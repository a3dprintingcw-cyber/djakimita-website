<?php
// Staff backend: spin the wheel. Prizes, how often people may spin, and who won.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

admin_post(function () use ($db) {
    switch ($_POST['action'] ?? '') {
        case 'settings':
            set_setting('wheel_on', empty($_POST['wheel_on']) ? '0' : '1');
            set_setting('wheel_days', (string) max(1, min(365, (int) $_POST['wheel_days'])));
            admin_log('wheel_settings', (empty($_POST['wheel_on']) ? 'off' : 'on') . ', every ' . (int) $_POST['wheel_days'] . ' days');
            flash('Saved.');
            break;
        case 'add':
            $label = mb_substr(trim((string) $_POST['label']), 0, 120);
            if ($label === '') {
                throw new RuntimeException('Type what is on this part of the wheel.');
            }
            $db->prepare('INSERT INTO wheel_prizes (label, chance, is_prize, active, sort) VALUES (:l, :c, :p, 1, :s)')
                ->execute(['l' => $label, 'c' => max(1, (int) $_POST['chance']), 'p' => empty($_POST['is_prize']) ? 0 : 1, 's' => (int) $db->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM wheel_prizes')->fetchColumn()]);
            admin_log('wheel_prize_added', $label);
            flash('Added to the wheel.');
            break;
        case 'save':
            foreach ((array) ($_POST['label'] ?? []) as $id => $label) {
                $db->prepare('UPDATE wheel_prizes SET label = :l, chance = :c, is_prize = :p, active = :a WHERE id = :id')->execute([
                    'l' => mb_substr(trim((string) $label), 0, 120), 'c' => max(0, (int) ($_POST['chance'][$id] ?? 0)),
                    'p' => empty($_POST['is_prize'][$id]) ? 0 : 1, 'a' => empty($_POST['active'][$id]) ? 0 : 1, 'id' => (int) $id,
                ]);
            }
            admin_log('wheel_prizes_saved');
            flash('Wheel saved.');
            break;
        case 'delete':
            $db->prepare('DELETE FROM wheel_prizes WHERE id = :id')->execute(['id' => (int) $_POST['id']]);
            admin_log('wheel_prize_deleted', '#' . (int) $_POST['id']);
            flash('Removed from the wheel.');
            break;
        case 'given':
            $db->prepare('UPDATE wheel_spins SET given = 1 - given WHERE id = :id')->execute(['id' => (int) $_POST['id']]);
            flash('Updated.');
            break;
    }
}, '/admin/wheel.php');

$prizes = $db->query('SELECT * FROM wheel_prizes ORDER BY sort, id')->fetchAll();
$total = array_sum(array_map(fn($p) => (int) $p['active'] ? (int) $p['chance'] : 0, $prizes));
$wins = $db->query('SELECT w.*, c.email, c.username, c.lastName FROM wheel_spins w LEFT JOIN clients c ON c.id = w.client_id WHERE w.is_prize = 1 ORDER BY w.id DESC LIMIT 100')->fetchAll();
$spins = (int) $db->query('SELECT COUNT(*) FROM wheel_spins')->fetchColumn();

admin_head('Spin the wheel', 'wheel');
?>
<h1>Spin the wheel</h1>
<p class="ad-muted">Customers with an active account can spin the wheel in their account on the website. You decide what is on the wheel and how big the chance is.
  You get an email when someone wins a prize. Tip: also put a few "Try again next time" parts on the wheel.</p>

<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <label class="check full"><input type="checkbox" name="wheel_on" value="1"<?= setting('wheel_on', '0') === '1' ? ' checked' : '' ?>> <span><strong>Show the wheel</strong> in customer accounts</span></label>
  <div><label>People can spin once every … days</label><input type="number" name="wheel_days" min="1" max="365" value="<?= (int) wheel_every_days() ?>"></div>
  <div class="full"><button class="btn btn-sm" name="action" value="settings">Save</button> <span class="ad-muted"><?= $spins ?> spins so far</span></div>
</form>

<form method="post" class="ad-box"><?= csrf_field() ?>
  <h2 style="margin-top:0">On the wheel</h2>
  <?php if (count(array_filter($prizes, fn($p) => (int) $p['active'])) < 2): ?><p class="ad-flash bad">Add at least 2 parts before the wheel shows up.</p><?php endif; ?>
  <div class="ad-wrap"><table class="ad-table">
    <thead><tr><th>Text on the wheel</th><th>Chance</th><th>Is a prize</th><th>On</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($prizes as $p): ?>
      <tr>
        <td><input class="ad-in" name="label[<?= (int) $p['id'] ?>]" value="<?= e($p['label']) ?>"></td>
        <td><input class="ad-in" style="width:80px" type="number" min="0" name="chance[<?= (int) $p['id'] ?>]" value="<?= (int) $p['chance'] ?>">
          <span class="ad-muted"><?= $total && (int) $p['active'] ? round(100 * $p['chance'] / $total, 1) . '%' : '' ?></span></td>
        <td><input type="checkbox" name="is_prize[<?= (int) $p['id'] ?>]" value="1"<?= (int) $p['is_prize'] ? ' checked' : '' ?>></td>
        <td><input type="checkbox" name="active[<?= (int) $p['id'] ?>]" value="1"<?= (int) $p['active'] ? ' checked' : '' ?>></td>
        <td><button class="btn btn-sm btn-outline" name="action" value="delete" formnovalidate onclick="this.form.querySelector('[name=id]').value=<?= (int) $p['id'] ?>;return confirm('Remove from the wheel?')">Remove</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$prizes): ?><tr><td colspan="5" class="ad-muted">Nothing on the wheel yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <input type="hidden" name="id" value="0">
  <?php if ($prizes): ?><div class="ad-actions"><button class="btn btn-sm" name="action" value="save">Save wheel</button></div><?php endif; ?>
  <p class="ad-muted">Chance works like tickets in a hat: a part with 10 is twice as likely as a part with 5.</p>
</form>

<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">Add to the wheel</h2>
  <div><label>Text</label><input type="text" name="label" required placeholder="Free ice cream at …"></div>
  <div><label>Chance</label><input type="number" name="chance" min="1" value="10"></div>
  <label class="check"><input type="checkbox" name="is_prize" value="1" checked> <span>This is a prize (uncheck for "Try again")</span></label>
  <div class="full"><button class="btn btn-sm" name="action" value="add">Add</button></div>
</form>

<h2>Winners</h2>
<div class="ad-wrap"><table class="ad-table">
  <thead><tr><th>When</th><th>Who</th><th>Prize</th><th>Given</th></tr></thead>
  <tbody>
  <?php foreach ($wins as $w): ?>
    <tr><td><?= e(admin_date($w['created_at'], true)) ?></td>
      <td><a href="/admin/customers.php?id=<?= (int) $w['client_id'] ?>"><?= e(trim($w['username'] . ' ' . $w['lastName']) ?: $w['email']) ?></a></td>
      <td><?= e($w['label']) ?></td>
      <td><form method="post" class="ad-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $w['id'] ?>">
        <button class="btn btn-sm <?= (int) $w['given'] ? '' : 'btn-outline' ?>" name="action" value="given"><?= (int) $w['given'] ? '✓ Given' : 'Mark as given' ?></button></form></td></tr>
  <?php endforeach; ?>
  <?php if (!$wins): ?><tr><td colspan="4" class="ad-muted">No winners yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php admin_foot();
