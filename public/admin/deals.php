<?php
// Staff backend: all partner discounts on one page (% off per card type). Same data the website and app use.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$country = (int) ($_GET['country'] ?? 1) === 2 ? 2 : 1;
$cards = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior'];

admin_post(function () use ($db, $cards) {
    $find = $db->prepare('SELECT id, was FROM voucher_company_info_deals WHERE company_id = :c AND voucher_id = :v ORDER BY id LIMIT 1');
    $up = $db->prepare('UPDATE voucher_company_info_deals SET the_percent = :p, after = :a WHERE id = :id');
    $ins = $db->prepare('INSERT INTO voucher_company_info_deals (company_id, voucher_id, was, after, the_percent) VALUES (:c, :v, 0, 0, :p)');
    $del = $db->prepare('DELETE FROM voucher_company_info_deals WHERE company_id = :c AND voucher_id = :v');
    $changed = 0;
    foreach ((array) ($_POST['p'] ?? []) as $cid => $row) {
        foreach ($cards as $v => $label) {
            if (!array_key_exists($v, $row)) {
                continue;
            }
            $raw = trim(str_replace(',', '.', (string) $row[$v]));
            $old = (string) ($_POST['o'][$cid][$v] ?? '');
            if ($raw === $old) {
                continue;
            }
            $pct = $raw === '' ? 0 : max(0, min(100, (float) $raw));
            if ($pct <= 0) {
                $del->execute(['c' => (int) $cid, 'v' => $v]);
            } else {
                $find->execute(['c' => (int) $cid, 'v' => $v]);
                if ($d = $find->fetch()) {
                    $was = (float) $d['was'];
                    $up->execute(['p' => $pct, 'a' => $was > 0 ? round($was * (1 - $pct / 100), 2) : 0, 'id' => $d['id']]);
                } else {
                    $ins->execute(['c' => (int) $cid, 'v' => $v, 'p' => $pct]);
                }
            }
            $changed++;
        }
    }
    admin_log('deals_saved', $changed . ' changes');
    flash($changed ? 'Saved ' . $changed . ' change' . ($changed === 1 ? '' : 's') . '. The website and app show them right away.' : 'Nothing changed.');
}, '/admin/deals.php?country=' . $country);

$partners = $db->prepare('SELECT id, TRIM(name) name, active FROM voucher_company WHERE country = :c ORDER BY active DESC, name');
$partners->execute(['c' => $country]);
$partners = $partners->fetchAll();
$pct = [];
foreach ($db->query('SELECT company_id c, voucher_id v, MAX(the_percent) p FROM voucher_company_info_deals GROUP BY company_id, voucher_id') as $r) {
    $pct[(int) $r['c']][(int) $r['v']] = rtrim(rtrim(number_format((float) $r['p'], 2, '.', ''), '0'), '.');
}

admin_head('All discounts', 'deals');
?>
<h1>All discounts</h1>
<p class="ad-muted">The % off each account holder gets at each partner. Empty means no discount for that account, 100 means free.
  Change the numbers and press <strong>Save all</strong>. For the normal price and the price with the account, open the partner.</p>
<form class="ad-filter" method="get">
  <select name="country" onchange="this.form.submit()"><option value="1"<?= $country === 1 ? ' selected' : '' ?>>Dj'aki mi ta! (Curaçao)</option><option value="2"<?= $country === 2 ? ' selected' : '' ?>>Rib'e luga! (Netherlands)</option></select>
  <input type="search" id="dl-search" class="ad-in" placeholder="Search partner" style="max-width:240px">
</form>
<form method="post"><?= csrf_field() ?>
  <div class="ad-wrap"><table class="ad-table" id="dl">
    <thead><tr><th>Partner</th><?php foreach ($cards as $label): ?><th><?= e($label) ?> %</th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($partners as $p): $cid = (int) $p['id']; ?>
      <tr data-name="<?= e(mb_strtolower($p['name'])) ?>">
        <td><a href="/admin/partners.php?id=<?= $cid ?>"><?= e($p['name']) ?></a><?= (int) $p['active'] ? '' : ' <span class="ad-tag">Off</span>' ?></td>
        <?php foreach ($cards as $v => $label): $val = $pct[$cid][$v] ?? ''; ?>
          <td><input class="ad-in" style="width:76px" type="text" inputmode="decimal" name="p[<?= $cid ?>][<?= $v ?>]" value="<?= e($val) ?>" placeholder="–"><input type="hidden" name="o[<?= $cid ?>][<?= $v ?>]" value="<?= e($val) ?>"></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="ad-actions" style="position:sticky;bottom:0;background:#f4f6f2;padding:10px 0"><button class="btn" name="action" value="save">Save all</button></div>
</form>
<script>
document.getElementById('dl-search').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#dl tbody tr').forEach(function (r) { r.hidden = q !== '' && r.dataset.name.indexOf(q) === -1; });
});
</script>
<?php admin_foot();
