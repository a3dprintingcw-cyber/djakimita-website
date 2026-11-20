<?php
// Mollie calls this (POST id=tr_...) whenever a payment changes, even if the customer closed the page.
// Nothing to set up in Mollie: the webhookUrl is sent with every payment.
// We never trust the call itself: fulfill_payment_intent() asks Mollie for the real status.
require __DIR__ . '/../app/bootstrap.php';

$id = (string) ($_POST['id'] ?? '');
if (!preg_match('/^tr_[A-Za-z0-9]+$/', $id)) {
    http_response_code(400);
    exit('bad id');
}
try {
    fulfill_payment_intent($id);
} catch (Throwable $ex) {
    error_log('Mollie webhook failed: ' . $ex->getMessage());
    http_response_code(500); // Mollie retries later
    exit('retry');
}
http_response_code(200);
echo 'ok';
