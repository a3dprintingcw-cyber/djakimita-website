<?php
// Swap points for a reward. POST JSON {reward_id, user_id, dt}
require __DIR__ . '/_auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$id = api_customer((int) ($in['user_id'] ?? 0), (string) ($in['dt'] ?? ''));
try {
    $r = pts_redeem(api_db(), $id, (int) ($in['reward_id'] ?? 0));
    api_json($r['ok'] ? ['status' => 'success', 'code' => $r['code']] : ['status' => 'fail', 'message' => $r['why']]);
} catch (Throwable $e) {
    error_log('reward_redeem: ' . $e->getMessage());
    api_json(['status' => 'fail', 'message' => 'Something went wrong. Try again.']);
}
