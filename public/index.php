<?php
require __DIR__ . '/../app/bootstrap.php';

$count = partner_count();
$best = best_discount();
$months = card_months();
$fromPrice = money(min(card_price('kids'), card_price('senior'), card_price('teen')));
$featured = array_values(array_filter(partners(null, '', 24), fn($p) => $p['image_id'] || $p['has_picture']));
$featured = array_slice($featured, 0, 8);

$cards = [
    ['key' => 'kids', 'popular' => false],
    ['key' => 'teen', 'popular' => false],
    ['key' => 'adult', 'popular' => false],
    ['key' => 'family', 'popular' => true],
    ['key' => 'senior', 'popular' => false],
];
$esimPrice = prices()[ESIM_PRODUCT]['eur'] ?? 11;

page_header("Dj'aki mi ta! · " . t('hero.title'));
?>
<section class="hero">
  <img class="hero-img" src="/assets/img/hero-beach.jpg" alt="" width="1600" height="1067">
  <div class="wrap hero-in">
    <div class="hero-copy">
      <p class="kicker"><?= icon('sun', 18) ?> <?= e(t('hero.kicker')) ?></p>
      <h1><?= e(t('hero.title')) ?></h1>
      <p class="lead"><?= e(t('hero.text', ['n' => $count])) ?></p>
      <div class="actions">
        <a class="btn btn-lg" href="#cards"><?= e(t('hero.cta', ['price' => $fromPrice])) ?> <?= icon('arrow', 18) ?></a>
        <a class="btn btn-lg btn-ghost" href="/partners.php"><?= e(t('hero.cta2')) ?></a>
      </div>
      <ul class="stats">
        <li><strong><?= $count ?>+</strong> <?= e(t('hero.stat1')) ?></li>
        <li><?= e(t('hero.stat2')) ?> <strong><?= $best ?>%</strong> <?= e(t('hero.stat2b')) ?></li>
        <li><?= icon('qr', 18) ?> <?= e(t('hero.stat3')) ?></li>
      </ul>
    </div>
    <div class="hero-card" aria-hidden="true">
      <div class="dcard">
        <div class="dcard-top">
          <img src="/assets/img/logo-mark.png" alt="" width="40" height="40">
          <span><?= e(t('card.adult')) ?></span>
        </div>
        <div class="dcard-qr"><?= icon('qr', 96) ?></div>
        <div class="dcard-bottom">
          <span class="muted"><?= e(t('card.valid')) ?></span>
          <strong><?= e(date('d M Y', strtotime('+' . $months . ' months'))) ?></strong>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="how">
  <div class="wrap">
    <h2><?= e(t('how.title')) ?></h2>
    <ol class="steps">
      <li><span class="step-n">1</span><div><h3><?= e(t('how.s1')) ?></h3><p><?= e(t('how.s1t')) ?></p></div></li>
      <li><span class="step-n">2</span><div><h3><?= e(t('how.s2')) ?></h3><p><?= e(t('how.s2t')) ?></p></div></li>
      <li><span class="step-n">3</span><div><h3><?= e(t('how.s3')) ?></h3><p><?= e(t('how.s3t')) ?></p></div></li>
    </ol>
  </div>
</section>

<section class="section tint">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <h2><?= e(t('deals.title')) ?></h2>
        <p class="muted"><?= e(t('deals.text')) ?></p>
      </div>
      <a class="more" href="/partners.php"><?= e(t('deals.all')) ?> <?= icon('arrow', 18) ?></a>
    </div>
    <div class="grid-partners">
      <?php foreach ($featured as $p): ?>
        <a class="pcard" href="/partner.php?id=<?= (int) $p['id'] ?>">
          <span class="pcard-img"><img src="<?= e(partner_image_url($p)) ?>" alt="" loading="lazy" width="400" height="300"></span>
          <?php if ($lbl = discount_label($p['best_percent'])): ?><span class="badge"><?= e($lbl) ?></span><?php endif; ?>
          <span class="pcard-body">
            <strong><?= e($p['name']) ?></strong>
            <span class="muted small"><?= icon('pin', 14) ?> <?= e(trim(explode(',', (string) $p['location'])[0])) ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section" id="cards">
  <div class="wrap">
    <h2><?= e(t('cards.title')) ?></h2>
    <p class="muted"><?= e(t('cards.text')) ?></p>
    <div class="grid-cards">
      <?php foreach ($cards as $c): $k = $c['key']; ?>
        <div class="price-card<?= $c['popular'] ? ' popular' : '' ?>">
          <?php if ($c['popular']): ?><span class="ribbon"><?= e(t('cards.popular')) ?></span><?php endif; ?>
          <h3><?= e(t('cards.' . $k)) ?></h3>
          <p class="muted small"><?= e(t('cards.' . $k . '.t')) ?></p>
          <p class="price"><?= e(money(card_price($k))) ?> <span class="muted small">/ <?= e(money(card_price($k, 'usd'), 'usd')) ?></span></p>
          <p class="small"><?= icon('check', 16) ?> <?= e(t('cards.valid', ['m' => $months])) ?></p>
          <a class="btn btn-block<?= $c['popular'] ? '' : ' btn-outline' ?>" href="/checkout.php?card=<?= e($k) ?>"><?= e(t('cards.buy')) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="esim">
      <span class="esim-icon"><?= icon('wifi', 28) ?></span>
      <div>
        <h3><?= e(t('esim.title')) ?></h3>
        <p class="muted"><?= e(t('esim.text')) ?></p>
      </div>
      <a class="btn btn-outline" href="/checkout.php?card=esim"><?= e(t('esim.cta', ['price' => money($esimPrice)])) ?></a>
    </div>
  </div>
</section>

<section class="section dark">
  <div class="wrap split">
    <div>
      <h2><?= e(t('diaspora.title')) ?></h2>
      <p><?= e(t('diaspora.text')) ?></p>
    </div>
    <div class="partner-cta">
      <h3><?= e(t('join.title')) ?></h3>
      <p><?= e(t('join.text')) ?></p>
      <a class="btn btn-gold" href="/become-a-partner.php"><?= e(t('join.cta')) ?> <?= icon('arrow', 18) ?></a>
    </div>
  </div>
</section>

<section class="section" id="faq">
  <div class="wrap narrow">
    <h2><?= e(t('faq.title')) ?></h2>
    <?php foreach ([1, 2, 3, 4] as $i): ?>
      <details class="faq">
        <summary><?= e(t('faq.q' . $i)) ?> <?= icon('chevron', 18) ?></summary>
        <p><?= e(t('faq.a' . $i, ['m' => $months])) ?></p>
      </details>
    <?php endforeach; ?>
  </div>
</section>
<?php page_footer();
