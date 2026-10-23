<?php
// Stripe calls this when a payment succeeds, even if the customer closed the page.
// In Stripe: Developers > Webhooks > Add endpoint: https://djakimita.com/stripe-webhook.php
// Event: payment_intent.succeeded. Put the signing secret in config.local.php.
require __DIR__ . '/../app/bootstrap.php';

$payload = (string) file_get_contents('php://input');
if (!stripe_verify_webhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''))) {
    http_response_code(400);
    exit('bad signature');
}
$event = json_decode($payload, true);
if (($event['type'] ?? '') === 'payment_intent.succeeded') {
    $intentId = (string) ($event['data']['object']['id'] ?? '');
    try {
        if ($intentId !== '') {
            fulfill_payment_intent($intentId);
        }
    } catch (Throwable $ex) {
        error_log('Webhook fulfill failed: ' . $ex->getMessage());
        http_response_code(500); // Stripe retries later
        exit('retry');
    }
}
http_response_code(200);
echo 'ok';
