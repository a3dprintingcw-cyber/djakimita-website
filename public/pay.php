<?php
require __DIR__ . '/../app/bootstrap.php';

$orderId = (int) ($_GET['o'] ?? 0);
$sig = (string) ($_GET['s'] ?? '');
if (!order_sig_ok($orderId, $sig) || !stripe_ready()) {
    header('Location: /checkout.php');
    exit;
}
$rows = order_rows($orderId);
if (!$rows) {
    header('Location: /checkout.php');
    exit;
}
$doneUrl = rtrim((string) config('base_url'), '/') . '/done.php?' . http_build_query(['o' => $orderId, 's' => $sig]);
if ((int) $rows[0]['status'] === 1) {
    header('Location: ' . $doneUrl);
    exit;
}
try {
    $intent = stripe('GET', 'payment_intents/' . rawurlencode($rows[0]['token']));
} catch (Throwable $ex) {
    header('Location: /checkout.php');
    exit;
}
$amount = money($intent['amount'] / 100, $intent['currency']);

page_header(t('pay.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap pay-wrap">
    <a class="back" href="/checkout.php"><?= icon('back', 18) ?> <?= e(t('pay.change')) ?></a>
    <h1 class="co-h1"><?= e(t('pay.title')) ?></h1>
    <p class="muted"><?= e(t('pay.order', ['n' => $orderId])) ?> · <strong><?= e($amount) ?></strong></p>

    <form id="pay-form" class="box">
      <div id="payment-element"></div>
      <p id="pay-error" class="alert" role="alert" hidden></p>
      <button id="pay-btn" class="btn btn-lg btn-block" type="submit"><?= e(t('pay.button', ['amount' => $amount])) ?></button>
    </form>
    <p class="trust"><?= icon('check', 16) ?> <?= e(t('co.secure')) ?></p>
  </div>
</section>
<script src="https://js.stripe.com/v3/"></script>
<script>
(function () {
  var stripe = Stripe(<?= json_encode((string) config('stripe_publishable_key')) ?>, {locale: <?= json_encode(current_lang() === 'nl' ? 'nl' : 'en') ?>});
  var elements = stripe.elements({
    clientSecret: <?= json_encode($intent['client_secret']) ?>,
    appearance: {theme: 'stripe', variables: {colorPrimary: '#187c19', borderRadius: '12px', fontFamily: 'DM Sans, system-ui, sans-serif'}},
    fonts: [400, 500, 700].map(function (w) {
      return {family: 'DM Sans', weight: String(w), src: 'url(' + location.origin + '/assets/fonts/dm-sans-latin-' + w + '-normal.woff2)'};
    })
  });
  elements.create('payment', {layout: 'tabs'}).mount('#payment-element');
  var form = document.getElementById('pay-form'), btn = document.getElementById('pay-btn'), err = document.getElementById('pay-error');
  var label = btn.textContent;
  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    btn.disabled = true; btn.textContent = <?= json_encode(t('pay.processing')) ?>; err.hidden = true;
    var result = await stripe.confirmPayment({elements: elements, confirmParams: {return_url: <?= json_encode($doneUrl) ?>}});
    if (result.error) {
      err.textContent = result.error.message; err.hidden = false;
      btn.disabled = false; btn.textContent = label;
    }
  });
})();
</script>
<?php page_footer();
