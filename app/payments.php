<?php
// Orders, payments (Mollie or Stripe), card activation and email.
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
    // Own secret, so changing the Stripe or database password never breaks links already emailed.
    $key = (string) config('app_secret') !== '' ? (string) config('app_secret')
        : (string) config('stripe_secret_key') . '|' . (string) config('db_pass') . '|djk-order';
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
// Mollie (used when mollie_api_key is set; otherwise Stripe)
// ---------------------------------------------------------------------------
function use_mollie(): bool
{
    return (string) config('mollie_api_key') !== '';
}

/** True when a payment provider is configured, so the checkout can take money. */
function payments_ready(): bool
{
    return use_mollie() || stripe_ready();
}

function mollie(string $method, string $path, array $params = []): array
{
    $ch = curl_init(rtrim((string) config('mollie_api_base', 'https://api.mollie.com/v2'), '/') . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . config('mollie_api_key'), 'Content-Type: application/json'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($params);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string) $body, true) ?: [];
    if ($code >= 400 || $err) {
        error_log('Mollie error ' . $code . ': ' . ($data['detail'] ?? $err));
        throw new RuntimeException($data['detail'] ?? 'Payment service unavailable');
    }
    return $data;
}

/**
 * Payment status for an order token, the same for both providers:
 * 'paid', 'failed', or 'open' (still waiting). Also returns amount/currency and,
 * for Mollie, the hosted checkout link.
 */
function payment_status(string $token): array
{
    if (str_starts_with($token, 'sentoo:')) {
        return ['state' => sentoo_state(substr($token, 7)), 'amount' => 0.0, 'currency' => 'xcg', 'checkout' => ''];
    }
    if (str_starts_with($token, 'tr_')) {
        $p = mollie('GET', 'payments/' . rawurlencode($token));
        $st = (string) ($p['status'] ?? '');
        return [
            'state' => $st === 'paid' ? 'paid' : (in_array($st, ['failed', 'canceled', 'expired'], true) ? 'failed' : 'open'),
            'amount' => (float) ($p['amount']['value'] ?? 0),
            'currency' => strtolower((string) ($p['amount']['currency'] ?? 'eur')),
            'checkout' => (string) ($p['_links']['checkout']['href'] ?? ''),
        ];
    }
    $i = stripe('GET', 'payment_intents/' . rawurlencode($token));
    $st = (string) ($i['status'] ?? '');
    return [
        'state' => $st === 'succeeded' ? 'paid' : (in_array($st, ['requires_payment_method', 'canceled'], true) ? 'failed' : 'open'),
        'amount' => ($i['amount'] ?? 0) / 100,
        'currency' => (string) ($i['currency'] ?? 'eur'),
        'checkout' => '',
        'client_secret' => (string) ($i['client_secret'] ?? ''),
    ];
}

/** Give a failed/expired Mollie order a fresh payment so the customer can try again. */
function mollie_new_payment(int $mainId): string
{
    $rows = order_rows($mainId);
    if (!$rows) {
        throw new RuntimeException('Order not found');
    }
    $old = payment_status((string) $rows[0]['token']);
    $payment = mollie_create_payment($mainId, $old['amount'], $old['currency'], (string) $rows[0]['email']);
    db()->prepare('UPDATE sale_receits SET token = :n WHERE token = :o AND status = 0')
        ->execute(['n' => $payment['id'], 'o' => $rows[0]['token']]);
    return (string) $payment['_links']['checkout']['href'];
}

function mollie_create_payment(int $mainId, float $total, string $currency, string $email): array
{
    $base = rtrim((string) config('base_url'), '/');
    $q = http_build_query(['o' => $mainId, 's' => order_sig($mainId)]);
    $lang = function_exists('current_lang') ? current_lang() : 'en';
    return mollie('POST', 'payments', [
        'amount' => ['currency' => strtoupper($currency), 'value' => number_format($total, 2, '.', '')],
        'description' => "Dj'aki mi ta! order #" . $mainId,
        'redirectUrl' => $base . '/done.php?' . $q,
        'cancelUrl' => $base . '/checkout.php',
        'webhookUrl' => $base . '/mollie-webhook.php',
        'locale' => $lang === 'nl' ? 'nl_NL' : 'en_US',
        'metadata' => ['order_id' => $mainId, 'email' => $email],
    ]);
}

// ---------------------------------------------------------------------------
// Sentoo (local bank payments in guilders). Used when the customer pays in XCG.
// Order tokens look like "sentoo:<transaction id>".
// ---------------------------------------------------------------------------
function sentoo_ready(): bool
{
    return (string) config('sentoo_merchant_id') !== '' && (string) config('sentoo_secret') !== '';
}

