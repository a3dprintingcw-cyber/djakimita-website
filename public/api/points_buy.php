<?php
// Buy points with money from the app. POST JSON {user_id, dt, pack, country} -> {status, url}
// The app opens the url (Sentoo for XCG, Mollie for USD/EUR). Points are added when the payment is confirmed.
require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/devices.php';
require_once __DIR__ . '/../../app/points.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}
$out = function (array $d, int $code = 200) { http_response_code($code); echo json_encode($d); exit; };
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$db = db();
$cid = (int) ($in['user_id'] ?? 0);
if (!dev_check($db, $cid, (string) ($in['dt'] ?? ''))) {
    $out(['status' => 'out'], 401);
}
if (!pts_on($db) || pts_setting($db, 'pts_buy_on', '1') !== '1') {
    $out(['status' => 'fail', 'message' => 'Buying points is not open yet.']);
}
$packs = pts_packs($db);
$pack = $packs[(int) ($in['pack'] ?? -1)] ?? null;
if (!$pack) {
    $out(['status' => 'fail', 'message' => 'This pack is not available.']);
}
$country = (int) ($in['country'] ?? 1) === 2 ? 2 : 1;
$cur = $country === 2 ? (use_mollie() ? 'eur' : '') : (sentoo_ready() ? 'xcg' : (use_mollie() ? 'usd' : ''));
$amount = (float) ($pack[$cur] ?? 0);
if ($cur === '' || $amount <= 0) {
    $out(['status' => 'fail', 'message' => 'Buying points is not open yet.']);
}
// Not too many open orders (no spam)
$st = $db->prepare('SELECT COUNT(*) FROM points_orders WHERE client_id = :c AND status = 0 AND created_at > NOW() - INTERVAL 1 HOUR');
$st->execute(['c' => $cid]);
if ((int) $st->fetchColumn() >= 10) {
    $out(['status' => 'fail', 'message' => 'Too many tries. Try again later.']);
}
$st = $db->prepare('SELECT email FROM clients WHERE id = :c');
$st->execute(['c' => $cid]);
$email = (string) $st->fetchColumn();
try {
    $db->prepare('INSERT INTO points_orders (client_id, points, amount, currency, created_at) VALUES (:c, :p, :a, :cur, NOW())')
        ->execute(['c' => $cid, 'p' => (int) $pack['points'], 'a' => $amount, 'cur' => $cur]);
    $oid = (int) $db->lastInsertId();
    $url = points_create_payment($oid, $amount, $cur, $email);
    $out(['status' => 'success', 'url' => $url]);
} catch (Throwable $e) {
    error_log('points_buy: ' . $e->getMessage());
    $out(['status' => 'fail', 'message' => 'Payment is not available right now. Try again later.']);
}
