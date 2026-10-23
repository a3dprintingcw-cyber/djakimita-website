<?php
require __DIR__ . '/../app/bootstrap.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$p = $id ? partner($id) : null;
if (!$p) {
    http_response_code(404);
    page_header('Not found');
    echo '<section class="section"><div class="wrap narrow"><h1>404</h1><p><a href="/partners.php">' . e(t('partner.back')) . '</a></p></div></section>';
    page_footer();
    exit;
}

$days = ['Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6, 'Sun' => 7];
$dayNames = [
    'en' => [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    'nl' => [1 => 'Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'],
    'pap' => [1 => 'Djaluna', 'Djamars', 'Djárason', 'Djuweps', 'Djabièrnè', 'Djasabra', 'Djadumingu'],
][current_lang()];
$mapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($p['name'] . ' ' . $p['location'] . ' Curaçao');
$website = filter_var($p['website'], FILTER_VALIDATE_URL) && preg_match('#^https?://#i', (string) $p['website']) ? $p['website'] : '';
$phone = preg_replace('/[^0-9+]/', '', (string) $p['phone']);
$best = $p['deals'] ? max(array_column($p['deals'], 'the_percent')) : 0;

page_header($p['name'] . " · Dj'aki mi ta!", 'partners', mb_substr(strip_tags((string) $p['description']), 0, 155));
?>
<section class="section pt-sm">
  <div class="wrap">
    <a class="back" href="/partners.php"><?= icon('back', 18) ?> <?= e(t('partner.back')) ?></a>
    <div class="detail">
      <div class="gallery">
        <?php if ($p['images']): ?>
          <img class="gallery-main" src="<?= e(url('img.php', ['i' => $p['images'][0], 's' => 'l'])) ?>" alt="<?= e($p['name']) ?>" width="1400" height="930">
          <?php if (count($p['images']) > 1): ?>
            <div class="thumbs">
              <?php foreach (array_slice($p['images'], 1, 4) as $img): ?>
                <img src="<?= e(url('img.php', ['i' => $img, 's' => 's'])) ?>" alt="" loading="lazy" width="360" height="240">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <img class="gallery-main" src="<?= e(partner_image_url($p, 'l')) ?>" alt="<?= e($p['name']) ?>" width="1400" height="930">
        <?php endif; ?>
      </div>

      <div class="detail-info">
        <?php if ($best > 0): ?><span class="badge badge-inline"><?= e(discount_label($best)) ?></span><?php endif; ?>
        <h1><?= e($p['name']) ?></h1>
        <div class="desc"><?= nl2br(e(trim((string) $p['description']))) ?></div>

        <?php if ($p['deals']): ?>
          <div class="box">
            <h2 class="h3"><?= e(t('partner.discounts')) ?></h2>
            <table class="deals">
              <?php foreach ($p['deals'] as $d): $lbl = package_label((int) $d['voucher_id']); if (!$lbl) continue; ?>
                <tr>
                  <th scope="row"><?= e($lbl) ?></th>
                  <td><strong><?= (int) round((float) $d['the_percent']) >= 100 ? e(t('deals.free')) : e(t('deals.off', ['p' => (int) round((float) $d['the_percent'])])) ?></strong></td>
                  <td class="muted small"><?= e(t('partner.was')) ?> <s><?= e(number_format((float) $d['was'], 2)) ?></s></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php endif; ?>

        <div class="box">
          <h2 class="h3"><?= icon('pin', 18) ?> <?= e(t('partner.location')) ?></h2>
          <p><?= e($p['location'] ?: 'Curaçao') ?></p>
          <div class="row-actions">
            <a class="btn btn-outline btn-sm" href="<?= e($mapsUrl) ?>" target="_blank" rel="noopener"><?= e(t('partner.route')) ?></a>
            <?php if ($website): ?><a class="btn btn-outline btn-sm" href="<?= e($website) ?>" target="_blank" rel="noopener"><?= icon('globe', 16) ?> <?= e(t('partner.website')) ?></a><?php endif; ?>
            <?php if ($phone): ?><a class="btn btn-outline btn-sm" href="tel:<?= e($phone) ?>"><?= icon('phone', 16) ?> <?= e(t('partner.call')) ?></a><?php endif; ?>
          </div>
        </div>

        <?php if ($p['hours']): ?>
          <div class="box">
            <h2 class="h3"><?= icon('clock', 18) ?> <?= e(t('partner.hours')) ?></h2>
            <table class="hours">
              <?php foreach ($p['hours'] as $h):
                  $n = $days[$h['day'] ?? ''] ?? null;
                  if (!$n) continue;
                  $dayName = $dayNames[$n];
                  $open = trim((string) ($h['open'] ?? ''));
                  $closed = $open === '' || strcasecmp($open, 'closed') === 0;
              ?>
                <tr<?= (int) date('N') === $n ? ' class="today"' : '' ?>>
                  <th scope="row"><?= e($dayName) ?></th>
                  <td><?= $closed ? e(t('partner.closed')) : e($open . ' – ' . ($h['close'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php endif; ?>

        <a class="btn btn-lg btn-block" href="/#cards"><?= e(t('partner.cta')) ?> <?= icon('arrow', 18) ?></a>
      </div>
    </div>
  </div>
</section>
<?php page_footer();
