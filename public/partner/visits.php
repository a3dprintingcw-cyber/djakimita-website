<?php
// Partner portal: recent visits, with who it was.
require __DIR__ . '/../../app/bootstrap.php';
$u = pp_require();
$cid = (int) $u['company_id'];
$stats = pp_stats($cid);
$rows = pp_recent($cid, 50);
pp_head('Visits', $u, 'visits');
?>
<div class="pp-stats" style="margin-bottom:16px">
  <div><b><?= $stats['today'] ?></b><span>Today</span></div>
  <div><b><?= $stats['month'] ?></b><span>This month</span></div>
  <div><b><?= $stats['all'] ?></b><span>In total</span></div>
  <?php if ($stats['views'] !== null): ?><div><b><?= $stats['views'] ?></b><span>Page views in the app this month</span></div><?php endif; ?>
</div>
<div class="pp-box">
  <h2>Last 50 visits</h2>
  <?php if (!$rows): ?><p class="pp-muted">No visits yet. They show here as soon as a visit is registered.</p><?php else: ?>
  <div class="pp-visits">
    <?php $lastDay = ''; foreach ($rows as $r): $day = date('Y-m-d', strtotime((string) $r['date'])); ?>
      <?php if ($day !== $lastDay): $lastDay = $day; ?>
        <div class="pp-day"><?= $day === date('Y-m-d') ? 'Today' : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : e(date('l d-m-Y', strtotime($day)))) ?></div>
      <?php endif; ?>
      <div class="pp-visit">
        <?php if ($r['photo'] && $r['account']): ?>
          <img src="/partner/photo.php?v=<?= (int) $r['photo'] ?>&amp;a=<?= (int) $r['account'] ?>" alt="" loading="lazy">
        <?php else: ?>
          <i><?= e(mb_strtoupper(mb_substr((string) $r['who'], 0, 1))) ?></i>
        <?php endif; ?>
        <div class="who">
          <b><?= e($r['who']) ?></b>
          <span><?= e(package_label((int) $r['package_id']) ?: 'Account') ?> account<?= $r['account'] ? ' · ' . PP_ACCOUNT_PREFIX . (int) $r['account'] : '' ?></span>
          <?php if (count($r['people']) > 1): ?><span class="pp-muted">With: <?= e(implode(', ', $r['people'])) ?></span><?php endif; ?>
        </div>
        <time><?= e(date('H:i', strtotime((string) $r['date']))) ?></time>
        <?php if ($r['account']): ?><a class="btn btn-sm btn-outline" href="/partner/?acc=<?= PP_ACCOUNT_PREFIX . (int) $r['account'] ?>">Check</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php pp_foot();
