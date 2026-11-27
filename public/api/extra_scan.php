<?php
// Buy extra visits at a partner with points. POST JSON {user_id, dt, company}
require __DIR__ . '/_auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$id = api_customer((int) ($in['user_id'] ?? 0), (string) ($in['dt'] ?? ''));
try {
    $r = pts_buy_extra_scan(api_db(), $id, (int) ($in['company'] ?? 0), (int) ($in['vid'] ?? 0));
    api_json($r['ok'] ? ['status' => 'success', 'left' => $r['left']] : ['status' => 'fail', 'message' => $r['why']]);
} catch (Throwable $e) {
    error_log('extra_scan: ' . $e->getMessage());
    api_json(['status' => 'fail', 'message' => 'Something went wrong. Try again.']);
}
