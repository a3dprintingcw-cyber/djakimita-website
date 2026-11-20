<?php
// Sentoo calls this ("Payment status URL" in the Sentoo portal) when a payment changes.
// We never trust the call itself: fulfill_payment_intent() asks Sentoo for the real status.
require __DIR__ . '/../app/bootstrap.php';

$raw = (string) file_get_contents('php://input');
$json = json_decode($raw, true);
$id = (string) ($_POST['transaction_id'] ?? $_POST['id'] ?? $_GET['transaction_id'] ?? $_GET['id']
    ?? (is_array($json) ? ($json['transaction_id'] ?? $json['id'] ?? $json['data']['id'] ?? '') : ''));
if (!preg_match('/^[A-Za-z0-9_-]{4,80}$/', $id)) {
    error_log('Sentoo webhook without a usable id: ' . substr($raw, 0, 200));
    http_response_code(400);
    exit('bad id');
}
try {
    fulfill_payment_intent('sentoo:' . $id);
} catch (Throwable $ex) {
    error_log('Sentoo webhook failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('retry');
}
http_response_code(200);
echo 'ok';
