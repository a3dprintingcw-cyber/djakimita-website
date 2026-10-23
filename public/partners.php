<?php
require __DIR__ . '/../app/bootstrap.php';

$cat = isset($_GET['cat']) ? max(0, (int) $_GET['cat']) : 0;
$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 60);

$list = partners($cat ?: null, $q);
$cats = categories();
$total = partner_count();

page_header(t('partners.title') . " · Dj'aki mi ta!", 'partners');
?>
<section class="page-head">
  <div class="wrap">
    <h1><?= e(t('partners.title')) ?></h1>
    <p class="lead muted"><?= e(t('partners.text', ['n' => $total])) ?></p>
    <form class="search" method="get" action="/partners.php" role="search">
      <label class="sr" for="q"><?= e(t('partners.search')) ?></label>
      <?= icon('search', 20) ?>
      <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="<?= e(t('partners.search')) ?>">
      <?php if ($cat): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
      <button class="btn" type="submit"><?= icon('search', 18) ?><span class="sr"><?= e(t('partners.search')) ?></span></button>
    </form>
    <nav class="chips" aria-label="Categories">
      <a href="/partners.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"<?= !$cat ? ' class="on" aria-current="true"' : '' ?>><?= e(t('partners.all')) ?></a>
      <?php foreach ($cats as $c): ?>
        <a href="/partners.php?<?= e(http_build_query(array_filter(['cat' => $c['id'], 'q' => $q]))) ?>"<?= $cat === (int) $c['id'] ? ' class="on" aria-current="true"' : '' ?>><?= e($c['name']) ?> <span><?= (int) $c['n'] ?></span></a>
      <?php endforeach; ?>
    </nav>
  </div>
</section>

<section class="section pt0">
  <div class="wrap">
    <p class="muted small"><?= e(t('partners.results', ['n' => count($list)])) ?></p>
    <?php if (!$list): ?>
      <p class="empty"><?= e(t('partners.none')) ?></p>
    <?php else: ?>
      <div class="grid-partners">
        <?php foreach ($list as $p): ?>
          <a class="pcard" href="/partner.php?id=<?= (int) $p['id'] ?>">
            <span class="pcard-img"><img src="<?= e(partner_image_url($p)) ?>" alt="" loading="lazy" width="400" height="300"></span>
            <?php if ($lbl = discount_label($p['best_percent'])): ?><span class="badge"><?= e($lbl) ?></span><?php endif; ?>
            <span class="pcard-body">
              <strong><?= e($p['name']) ?></strong>
              <span class="muted small"><?= icon('pin', 14) ?> <?= e(trim(explode(',', (string) $p['location'])[0]) ?: 'Curaçao') ?></span>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php page_footer();
