<?php
// Claim the birthday gift from the birthday screen in the app. POST JSON {user_id, dt}
require __DIR__ . '/_auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}
$in = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
$id = api_customer((int) ($in['user_id'] ?? 0), (string) ($in['dt'] ?? ''));
try {
    $o = pts_birthday_claim(api_db(), $id);
    api_json($o ? ['status' => 'success', 'points' => (int) $o['points'], 'test' => (bool) $o['test']] : ['status' => 'fail']);
} catch (Throwable $e) {
    error_log('birthday_claim: ' . $e->getMessage());
    api_json(['status' => 'fail']);
}
