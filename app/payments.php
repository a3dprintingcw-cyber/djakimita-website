<?php
// Orders, Stripe payments, card activation and email.
// Writes to the same tables the app and the dashboard already use:
//   clients, sale_receits, clients_package, client_package_customers, esim

const ESIM_PACKAGE = 999; // package id the old site used for eSIM orders

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''));
}

// Signed order reference, so nobody can look at someone else's order by changing a number.
function order_sig(int $orderId): string
{
    $key = (string) config('stripe_secret_key') . '|' . (string) config('db_pass') . '|djk-order';
    return substr(hash_hmac('sha256', (string) $orderId, $key), 0, 24);
}

function order_sig_ok(int $orderId, string $sig): bool
{
    return $orderId > 0 && hash_equals(order_sig($orderId), $sig);
}

// ---------------------------------------------------------------------------
// Stripe (plain HTTPS calls, no SDK needed)
// ---------------------------------------------------------------------------
function stripe_ready(): bool
{
    return (string) config('stripe_secret_key') !== '' && (string) config('stripe_publishable_key') !== '';
}

function stripe(string $method, string $path, array $params = []): array
{
    $ch = curl_init(rtrim((string) config('stripe_api_base', 'https://api.stripe.com/v1'), '/') . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => config('stripe_secret_key') . ':',
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string) $body, true) ?: [];
    if ($code >= 400 || $err) {
        error_log('Stripe error ' . $code . ': ' . ($data['error']['message'] ?? $err));
        throw new RuntimeException($data['error']['message'] ?? 'Payment service unavailable');
    }
    return $data;
}

