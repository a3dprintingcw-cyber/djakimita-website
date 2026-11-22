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
          <a class="lb" href="<?= e(url('img.php', ['i' => $p['images'][0], 's' => 'l'])) ?>"><img class="gallery-main" src="<?= e(url('img.php', ['i' => $p['images'][0], 's' => 'l'])) ?>" alt="<?= e($p['name']) ?>" width="1400" height="930"></a>
          <?php if (count($p['images']) > 1): ?>
            <div class="thumbs">
              <?php foreach (array_slice($p['images'], 1) as $k => $img): ?>
                <a class="lb" href="<?= e(url('img.php', ['i' => $img, 's' => 'l'])) ?>"<?= $k >= 4 ? ' hidden' : '' ?>><img src="<?= e(url('img.php', ['i' => $img, 's' => 's'])) ?>" alt="" loading="lazy" width="360" height="240"></a>
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

        <?php if ($note = partner_note((int) $p['id'])): ?>
          <div class="p-note" role="note"><strong><?= e(t('p.note')) ?></strong><div><?= nl2br(e($note)) ?></div></div>
        <?php endif; ?>
        <?php if ($video = partner_video((int) $p['id'])): ?><div class="p-video"><?= video_embed($video, t('p.video')) ?></div><?php endif; ?>

        <?php
          // Same discount and price for every account? Show one line "All persons".
          $rows = array_values(array_filter($p['deals'], fn ($d) => package_label((int) $d['voucher_id'])));
          $sig = array_unique(array_map(fn ($d) => round((float) $d['the_percent']) . '|' . round((float) $d['was'], 2), $rows));
          if (count($rows) > 1 && count($sig) === 1) {
              $rows = [$rows[0] + ['all' => true]];
          }
        ?>
        <?php if ($rows): ?>
          <div class="box">
            <h2 class="h3"><?= e(t('partner.discounts')) ?></h2>
            <table class="deals">
              <?php foreach ($rows as $d): $pc = (int) round((float) $d['the_percent']); $was = (float) $d['was']; ?>
                <tr>
                  <th scope="row"><?= e(!empty($d['all']) ? t('deals.all') : package_label((int) $d['voucher_id'])) ?></th>
                  <?php if ($pc <= 0): ?>
                    <td colspan="2" class="muted"><?= e(t('deals.see')) ?></td>
                  <?php else: ?>
                    <td><strong><?= $pc >= 100 ? e(t('deals.free')) : e(t('deals.off', ['p' => $pc])) ?></strong></td>
                    <td class="muted small"><?php if ($was > 0): ?><?= e(t('partner.was')) ?> <s><?= e(number_format($was, 2)) ?></s><?php endif; ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        <?php else: ?>
          <div class="box"><h2 class="h3"><?= e(t('partner.discounts')) ?></h2><p class="muted" style="margin:0"><?= e(t('deals.see')) ?></p></div>
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
<div class="lbox" id="lbox" hidden role="dialog" aria-modal="true" aria-label="Photo">
  <button type="button" class="lbox-x" aria-label="Close">&times;</button>
  <button type="button" class="lbox-p" aria-label="Previous">&#8249;</button>
  <img alt="">
  <button type="button" class="lbox-n" aria-label="Next">&#8250;</button>
</div>
<style>
.p-video{margin:14px 0}.p-video .video{position:relative;aspect-ratio:16/9;border-radius:14px;overflow:hidden;background:#000}.p-video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.gallery a.lb{display:block;cursor:zoom-in}.gallery a.lb[hidden]{display:none}
.lbox{position:fixed;inset:0;z-index:1000;background:rgba(10,20,12,.92);display:flex;align-items:center;justify-content:center}
.lbox[hidden]{display:none}
.lbox img{max-width:94vw;max-height:86vh;border-radius:10px;object-fit:contain}
.lbox button{position:absolute;background:rgba(255,255,255,.14);color:#fff;border:0;border-radius:50%;width:48px;height:48px;font-size:32px;line-height:44px;cursor:pointer}
.lbox-x{top:16px;right:16px}.lbox-p{left:12px;top:50%;transform:translateY(-50%)}.lbox-n{right:12px;top:50%;transform:translateY(-50%)}
</style>
<script>
(function () {
  var links = [].slice.call(document.querySelectorAll('.gallery a.lb')), box = document.getElementById('lbox');
  if (!links.length || !box) return;
  var img = box.querySelector('img'), i = 0;
  function show(n) { i = (n + links.length) % links.length; img.src = links[i].href; }
  function close() { box.hidden = true; document.body.style.overflow = ''; }
  links.forEach(function (a, n) { a.addEventListener('click', function (e) { e.preventDefault(); show(n); box.hidden = false; document.body.style.overflow = 'hidden'; }); });
  var one = links.length < 2;
  box.querySelector('.lbox-p').hidden = one; box.querySelector('.lbox-n').hidden = one;
  box.querySelector('.lbox-p').onclick = function (e) { e.stopPropagation(); show(i - 1); };
  box.querySelector('.lbox-n').onclick = function (e) { e.stopPropagation(); show(i + 1); };
  box.querySelector('.lbox-x').onclick = close;
  box.addEventListener('click', function (e) { if (e.target === box) close(); });
  document.addEventListener('keydown', function (e) { if (box.hidden) return; if (e.key === 'Escape') close(); if (e.key === 'ArrowLeft') show(i - 1); if (e.key === 'ArrowRight') show(i + 1); });
  var x0 = null; box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, {passive: true});
  box.addEventListener('touchend', function (e) { if (x0 === null) return; var d = e.changedTouches[0].clientX - x0; if (Math.abs(d) > 50) show(i + (d < 0 ? 1 : -1)); x0 = null; });
})();
</script>
<?php page_footer();
