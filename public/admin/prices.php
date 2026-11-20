<?php
// Staff backend: card prices per length and currency, and the eSIM price.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$cardTypes = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior'];
$lenNames = ['10d' => '10 days', '1m' => '1 month', '3m' => '3 months', '6m' => '6 months', '12m' => '1 year'];

admin_post(function () use ($db) {
    $money = fn($v) => max(0, round((float) str_replace(',', '.', (string) $v), 2));
    if (($_POST['action'] ?? '') === 'save') {
        $up = $db->prepare('UPDATE card_lengths SET eur = :e, usd = :u, xcg = :x, active = :a WHERE id = :id');
        $changed = 0;
        foreach ((array) ($_POST['eur'] ?? []) as $lid => $eur) {
            $up->execute(['e' => $money($eur), 'u' => $money($_POST['usd'][$lid] ?? 0), 'x' => $money($_POST['xcg'][$lid] ?? 0),
                          'a' => empty($_POST['active'][$lid]) ? 0 : 1, 'id' => (int) $lid]);
            $changed++;
        }
        $db->prepare('UPDATE website_product_prices SET eur = :e, dollars = :u WHERE product_id = :p')
            ->execute(['e' => $money($_POST['esim_eur'] ?? 0), 'u' => $money($_POST['esim_usd'] ?? 0), 'p' => ESIM_PRODUCT]);
        admin_log('prices_saved', $changed . ' card prices + eSIM');
        flash('Prices saved. The checkout uses them right away. Orders already paid keep their price.');
    }
}, '/admin/prices.php');

$rows = $db->query('SELECT * FROM card_lengths ORDER BY product_id, sort')->fetchAll();
$byCard = [];
foreach ($rows as $r) {
    $byCard[(int) $r['product_id']][] = $r;
}
$esim = prices()[ESIM_PRODUCT] ?? ['eur' => 0, 'usd' => 0];
$num = fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

admin_head('Prices', 'prices');
?>
<h1>Prices</h1>
<p class="ad-muted">Prices for each card and length, in euro, dollar and guilder. Untick "Sell" to stop selling a length (for example no 10-day Family card). The checkout shows the new prices right away.</p>
<form method="post"><?= csrf_field() ?>
  <?php foreach ($cardTypes as $pid => $label): if (empty($byCard[$pid])) { continue; } ?>
    <div class="ad-box">
      <h2 style="margin-top:0"><?= e($label) ?> card</h2>
      <div class="ad-wrap"><table class="ad-table" style="max-width:720px">
        <thead><tr><th>Length</th><th>€ EUR</th><th>$ USD</th><th>ƒ XCG</th><th>Sell</th></tr></thead>
        <tbody><?php foreach ($byCard[$pid] as $l): ?>
          <tr><td><strong><?= e($lenNames[$l['code']] ?? $l['code']) ?></strong></td>
            <td><input class="ad-in" name="eur[<?= (int) $l['id'] ?>]" inputmode="decimal" value="<?= e($num($l['eur'])) ?>"></td>
            <td><input class="ad-in" name="usd[<?= (int) $l['id'] ?>]" inputmode="decimal" value="<?= e($num($l['usd'])) ?>"></td>
            <td><input class="ad-in" name="xcg[<?= (int) $l['id'] ?>]" inputmode="decimal" value="<?= e($num($l['xcg'])) ?>"></td>
            <td><label class="check"><input type="checkbox" name="active[<?= (int) $l['id'] ?>]" value="1"<?= (int) $l['active'] ? ' checked' : '' ?>> <span>Sell</span></label></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>
  <?php endforeach; ?>
  <div class="ad-box">
    <h2 style="margin-top:0">Flow eSIM (add-on)</h2>
    <div class="ad-actions">
      <label>€ <input class="ad-in" style="width:110px;display:inline-block" name="esim_eur" inputmode="decimal" value="<?= e($num($esim['eur'])) ?>"></label>
      <label>$ <input class="ad-in" style="width:110px;display:inline-block" name="esim_usd" inputmode="decimal" value="<?= e($num($esim['usd'])) ?>"></label>
      <span class="ad-muted">The guilder price is worked out from the dollar price.</span>
    </div>
  </div>
  <div class="ad-actions"><button class="btn" name="action" value="save">Save all prices</button></div>
</form>
<?php admin_foot();