/** Verify a webhook signature (Stripe-Signature header). */
function stripe_verify_webhook(string $payload, string $header, int $tolerance = 300): bool
{
    $secret = (string) config('stripe_webhook_secret');
    if ($secret === '' || $header === '') {
        return false;
    }
    $ts = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') {
            $ts = (int) $v;
        } elseif ($k === 'v1') {
            $sigs[] = $v;
        }
    }
    if (!$ts || !$sigs || abs(time() - $ts) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Customers
// ---------------------------------------------------------------------------

/** Find the customer account by email, or create one. Returns [clientId, isNew]. */
function find_or_create_client(string $email, string $firstName, string $lastName, string $phone = ''): array
{
    $st = db()->prepare('SELECT id FROM clients WHERE LOWER(email) = LOWER(:e) LIMIT 1');
    $st->execute(['e' => $email]);
    $id = $st->fetchColumn();
    if ($id) {
        return [(int) $id, false];
    }
    // Random password: the customer sets their own via the email link.
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $ins = db()->prepare('INSERT INTO clients (username, lastName, phone, sex, email, password, type, active)
                          VALUES (:u, :l, :p, "", :e, :pw, 0, 1)');
    $ins->execute(['u' => $firstName, 'l' => $lastName, 'p' => $phone, 'e' => $email, 'pw' => $hash]);
    return [(int) db()->lastInsertId(), true];
}

function create_password_token(int $clientId, int $hours = 72): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM password_resets WHERE client_id = :c')->execute(['c' => $clientId]);
    db()->prepare('INSERT INTO password_resets (client_id, token, expires_at, created_at) VALUES (:c, :t, :x, NOW())')
        ->execute(['c' => $clientId, 't' => hash('sha256', $token), 'x' => date('Y-m-d H:i:s', time() + $hours * 3600)]);
    return $token;
}

// ---------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------

/**
 * Creates the order rows (status 0 = unpaid) and a Stripe PaymentIntent.
 * $card: kids|teen|adult|family|senior or '' (eSIM only). Returns the main order id.
 */
function create_order(array $in): int
{
    $currency = $in['currency'] === 'usd' ? 'usd' : 'eur';
    $items = [];
    if ($in['card'] !== '') {
        $items[] = ['package' => CARD_TYPES[$in['card']]['package'], 'amount' => card_price($in['card'], $currency)];
    }
    if ($in['esim']) {
        $items[] = ['package' => ESIM_PACKAGE, 'amount' => prices()[ESIM_PRODUCT][$currency] ?? 0];
    }
    $total = array_sum(array_column($items, 'amount'));
    if (!$items || $total <= 0) {
        throw new RuntimeException('Nothing to pay');
    }

    $pdo = db();
    $pdo->beginTransaction();
    [$clientId, $isNew] = find_or_create_client($in['email'], $in['first'], $in['last'], $in['phone']);

    $ids = [];
    $ins = $pdo->prepare('INSERT INTO sale_receits (email, package, dia_orario, status, user_id, currency, email_status, token, client_package_id)
                          VALUES (:e, :p, NOW(), 0, :u, :c, 0, "", 0)');
    foreach ($items as $it) {
        $ins->execute(['e' => $in['email'], 'p' => $it['package'], 'u' => $clientId, 'c' => $currency]);
        $ids[] = (int) $pdo->lastInsertId();
    }
    $mainId = $ids[0];

    // Card holders: name + birthdate (the shop checks these against an ID). No passport numbers.
    if ($in['card'] !== '') {
        $h = $pdo->prepare('INSERT INTO client_package_customers (name, client_id, client_birthdate, order_id) VALUES (:n, "", :b, :o)');
        foreach ($in['holders'] as $holder) {
            $h->execute(['n' => $holder['name'], 'b' => $holder['birth'], 'o' => $mainId]);
        }
    }
    if ($in['esim']) {
        $pdo->prepare('INSERT INTO esim (email, phone, user_id, confirmed) VALUES (:e, :p, :u, 0)')
            ->execute(['e' => $in['email'], 'p' => $in['phone'], 'u' => $clientId]);
    }

    $intent = stripe('POST', 'payment_intents', [
        'amount' => (int) round($total * 100),
        'currency' => $currency,
        'automatic_payment_methods[enabled]' => 'true',
        'receipt_email' => $in['email'],
        'description' => "Dj'aki mi ta! order #" . $mainId,
        'metadata[order_ids]' => implode(',', $ids),
        'metadata[client_id]' => $clientId,
    ]);
    $up = $pdo->prepare('UPDATE sale_receits SET token = :t WHERE id = :id');
    foreach ($ids as $id) {
        $up->execute(['t' => $intent['id'], 'id' => $id]);
    }
    $pdo->commit();

    $_SESSION['new_account'][$mainId] = $isNew;
    return $mainId;
}

function order_rows(int $mainId): array
{
    $st = db()->prepare('SELECT token FROM sale_receits WHERE id = :id');
    $st->execute(['id' => $mainId]);
    $token = $st->fetchColumn();
    if (!$token) {
        return [];
    }
    $all = db()->prepare('SELECT * FROM sale_receits WHERE token = :t ORDER BY id');
    $all->execute(['t' => $token]);
    return $all->fetchAll();
}

/**
 * Activates everything once the payment succeeded. Safe to call many times
 * (from the webhook and from the return page): it only acts on unpaid rows.
 */
function fulfill_payment_intent(string $intentId): bool
{
    $intent = stripe('GET', 'payment_intents/' . rawurlencode($intentId));
    if (($intent['status'] ?? '') !== 'succeeded') {
        return false;
    }
    $pdo = db();
    $pdo->beginTransaction();
    $rows = $pdo->prepare('SELECT * FROM sale_receits WHERE token = :t AND status = 0 FOR UPDATE');
    $rows->execute(['t' => $intentId]);
    $rows = $rows->fetchAll();
    $activated = [];
    foreach ($rows as $r) {
        $clientPackageId = 0;
        if ((int) $r['package'] === ESIM_PACKAGE) {
            $pdo->prepare('UPDATE esim SET confirmed = 1 WHERE user_id = :u AND confirmed = 0')->execute(['u' => $r['user_id']]);
        } else {
            $months = 2;
            foreach (CARD_TYPES as $ids) {
                if ($ids['package'] === (int) $r['package']) {
                    $months = prices()[$ids['product']]['months'] ?? 2;
                }
            }
            $pdo->prepare('INSERT INTO clients_package (users_id, package_id, expire) VALUES (:u, :p, DATE_ADD(CURDATE(), INTERVAL :m MONTH))')
                ->execute(['u' => $r['user_id'], 'p' => $r['package'], 'm' => $months]);
            $clientPackageId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE sale_receits SET status = 1, dia_orario = NOW(), client_package_id = :cp WHERE id = :id')
            ->execute(['cp' => $clientPackageId, 'id' => $r['id']]);
        $activated[] = $r;
    }
    $pdo->commit();

    if ($activated) {
        send_order_email($activated);
    }
    return true;
}

// ---------------------------------------------------------------------------
// Email (Brevo)
// ---------------------------------------------------------------------------
function brevo_send(string $to, string $subject, string $html): bool
{
    $key = (string) config('brevo_api_key');
    if ($key === '') {
        error_log('Email not sent (no Brevo key): ' . $subject);
        return false;
    }
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['api-key: ' . $key, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'sender' => ['email' => config('mail_from'), 'name' => config('mail_from_name')],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $html,
        ]),
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300) {
        error_log('Brevo error ' . $code . ' for ' . $subject);
    }
    return $code < 300;
}

function send_order_email(array $rows): void
{
    $first = $rows[0];
    $email = (string) $first['email'];
    $base = rtrim((string) config('base_url'), '/');
    $lines = '';
    foreach ($rows as $r) {
        $name = (int) $r['package'] === ESIM_PACKAGE ? 'Flow eSIM' : (package_label((int) $r['package']) . ' card');
        $lines .= '<li>' . e($name) . '</li>';
    }
    $setPw = '';
    $st = db()->prepare('SELECT COUNT(*) FROM sale_receits WHERE user_id = :u AND status = 1');
    $st->execute(['u' => $first['user_id']]);
    if ((int) $st->fetchColumn() <= count($rows)) {
        // First purchase: let them choose a password for the app.
        $token = create_password_token((int) $first['user_id']);
        $setPw = '<p><a href="' . e($base . '/set-password.php?t=' . $token) . '" style="display:inline-block;background:#187c19;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:bold">Choose your password</a></p>'
            . '<p style="color:#4f5d52;font-size:14px">Then log in to the Dj\'aki mi ta! app with ' . e($email) . ' to see your card.</p>';
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#17221a">'
        . '<div style="background:#187c19;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">Dj\'aki mi ta!</div>'
        . '<div style="border:1px solid #dfe7dc;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">Bon bini! Your order is confirmed</h1>'
        . '<p>Order #' . (int) $first['id'] . '</p><ul>' . $lines . '</ul>' . $setPw
        . '<p>Show your QR code in the app at any partner to get your discount.</p>'
        . '<p style="color:#4f5d52;font-size:13px">Questions? contact@djakimita.com</p></div></div>';
    if (brevo_send($email, "Your Dj'aki mi ta! order #" . (int) $first['id'], $html)) {
        db()->prepare('UPDATE sale_receits SET email_status = 1 WHERE token = :t')->execute(['t' => $first['token']]);
    }
}
