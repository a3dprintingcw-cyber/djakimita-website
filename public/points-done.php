<?php
// The payment page sends the customer here after buying points in the app.
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/points.php';
$o = (int) ($_GET['o'] ?? 0);
$ok = false;
$state = 'open';
if ($o > 0 && hash_equals(points_order_sig($o), (string) ($_GET['s'] ?? ''))) {
    $st = db()->prepare('SELECT * FROM points_orders WHERE id = :i');
    $st->execute(['i' => $o]);
    $row = $st->fetch();
    if ($row) {
        if ((int) $row['status'] === 1) {
            $state = 'paid';
        } elseif ($row['token'] !== '') {
            try {
                $state = payment_status((string) $row['token'])['state'];
                if ($state === 'paid') {
                    pts_order_paid(db(), (string) $row['token']);
                }
            } catch (Throwable $e) {
                $state = 'open';
            }
        }
        $ok = true;
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Points · Dj'aki mi ta!</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f1f6ee;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px}
.c{background:#fff;border-radius:24px;padding:32px 26px;max-width:380px;text-align:center;box-shadow:0 6px 24px rgba(0,0,0,.08)}h1{color:#0d3b12;margin:.3em 0}p{color:#44524a;line-height:1.5}
a{display:inline-block;margin-top:14px;background:#2f8f3a;color:#fff;text-decoration:none;padding:14px 26px;border-radius:999px;font-weight:700}</style></head>
<body><div class="c">
<?php if (!$ok): ?><h1>Hmm</h1><p>We could not find this order.</p>
<?php elseif ($state === 'paid'): ?><div style="font-size:54px">⭐</div><h1>Points added!</h1><p>Your points are in your account. Go back to the app to use them.</p>
<?php elseif ($state === 'failed'): ?><h1>Payment not completed</h1><p>No money was taken and no points were added. You can try again in the app.</p>
<?php else: ?><h1>Almost there</h1><p>We are waiting for the payment to be confirmed. Your points arrive in the app within a few minutes.</p><?php endif; ?>
<a href="/app/djakimita/">Back to the app</a>
</div></body></html>