function sentoo(string $method, string $path, array $params = []): array
{
    $base = rtrim((string) config('sentoo_api_base', 'https://api.sentoo.io/v1'), '/');
    $ch = curl_init($base . '/' . ltrim($path, '/'));
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['X-SENTOO-SECRET: ' . config('sentoo_secret'), 'Accept: application/json'],
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
    if ($code >= 400 || $err || isset($data['error'])) {
        $msg = is_array($data['error'] ?? null) ? ($data['error']['message'] ?? '') : (string) ($data['error'] ?? '');
        error_log('Sentoo error ' . $code . ': ' . ($msg ?: $err ?: substr((string) $body, 0, 200)));
        throw new RuntimeException('Payment service unavailable');
    }
    return $data;
}

/** Starts a Sentoo payment. Returns [transaction id, payment page url]. */
function sentoo_create_payment(int $mainId, float $total, string $email): array
{
    $base = rtrim((string) config('base_url'), '/');
    $res = sentoo('POST', 'payment/new', [
        'sentoo_merchant' => config('sentoo_merchant_id'),
        'sentoo_amount' => (int) round($total * 100),
        'sentoo_currency' => (string) config('sentoo_currency', 'XCG'),
        'sentoo_description' => "Dj'aki mi ta! order #" . $mainId,
        'sentoo_return_url' => $base . '/done.php?' . http_build_query(['o' => $mainId, 's' => order_sig($mainId)]),
        'sentoo_customer' => $email,
        'sentoo_expires' => date('c', time() + 2 * 3600),
    ]);
    $ok = $res['success'] ?? [];
    $id = (string) ($ok['message'] ?? ($ok['data']['id'] ?? ($ok['data']['transaction_id'] ?? '')));
    $url = (string) ($ok['data']['url'] ?? '');
    if ($id === '' || $url === '') {
        error_log('Sentoo: unexpected answer ' . substr(json_encode($res), 0, 300));
        throw new RuntimeException('Payment service unavailable');
    }
    return [$id, $url];
}

/** 'paid', 'failed' or 'open' for a Sentoo transaction. */
function sentoo_state(string $id): string
{
    $res = sentoo('GET', 'payment/status/' . rawurlencode((string) config('sentoo_merchant_id')) . '/' . rawurlencode($id));
    $ok = $res['success'] ?? [];
    $st = strtolower((string) ($ok['data']['status'] ?? ($ok['message'] ?? '')));
    if ($st === 'success') {
        return 'paid';
    }
    return in_array($st, ['failed', 'expired', 'cancelled', 'canceled', 'rejected'], true) ? 'failed' : 'open';
}

