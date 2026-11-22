<?php
// Staff backend: freebies per partner and card type (the voucher_package_amount table the app uses),
// and how often each partner was used.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$country = (int) ($_GET['country'] ?? 1) === 2 ? 2 : 1;
$packs = $db->query('SELECT id, name FROM voucher_packages WHERE active = 1 ORDER BY id')->fetchAll();
if (!$packs) {
    $packs = array_map(fn($i) => ['id' => $i, 'name' => card_name($i)], [1, 2, 3, 4, 5]);
}

admin_post(function () use ($db, $packs) {
    $amounts = (array) ($_POST['a'] ?? []);
    $find = $db->prepare('SELECT id FROM voucher_package_amount WHERE v_company_id = :c AND v_package_id = :p ORDER BY id LIMIT 1');
    $up = $db->prepare('UPDATE voucher_package_amount SET v_amount_total = :a WHERE id = :id');
    $ins = $db->prepare('INSERT INTO voucher_package_amount (v_company_id, v_package_id, v_amount_total) VALUES (:c, :p, :a)');
    $changed = 0;
    foreach ($amounts as $cid => $row) {
        foreach ($packs as $pk) {
            if (!isset($row[$pk['id']])) {
                continue;
            }
            $a = max(0, min(9999, (int) $row[$pk['id']]));
            $old = isset($_POST['o'][$cid][$pk['id']]) ? (int) $_POST['o'][$cid][$pk['id']] : null;
            if ($old === $a) {
                continue;
            }
            $find->execute(['c' => (int) $cid, 'p' => (int) $pk['id']]);
            if ($id = (int) $find->fetchColumn()) {
                $up->execute(['a' => $a, 'id' => $id]);
            } else {
                $ins->execute(['c' => (int) $cid, 'p' => (int) $pk['id'], 'a' => $a]);
            }
            $changed++;
        }
    }
    admin_log('freebies_saved', $changed . ' changes');
    flash($changed ? 'Saved ' . $changed . ' change' . ($changed === 1 ? '' : 's') . '. The app uses them right away.' : 'Nothing changed.');
}, '/admin/freebies.php?country=' . $country);

$partners = $db->prepare('SELECT id, TRIM(name) name, active FROM voucher_company WHERE country = :c ORDER BY active DESC, name');
$partners->execute(['c' => $country]);
$partners = $partners->fetchAll();
$amt = [];
foreach ($db->query('SELECT v_company_id c, v_package_id p, MAX(v_amount_total) a FROM voucher_package_amount GROUP BY v_company_id, v_package_id') as $r) {
    $amt[(int) $r['c']][(int) $r['p']] = (int) $r['a'];
}
$used = [];
$year = date('Y');
try {
    $st = $db->prepare('SELECT company_id, COUNT(*) n FROM client_package_info WHERE YEAR(date) = :y GROUP BY company_id');
    $st->execute(['y' => $year]);
    foreach ($st as $r) {
        $used[(int) $r['company_id']] = (int) $r['n'];
    }
} catch (Throwable $e) {
}

admin_head('Freebies', 'freebies');
?>
<h1>Freebies</h1>
<p class="ad-muted">How many freebies one card holder gets at each partner, per card type. These are the same numbers as in the old dashboard and the app uses them. 0 means none.
  "Used <?= $year ?>" is how often the card was scanned at that partner this year.</p>
<form class="ad-filter" method="get">
  <select name="country" onchange="this.form.submit()"><option value="1"<?= $country === 1 ? ' selected' : '' ?>>Dj'aki mi ta! (Curaçao)</option><option value="2"<?= $country === 2 ? ' selected' : '' ?>>Rib'e luga! (Netherlands)</option></select>
  <input type="search" id="fb-search" class="ad-in" placeholder="Search partner" style="max-width:240px">
</form>
<form method="post"><?= csrf_field() ?>
  <div class="ad-wrap"><table class="ad-table" id="fb">
    <thead><tr><th>Partner</th><?php foreach ($packs as $pk): ?><th><?= e($pk['name']) ?></th><?php endforeach; ?><th>Used <?= $year ?></th></tr></thead>
    <tbody>
    <?php foreach ($partners as $p): $cid = (int) $p['id']; ?>
      <tr data-name="<?= e(mb_strtolower($p['name'])) ?>">
        <td><a href="/admin/partners.php?id=<?= $cid ?>"><?= e($p['name']) ?></a><?= (int) $p['active'] ? '' : ' <span class="ad-tag">Off</span>' ?></td>
        <?php foreach ($packs as $pk): $v = $amt[$cid][(int) $pk['id']] ?? 0; ?>
          <td><input class="ad-in" style="width:70px" type="number" min="0" max="9999" name="a[<?= $cid ?>][<?= (int) $pk['id'] ?>]" value="<?= $v ?>"><input type="hidden" name="o[<?= $cid ?>][<?= (int) $pk['id'] ?>]" value="<?= $v ?>"></td>
        <?php endforeach; ?>
        <td><?= $used[$cid] ?? 0 ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="ad-actions" style="position:sticky;bottom:0;background:#f4f6f2;padding:10px 0"><button class="btn" name="action" value="save">Save all</button></div>
</form>
<script>
document.getElementById('fb-search').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#fb tbody tr').forEach(function (r) { r.hidden = q !== '' && r.dataset.name.indexOf(q) === -1; });
});
</script>
<?php admin_foot();
