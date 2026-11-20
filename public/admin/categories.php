<?php
// Staff backend: partner categories (the filter buttons on the partner list).
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

admin_post(function () use ($db) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim((string) $_POST['name']);
        if ($name === '') {
            throw new RuntimeException('Type a name.');
        }
        $db->prepare('INSERT INTO category (name, active) VALUES (:n, 1)')->execute(['n' => mb_substr($name, 0, 80)]);
        admin_log('category_added', $name);
        flash('Category added. Partners can now be put in it.');
    } elseif ($action === 'save') {
        $up = $db->prepare('UPDATE category SET name = :n, active = :a WHERE id = :id');
        foreach ((array) ($_POST['name'] ?? []) as $cid => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $up->execute(['n' => mb_substr($name, 0, 80), 'a' => empty($_POST['active'][$cid]) ? 0 : 1, 'id' => (int) $cid]);
        }
        admin_log('categories_saved');
        flash('Categories saved.');
    }
}, '/admin/categories.php');

$cats = $db->query('SELECT c.id, TRIM(c.name) name, c.active,
        (SELECT COUNT(*) FROM voucher_company v WHERE v.active = 1 AND (v.category_id = c.id OR JSON_CONTAINS(COALESCE(NULLIF(v.additional_category_ids, ""), "[]"), CONCAT(c.id)))) n
    FROM category c ORDER BY c.active DESC, name')->fetchAll();

admin_head('Categories', 'categories');
?>
<h1>Categories</h1>
<p class="ad-muted">These are the filter buttons above the partner list. A category only shows on the website when it's switched on and has at least one active partner.</p>
<form method="post" class="ad-box"><?= csrf_field() ?>
  <div class="ad-wrap"><table class="ad-table">
    <thead><tr><th>Name</th><th>Active partners</th><th>Show</th></tr></thead>
    <tbody><?php foreach ($cats as $c): ?>
      <tr><td><input class="ad-in" name="name[<?= (int) $c['id'] ?>]" value="<?= e($c['name']) ?>"></td>
        <td><?= (int) $c['n'] ?></td>
        <td><label class="check"><input type="checkbox" name="active[<?= (int) $c['id'] ?>]" value="1"<?= (int) $c['active'] ? ' checked' : '' ?>> <span>On</span></label></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="ad-actions"><button class="btn btn-sm" name="action" value="save">Save all</button></div>
</form>
<form method="post" class="ad-box ad-actions"><?= csrf_field() ?>
  <input class="ad-in" style="max-width:320px" name="name" placeholder="New category name">
  <button class="btn btn-sm" name="action" value="add">Add category</button>
</form>
<?php admin_foot();
