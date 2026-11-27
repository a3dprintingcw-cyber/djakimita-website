<?php
// The app's "one account, one phone" endpoint. POST JSON:
//   {action:"register", username, password, label}  -> {status:"success", client_id, token, limit, logged_out}
//   {action:"check", client_id, token}              -> {status:"ok"} or {status:"out", limit}
//   {action:"logout", client_id, token}             -> {status:"ok"}
require __DIR__ . '/_db.php';
require __DIR__ . '/../../app/devices.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$action = (string) ($in['action'] ?? '');
try {
    $db = api_db();
    if ($action === 'register') {
        $ipKey = hash('sha256', 'dev:' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE k = :k AND created_at > NOW() - INTERVAL 15 MINUTE');
        $st->execute(['k' => $ipKey]);
        if ((int) $st->fetchColumn() >= 20) {
            api_json(['status' => 'wait'], 429);
        }
        $user = trim((string) ($in['username'] ?? ''));
        $pass = (string) ($in['password'] ?? '');
        $st = $db->prepare('SELECT id, password, type FROM clients WHERE (LOWER(email) = LOWER(:u) OR (phone <> \'\' AND phone = :u2)) AND active = 1 ORDER BY id LIMIT 1');
        $st->execute(['u' => $user, 'u2' => $user]);
        $c = $st->fetch();
        if (!$c || $pass === '' || !password_verify($pass, (string) $c['password'])) {
            $db->prepare('INSERT INTO login_attempts (k, created_at) VALUES (:k, NOW())')->execute(['k' => $ipKey]);
            api_json(['status' => 'fail'], 401);
        }
        [$token, $out] = dev_register($db, (int) $c['id'], (string) ($in['label'] ?? ''));
        api_json(['status' => 'success', 'client_id' => (int) $c['id'], 'token' => $token,
                  'limit' => dev_limit($db, (int) $c['id']), 'logged_out' => $out]);
    }
    $cid = (int) ($in['client_id'] ?? 0);
    $tok = (string) ($in['token'] ?? '');
    if ($action === 'check') {
        if (dev_check($db, $cid, $tok)) {
            api_json(['status' => 'ok']);
        }
        api_json(['status' => 'out', 'limit' => $cid > 0 ? dev_limit($db, $cid) : 1]);
    }
    if ($action === 'logout') {
        dev_remove($db, $cid, $tok);
        api_json(['status' => 'ok']);
    }
    api_json(['status' => 'bad'], 400);
} catch (Throwable $e) {
    api_json(['status' => 'error'], 500);
}
