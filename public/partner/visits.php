<?php
// Partner portal: recent visits and numbers.
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
  <?php if (!$rows): ?><p class="pp-muted">No visits yet. They show here as soon as you register one.</p><?php else: ?>
  <table class="pp-list">
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e(date('d-m-Y H:i', strtotime((string) $r['date']))) ?></td><td><?= e(package_label((int) $r['package_id']) ?: 'Account') ?></td><td class="pp-muted"><?= PP_ACCOUNT_PREFIX . (int) $r['account'] ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
<?php pp_foot();
