<?php
// Customer accounts on the website. Same accounts and passwords as the app (clients table).

function client_id(): int
{
    return (int) ($_SESSION['client_id'] ?? 0);
}

function current_client(): ?array
{
    static $c = false;
    if ($c === false) {
        $c = null;
        if (client_id()) {
            $st = db()->prepare('SELECT id, username, lastName, email, phone FROM clients WHERE id = :id AND active = 1');
            $st->execute(['id' => client_id()]);
            $c = $st->fetch() ?: null;
            if (!$c) {
                unset($_SESSION['client_id']);
            }
        }
    }
    return $c;
}

function require_login(): array
{
    $c = current_client();
    if (!$c) {
        header('Location: /login.php?' . http_build_query(['next' => $_SERVER['REQUEST_URI'] ?? '/account.php']));
        exit;
    }
    return $c;
}

/** Only allow redirects to our own pages. */
function safe_next(string $next): string
{
    return preg_match('#^/(?!/)[A-Za-z0-9_\-./?=&%]*$#', $next) ? $next : '/account.php';
}

// ---------------------------------------------------------------------------
// Login throttling: max 8 wrong tries per email and 30 per IP in 15 minutes
// ---------------------------------------------------------------------------
function attempt_keys(string $email): array
{
    return [
        'email' => hash('sha256', 'email:' . mb_strtolower(trim($email))),
        'ip' => hash('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? '')),
    ];
}

function login_blocked(string $email): bool
{
    $k = attempt_keys($email);
    $st = db()->prepare('SELECT k, COUNT(*) n FROM login_attempts WHERE k IN (:a, :b) AND created_at > NOW() - INTERVAL 15 MINUTE GROUP BY k');
    $st->execute(['a' => $k['email'], 'b' => $k['ip']]);
    foreach ($st->fetchAll() as $r) {
        if (($r['k'] === $k['email'] && $r['n'] >= 8) || ($r['k'] === $k['ip'] && $r['n'] >= 30)) {
            return true;
        }
    }
    return false;
}

function login_failed(string $email): void
{
    $ins = db()->prepare('INSERT INTO login_attempts (k, created_at) VALUES (:k, NOW())');
    foreach (attempt_keys($email) as $k) {
        $ins->execute(['k' => $k]);
    }
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    }
    usleep(400000);
}

/** Returns the client id, or 0. */
function client_login(string $email, string $password): int
{
    $st = db()->prepare('SELECT id, password FROM clients WHERE LOWER(email) = LOWER(:e) AND active = 1 LIMIT 1');
    $st->execute(['e' => trim($email)]);
    $row = $st->fetch();
    if ($row && password_verify($password, (string) $row['password'])) {
        if (password_needs_rehash((string) $row['password'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE clients SET password = :p WHERE id = :id')
                ->execute(['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => $row['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['client_id'] = (int) $row['id'];
        return (int) $row['id'];
    }
    login_failed($email);
    return 0;
}

function client_logout(): void
{
    unset($_SESSION['client_id']);
    session_regenerate_id(true);
}

// ---------------------------------------------------------------------------
// What the account page shows
// ---------------------------------------------------------------------------

/** Cards of a client, newest first, each with its holders + verification. */
function client_cards(int $clientId): array
{
    $st = db()->prepare('SELECT cp.id, cp.package_id, cp.expire, s.id AS order_id
                         FROM clients_package cp
                         LEFT JOIN sale_receits s ON s.client_package_id = cp.id AND s.status = 1
                         WHERE cp.users_id = :c ORDER BY cp.expire DESC, cp.id DESC');
    $st->execute(['c' => $clientId]);
    $cards = $st->fetchAll();
    foreach ($cards as &$card) {
        $card['active'] = $card['expire'] >= date('Y-m-d');
        $card['holders'] = $card['order_id'] ? order_holders((int) $card['order_id']) : [];
    }
    return $cards;
}

function client_esims(int $clientId): array
{
    $st = db()->prepare('SELECT phone, confirmed FROM esim WHERE user_id = :c ORDER BY id DESC');
    $st->execute(['c' => $clientId]);
    return $st->fetchAll();
}

function send_reset_email(int $clientId, string $email): void
{
    $token = create_password_token($clientId, 2);
    $link = rtrim((string) config('base_url'), '/') . '/set-password.php?t=' . $token;
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#17221a">'
        . '<div style="background:#187c19;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">Dj\'aki mi ta!</div>'
        . '<div style="border:1px solid #dfe7dc;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">Choose a new password</h1>'
        . '<p>Click the button to choose a new password. The link works for 2 hours.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#187c19;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:bold">New password</a></p>'
        . '<p style="color:#4f5d52;font-size:13px">Did not ask for this? Then you can ignore this email, your password stays the same.</p></div></div>';
    brevo_send($email, "Dj'aki mi ta!: choose a new password", $html);
}

/**
 * Account deletion. Removes personal data and photos; keeps the payment records
 * (required for bookkeeping) without a name attached.
 */
function delete_client_account(int $clientId): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT face_file, id_file FROM holder_verifications WHERE client_id = :c');
    $st->execute(['c' => $clientId]);
    foreach ($st->fetchAll() as $v) {
        delete_photo($v['face_file']);
        delete_photo($v['id_file']);
    }
    $anon = 'deleted-' . $clientId . '@deleted.invalid';
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM holder_verifications WHERE client_id = :c')->execute(['c' => $clientId]);
    $pdo->prepare('UPDATE client_package_customers h JOIN sale_receits s ON s.id = h.order_id
                   SET h.name = "", h.client_id = "", h.client_birthdate = "0000-00-00" WHERE s.user_id = :c')->execute(['c' => $clientId]);
    $pdo->prepare('UPDATE esim SET email = :e, phone = "" WHERE user_id = :c')->execute(['e' => $anon, 'c' => $clientId]);
    $pdo->prepare('UPDATE sale_receits SET email = :e WHERE user_id = :c')->execute(['e' => $anon, 'c' => $clientId]);
    $pdo->prepare('DELETE FROM password_resets WHERE client_id = :c')->execute(['c' => $clientId]);
    $pdo->prepare('UPDATE clients SET username = "", lastName = "", phone = "", sex = "", email = :e, password = :p, active = 0 WHERE id = :c')
        ->execute(['e' => $anon, 'p' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'c' => $clientId]);
    $pdo->commit();
}
