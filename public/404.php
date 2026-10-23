<?php
// "Page not found" + forwarding of old djakimita.com addresses (links in old emails, Google, bookmarks).
require __DIR__ . '/../app/bootstrap.php';

$path = strtolower(trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/'));
parse_str((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY), $q);

$plan = (string) ($q['plan'] ?? '');
$legacy = [
    'purchase.php' => '/checkout.php' . (isset(CARD_TYPES[$plan]) ? '?card=' . $plan : ''),
    'buy_voucher.php' => '/checkout.php',
    'difference_plan.php' => '/#cards',
    'registration.php' => '/#cards',
    'purchase_esim.php' => '/checkout.php?card=esim',
    'purchase_esim_payment.php' => '/checkout.php?card=esim',
    'purchase_stp2_payment.php' => '/checkout.php',
    'purchase_finished.php' => '/account.php',
    'thank-you.php' => '/account.php',
    'card_view.php' => '/account.php',
    'vouchers_view.php' => '/account.php',
    'account_side.php' => '/account.php',
    'logout.php' => '/account.php',
    'reset-password-confirm.php' => '/forgot-password.php',
    'partner-submit.php' => '/become-a-partner.php',
    'contact_send.php' => '/faq.php#contact',
    'index.html' => '/',
    'home' => '/',
    'partners' => '/partners.php',
    'faq' => '/faq.php',
];
if (isset($legacy[$path])) {
    header('Location: ' . $legacy[$path], true, 301);
    exit;
}

http_response_code(404);
page_header(t('nf.title') . " · Dj'aki mi ta!");
?>
<section class="section nf">
  <div class="wrap narrow">
    <p class="nf-code">404</p>
    <h1><?= e(t('nf.title')) ?></h1>
    <p class="lead"><?= e(t('nf.text')) ?></p>
    <div class="row-actions">
      <a class="btn btn-lg" href="/"><?= e(t('nf.home')) ?></a>
      <a class="btn btn-lg btn-outline" href="/partners.php"><?= e(t('nav.partners')) ?></a>
    </div>
  </div>
</section>
<?php page_footer();
