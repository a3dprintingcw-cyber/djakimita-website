<?php
require __DIR__ . '/../app/bootstrap.php';

$orderId = (int) ($_GET['o'] ?? 0);
$sig = (string) ($_GET['s'] ?? '');
if (!order_sig_ok($orderId, $sig)) {
    header('Location: /');
    exit;
}
$rows = order_rows($orderId);
if (!$rows) {
    header('Location: /');
    exit;
}

// Activate right away if Stripe already confirms (the webhook does the same; whichever is first wins).
$state = 'pending';
if ((int) $rows[0]['status'] === 1) {
    $state = 'paid';
} elseif (stripe_ready()) {
    try {
        $intent = stripe('GET', 'payment_intents/' . rawurlencode($rows[0]['token']));
        if ($intent['status'] === 'succeeded') {
            fulfill_payment_intent($rows[0]['token']);
            $state = 'paid';
        } elseif (in_array($intent['status'], ['requires_payment_method', 'canceled'], true)) {
            $state = 'failed';
        }
    } catch (Throwable $ex) {
        error_log('done.php: ' . $ex->getMessage());
    }
}
$email = (string) $rows[0]['email'];

$pageTitle = ['paid' => t('done.title'), 'failed' => t('done.failed'), 'pending' => t('pay.processing')][$state];
page_header($pageTitle . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap narrow done">
    <?php if ($state === 'paid'): ?>
      <div class="done-icon ok"><?= icon('check', 44) ?></div>
      <h1><?= e(t('done.title')) ?></h1>
      <p class="lead"><?= e(t('done.text', ['email' => $email])) ?></p>
      <?php if (order_holders($orderId)): ?>
        <div class="box box-verify">
          <h2 class="h3"><?= icon('shield', 22) ?> <?= e(t('done.verify')) ?></h2>
          <p><?= e(t('done.verify.t')) ?></p>
          <a class="btn" href="/verify.php?<?= e(http_build_query(['o' => $orderId, 's' => $sig])) ?>"><?= e(t('v.title')) ?> <?= icon('arrow', 18) ?></a>
        </div>
      <?php endif; ?>
      <div class="box">
        <h2 class="h3"><?= e(t('done.next')) ?></h2>
        <ol class="done-steps">
          <li><?= e(t('done.n1')) ?>
            <span class="row-actions">
              <a class="btn btn-sm btn-outline" href="https://apps.apple.com/us/app/deals-with-discounts/id6753038072" rel="noopener">App Store</a>
              <a class="btn btn-sm btn-outline" href="https://play.google.com/store/apps/details?id=com.dealswithdiscout.app" rel="noopener">Google Play</a>
            </span>
          </li>
          <li><?= e(t('done.n2', ['email' => $email])) ?></li>
          <li><?= e(t('done.n3')) ?></li>
        </ol>
      </div>
      <a class="btn btn-lg" href="/partners.php"><?= e(t('done.partners')) ?> <?= icon('arrow', 18) ?></a>
    <?php elseif ($state === 'failed'): ?>
      <div class="done-icon bad">!</div>
      <h1><?= e(t('done.failed')) ?></h1>
      <a class="btn btn-lg" href="/pay.php?<?= e(http_build_query(['o' => $orderId, 's' => $sig])) ?>"><?= e(t('done.retry')) ?></a>
    <?php else: ?>
      <div class="done-icon wait"><?= icon('clock', 44) ?></div>
      <h1><?= e(t('pay.processing')) ?></h1>
      <p class="lead"><?= e(t('done.pending')) ?></p>
      <script>setTimeout(function () { location.reload(); }, 4000);</script>
    <?php endif; ?>
  </div>
</section>
<?php page_footer();
