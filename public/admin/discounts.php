<?php
// Staff backend: discount codes for the checkout (a percentage off the card).
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

admin_post(function () use ($db) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_POST['code']));
        $pct = (float) str_replace(',', '.', (string) $_POST['percent']);
        if (strlen($code) < 3) {
            throw new RuntimeException('The code needs at least 3 letters or numbers (no spaces).');
        }
        if ($pct < 1 || $pct > 90) {
            throw new RuntimeException('The discount must be between 1 and 90%. To give a card for free, use Customers → Give a card.');
        }
        $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['valid_until']) ? (string) $_POST['valid_until'] : null;
        $max = (int) ($_POST['max_uses'] ?? 0) ?: null;
        $dup = $db->prepare('SELECT id FROM discount_codes WHERE code = :c');
        $dup->execute(['c' => $code]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('That code already exists.');
        }
        $db->prepare('INSERT INTO discount_codes (code, percent, valid_until, max_uses, used, active, created_at) VALUES (:c, :p, :u, :m, 0, 1, NOW())')
            ->execute(['c' => $code, 'p' => $pct, 'u' => $until, 'm' => $max]);
        admin_log('discount_added', $code . ' ' . $pct . '%' . ($until ? ' until ' . $until : ''));
        flash('Code ' . $code . ' added. Customers type it at checkout.');
    } elseif ($action === 'toggle') {
        $db->prepare('UPDATE discount_codes SET active = 1 - active WHERE id = :id')->execute(['id' => (int) $_POST['id']]);
        admin_log('discount_toggled', '#' . (int) $_POST['id']);
        flash('Updated.');
    } elseif ($action === 'save') {
        $until = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_POST['valid_until']) ? (string) $_POST['valid_until'] : null;
        $pct = (float) str_replace(',', '.', (string) $_POST['percent']);
        if ($pct < 1 || $pct > 90) {
            throw new RuntimeException('The discount must be between 1 and 90%.');
        }
        $db->prepare('UPDATE discount_codes SET percent = :p, valid_until = :u, max_uses = :m WHERE id = :id')
            ->execute(['p' => $pct, 'u' => $until, 'm' => (int) ($_POST['max_uses'] ?? 0) ?: null, 'id' => (int) $_POST['id']]);
        admin_log('discount_saved', '#' . (int) $_POST['id']);
        flash('Saved.');
    } elseif ($action === 'delete') {
        $db->prepare('DELETE FROM discount_codes WHERE id = :id AND used = 0')->execute(['id' => (int) $_POST['id']]);
        admin_log('discount_deleted', '#' . (int) $_POST['id']);
        flash('Deleted. (Codes that were already used stay in the list; switch them off instead.)');
    }
}, '/admin/discounts.php');

$codes = $db->query('SELECT d.*, (SELECT COALESCE(SUM(s.amount), 0) FROM sale_discounts s WHERE s.code_id = d.id AND s.counted = 1) given FROM discount_codes d ORDER BY d.active DESC, d.id DESC')->fetchAll();
$today = date('Y-m-d');

admin_head('Discount codes', 'discounts');
?>
<h1>Discount codes</h1>
<p class="ad-muted">A code gives a percentage off the card at checkout (not off the eSIM). Example: PREORDER, 10%, valid until 30 November.</p>

<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">New code</h2>
  <div><label>Code</label><input type="text" name="code" required placeholder="PREORDER" style="text-transform:uppercase"></div>
  <div><label>Discount (%)</label><input type="number" name="percent" min="1" max="90" step="0.5" required placeholder="10"></div>
  <div><label>Valid until (last day it works)</label><input type="date" name="valid_until" value="2026-11-30"></div>
  <div><label>Max times it can be used (empty = no limit)</label><input type="number" name="max_uses" min="1"></div>
  <div class="full"><button class="btn btn-sm" name="action" value="add">Add code</button></div>
</form>

<div class="ad-wrap"><table class="ad-table">
  <thead><tr><th>Code</th><th>Discount · valid until · max uses</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($codes as $c):
      $expired = $c['valid_until'] && $c['valid_until'] < $today;
      $full = $c['max_uses'] !== null && (int) $c['used'] >= (int) $c['max_uses']; ?>
    <tr>
      <td><strong><?= e($c['code']) ?></strong></td>
      <td>
        <form method="post" class="ad-actions" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <input class="ad-in" style="width:80px" type="number" name="percent" min="1" max="90" step="0.5" value="<?= e(rtrim(rtrim((string) $c['percent'], '0'), '.')) ?>"> %
          <input class="ad-in" style="width:160px" type="date" name="valid_until" value="<?= e((string) $c['valid_until']) ?>">
          <input class="ad-in" style="width:110px" type="number" name="max_uses" min="1" placeholder="no limit" value="<?= $c['max_uses'] === null ? '' : (int) $c['max_uses'] ?>">
          <span class="ad-muted"><?= (int) $c['used'] ?> × used<?= (float) $c['given'] > 0 ? ' · ' . e(number_format((float) $c['given'], 2)) . ' off in total' : '' ?></span>
          <button class="btn btn-sm btn-outline" name="action" value="save">Save</button>
        </form>
      </td>
      <td><?= !(int) $c['active'] ? '<span class="ad-tag">Off</span>' : ($expired ? '<span class="ad-tag bad">Expired</span>' : ($full ? '<span class="ad-tag bad">Used up</span>' : '<span class="ad-tag ok">Works</span>')) ?></td>
      <td><form method="post" class="ad-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="btn btn-sm btn-outline" name="action" value="toggle"><?= (int) $c['active'] ? 'Switch off' : 'Switch on' ?></button>
          <?php if (!(int) $c['used']): ?><button class="btn btn-sm btn-outline" name="action" value="delete" onclick="return confirm('Delete this code?')">Delete</button><?php endif; ?>
        </form></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$codes): ?><tr><td colspan="4" class="ad-muted">No codes yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php admin_foot();