/** Give a failed/expired Sentoo order a fresh payment so the customer can try again. Returns the payment page. */
function sentoo_new_payment(int $mainId): string
{
    $rows = order_rows($mainId);
    if (!$rows) {
        throw new RuntimeException('Order not found');
    }
    $total = 0.0;
    foreach ($rows as $r) {
        $total += (float) ($_SESSION['sentoo_amount'][$mainId] ?? 0);
        break;
    }
    if ($total <= 0) {
        throw new RuntimeException('Unknown amount');
    }
    [$id, $url] = sentoo_create_payment($mainId, $total, (string) $rows[0]['email']);
    db()->prepare('UPDATE sale_receits SET token = :n WHERE token = :o AND status = 0')
        ->execute(['n' => 'sentoo:' . $id, 'o' => $rows[0]['token']]);
    $_SESSION['sentoo_url'][$mainId] = $url;
    return $url;
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
    $currency = in_array($in['currency'], ['usd', 'xcg'], true) ? $in['currency'] : 'eur';
    $items = [];
    $length = null;
    if ($in['card'] !== '') {
        $length = card_length($in['card'], $in['length'] ?? null);
        if (!$length) {
            throw new RuntimeException('Unknown card length');
        }
        $items[] = ['package' => CARD_TYPES[$in['card']]['package'], 'amount' => (float) $length[$currency], 'length_id' => $length['id']];
    }
    // Discount code: a percentage off the card (never the eSIM)
    $discount = null;
    if ($in['card'] !== '' && !empty($in['discount'])) {
        $discount = $in['discount'];
        $off = round($items[0]['amount'] * min(90, (float) $discount['percent']) / 100, 2);
        $items[0]['amount'] = round($items[0]['amount'] - $off, 2);
        $discount['amount'] = $off;
    }
    if ($in['esim']) {
        $items[] = ['package' => ESIM_PACKAGE, 'amount' => (float) (prices()[ESIM_PRODUCT][$currency] ?? 0)];
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
        $saleId = (int) $pdo->lastInsertId();
        $ids[] = $saleId;
        if (!empty($it['length_id'])) {
            $pdo->prepare('INSERT INTO sale_lengths (sale_id, length_id) VALUES (:s, :l)')->execute(['s' => $saleId, 'l' => $it['length_id']]);
        }
    }
    $mainId = $ids[0];
    if ($discount) {
        $pdo->prepare('INSERT INTO sale_discounts (sale_id, code_id, code, percent, amount, counted) VALUES (:s, :c, :code, :p, :a, 0)')
            ->execute(['s' => $mainId, 'c' => $discount['id'], 'code' => $discount['code'], 'p' => $discount['percent'], 'a' => $discount['amount']]);
    }

    // Invite a friend: remember whose invite code was used (points come after paying, see app/points.php)
    if ($in['card'] !== '' && !empty($in['inviter'])) {
        $pdo->prepare('INSERT IGNORE INTO sale_invites (sale_id, inviter_id) VALUES (:s, :i)')->execute(['s' => $mainId, 'i' => (int) $in['inviter']]);
    }

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

    if ($currency === 'xcg') {
        [$sid, $surl] = sentoo_create_payment($mainId, $total, $in['email']);
        $token = 'sentoo:' . $sid;
        $_SESSION['sentoo_url'][$mainId] = $surl;
        $_SESSION['sentoo_amount'][$mainId] = $total;
    } elseif (use_mollie()) {
        $token = (string) mollie_create_payment($mainId, $total, $currency, $in['email'])['id'];
    } else {
        $token = (string) stripe('POST', 'payment_intents', [
            'amount' => (int) round($total * 100),
            'currency' => $currency,
            'automatic_payment_methods[enabled]' => 'true',
            'receipt_email' => $in['email'],
            'description' => "Dj'aki mi ta! order #" . $mainId,
            'metadata[order_ids]' => implode(',', $ids),
            'metadata[client_id]' => $clientId,
        ])['id'];
    }
    $up = $pdo->prepare('UPDATE sale_receits SET token = :t WHERE id = :id');
    foreach ($ids as $id) {
        $up->execute(['t' => $token, 'id' => $id]);
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
    // Works for Stripe (pi_...) and Mollie (tr_...): always asks the provider, never trusts the caller.
    if (payment_status($intentId)['state'] !== 'paid') {
        return false;
    }
    activate_order($intentId);
    return true;
}

/**
 * Turns the unpaid rows of an order into cards (and eSIMs) and emails the customer.
 * Only call this once the money is in: after the provider says paid, or when staff mark it paid.
 * Returns how many rows were activated (0 when it was already done).
 */
function activate_order(string $intentId): int
{
    $pdo = db();
    $pdo->beginTransaction();
    $rows = $pdo->prepare('SELECT * FROM sale_receits WHERE token = :t AND status = 0 FOR UPDATE');
    $rows->execute(['t' => $intentId]);
    $rows = $rows->fetchAll();
    $activated = [];
    // Pre-orders (paid before launch) start counting on launch day, so nobody loses days.
    $start = date('Y-m-d');
    if (function_exists('before_launch') && before_launch()) {
        $start = date('Y-m-d', launch_ts());
    }
    foreach ($rows as $r) {
        $clientPackageId = 0;
        if ((int) $r['package'] === ESIM_PACKAGE) {
            $pdo->prepare('UPDATE esim SET confirmed = 1 WHERE user_id = :u AND confirmed = 0')->execute(['u' => $r['user_id']]);
        } else {
            // Length bought (10 days to 1 year). Orders from before lengths existed: 1 year.
            $len = $pdo->prepare('SELECT l.days, l.months FROM sale_lengths s JOIN card_lengths l ON l.id = s.length_id WHERE s.sale_id = :s');
            $len->execute(['s' => $r['id']]);
            $len = $len->fetch() ?: ['days' => null, 'months' => 12];
            if (!empty($len['days'])) {
                $pdo->prepare('INSERT INTO clients_package (users_id, package_id, expire) VALUES (:u, :p, DATE_ADD(:start, INTERVAL :d DAY))')
                    ->execute(['u' => $r['user_id'], 'p' => $r['package'], 'd' => (int) $len['days'], 'start' => $start]);
            } else {
                $pdo->prepare('INSERT INTO clients_package (users_id, package_id, expire) VALUES (:u, :p, DATE_ADD(:start, INTERVAL :m MONTH))')
                    ->execute(['u' => $r['user_id'], 'p' => $r['package'], 'm' => max(1, (int) $len['months']), 'start' => $start]);
            }
            $clientPackageId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE sale_receits SET status = 1, dia_orario = NOW(), client_package_id = :cp WHERE id = :id')
            ->execute(['cp' => $clientPackageId, 'id' => $r['id']]);
        $activated[] = $r;
    }
    $pdo->commit();

    if ($activated) {
        discount_count_used((int) min(array_column($activated, 'id')));
        send_order_email($activated);
        try {
            notify_staff_order($activated);
        } catch (Throwable $e) {
            error_log('Staff notification failed: ' . $e->getMessage());
        }
    }
    return count($activated);
}

// ---------------------------------------------------------------------------
// Email (Brevo)
// ---------------------------------------------------------------------------
/** The Brevo key: pasted in the backend (Settings → Emails), else from config.local.php. */
function brevo_key(): string
{
    $k = function_exists('setting') ? trim((string) setting('brevo_api_key', '')) : '';
    return $k !== '' ? $k : (string) config('brevo_api_key');
}

function brevo_send(string $to, string $subject, string $html, ?array $replyTo = null): bool
{
    $key = brevo_key();
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
        CURLOPT_POSTFIELDS => json_encode(array_filter([
            'sender' => ['email' => config('mail_from'), 'name' => config('mail_from_name')],
            'to' => [['email' => $to]],
            'replyTo' => $replyTo,
            'subject' => $subject,
            'htmlContent' => $html,
        ])),
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300) {
        $msg = (string) (json_decode($body, true)['message'] ?? ('HTTP ' . $code));
        error_log('Brevo error ' . $code . ' for ' . $subject . ': ' . $msg);
        $GLOBALS['brevo_last_error'] = $msg;
    }
    return $code >= 200 && $code < 300;
}

function send_order_email(array $rows): void
{
    $first = $rows[0];
    $email = (string) $first['email'];
    $base = rtrim((string) config('base_url'), '/');
    $lines = '';
    foreach ($rows as $r) {
        $name = (int) $r['package'] === ESIM_PACKAGE ? 'Flow eSIM' : (package_label((int) $r['package']) . ' account');
        $lines .= '<li>' . e($name) . '</li>';
    }
    $setPw = '';
    $st = db()->prepare('SELECT COUNT(*) FROM sale_receits WHERE user_id = :u AND status = 1');
    $st->execute(['u' => $first['user_id']]);
    if ((int) $st->fetchColumn() <= count($rows)) {
        // First purchase: let them choose a password for the app.
        $token = create_password_token((int) $first['user_id']);
        $setPw = '<p><a href="' . e($base . '/set-password.php?t=' . $token) . '" style="display:inline-block;background:#187c19;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:bold">Choose your password</a></p>'
            . '<p style="color:#4f5d52;font-size:14px">Then log in to the Dj\'aki mi ta! app with ' . e($email) . ' to see your account.</p>';
    }
    $verify = '';
    foreach ($rows as $r) {
        if ((int) $r['package'] !== ESIM_PACKAGE && order_holders((int) $r['id'])) {
            $verify = '<div style="background:#fbf3dc;border-radius:10px;padding:16px;margin:16px 0">'
                . '<p style="margin:0 0 8px;font-weight:bold">One more step: verify your account (2 minutes)</p>'
                . '<p style="margin:0 0 12px;color:#4f5d52;font-size:14px">One selfie and one passport photo, one time only. Then you just scan at the shop, no passport needed.</p>'
                . '<a href="' . e(verify_link((int) $r['id'])) . '" style="display:inline-block;background:#187c19;color:#fff;padding:10px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Verify my account</a></div>';
        }
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#17221a">'
        . '<div style="background:#187c19;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">Dj\'aki mi ta!</div>'
        . '<div style="border:1px solid #dfe7dc;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">Bon bini! Your order is confirmed</h1>'
        . '<p>Order #' . (int) $first['id'] . '</p><ul>' . $lines . '</ul>' . $verify . $setPw
        . '<p>Show your QR code in the app at any partner to get your discount.</p>'
        . '<p style="color:#4f5d52;font-size:13px">Questions? contact@djakimita.com</p></div></div>';
    if (brevo_send($email, "Your Dj'aki mi ta! order #" . (int) $first['id'], $html)) {
        db()->prepare('UPDATE sale_receits SET email_status = 1 WHERE token = :t')->execute(['t' => $first['token']]);
    }
}
