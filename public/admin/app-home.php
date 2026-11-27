<?php
// Staff backend: the top of the app's home screen (big picture + button) and the ads below it.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$countries = [1 => "Dj'aki mi ta! app", 2 => "Rib'e luga! app"];

admin_post(function () use ($db) {
    $action = $_POST['action'] ?? '';
    $country = (int) ($_POST['country'] ?? 1) === 2 ? 2 : 1;
    $link = function (string $type, string $value): array {
        $type = $type === 'company' ? 'company' : 'url';
        $value = trim($value);
        if ($type === 'company') {
            $value = (string) (int) $value;
        } elseif ($value !== '' && !preg_match('~^https?://~i', $value)) {
            $value = 'https://' . $value;
        }
        return [$type, mb_substr($value, 0, 500)];
    };
    switch ($action) {
        case 'hero':
            [$type, $value] = $link((string) $_POST['button_action'], (string) $_POST['button_value']);
            $exists = $db->prepare('SELECT id FROM home_hero WHERE country = :c ORDER BY id LIMIT 1');
            $exists->execute(['c' => $country]);
            $hid = (int) $exists->fetchColumn();
            $fields = ['t' => mb_substr(trim((string) $_POST['header_text']), 0, 250), 'b' => mb_substr(trim((string) $_POST['header_big_text']), 0, 250),
                       'bt' => mb_substr(trim((string) $_POST['button_text']), 0, 120), 'ba' => $type, 'bv' => $value, 'a' => empty($_POST['active']) ? 0 : 1];
            if ($hid) {
                $db->prepare('UPDATE home_hero SET header_text = :t, header_big_text = :b, button_text = :bt, button_action = :ba, button_value = :bv, active = :a, updated_at = NOW() WHERE id = :id')
                    ->execute($fields + ['id' => $hid]);
            } else {
                $db->prepare('INSERT INTO home_hero (country, header_text, header_big_text, button_text, button_action, button_value, active, created_at, updated_at) VALUES (:c, :t, :b, :bt, :ba, :bv, :a, NOW(), NOW())')
                    ->execute($fields + ['c' => $country]);
                $hid = (int) $db->lastInsertId();
            }
            if (!empty($_FILES['image']['name'])) {
                [$bytes, $mime] = admin_image_upload($_FILES['image'], 1600);
                $up = $db->prepare('UPDATE home_hero SET header_image = :d, header_image_type = :t WHERE id = :id');
                $up->bindValue('d', $bytes, PDO::PARAM_LOB);
                $up->bindValue('t', $mime);
                $up->bindValue('id', $hid);
                $up->execute();
            }
            admin_log('app_hero_saved', 'country ' . $country);
            flash('App home picture saved. The app shows it the next time it loads.');
            break;
        case 'ad_add':
            [$type, $value] = $link((string) $_POST['link_type'], (string) $_POST['link_value']);
            [$bytes, $mime] = admin_image_upload($_FILES['image'] ?? [], 1400);
            $sort = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM home_advertisement WHERE country = ' . $country)->fetchColumn();
            $ins = $db->prepare('INSERT INTO home_advertisement (country, ad_image, ad_image_type, link_type, link_value, sort_order, active, created_at, updated_at) VALUES (:c, :d, :t, :lt, :lv, :s, 1, NOW(), NOW())');
            $ins->bindValue('c', $country);
            $ins->bindValue('d', $bytes, PDO::PARAM_LOB);
            $ins->bindValue('t', $mime);
            $ins->bindValue('lt', $type);
            $ins->bindValue('lv', $value);
            $ins->bindValue('s', $sort);
            $ins->execute();
            admin_log('app_ad_added', 'country ' . $country);
            flash('Ad added.');
            break;
        case 'ad_save':
            [$type, $value] = $link((string) $_POST['link_type'], (string) $_POST['link_value']);
            $db->prepare('UPDATE home_advertisement SET link_type = :lt, link_value = :lv, sort_order = :s, active = :a, updated_at = NOW() WHERE id = :id')
                ->execute(['lt' => $type, 'lv' => $value, 's' => (int) $_POST['sort_order'], 'a' => empty($_POST['active']) ? 0 : 1, 'id' => (int) $_POST['ad']]);
            admin_log('app_ad_saved', '#' . (int) $_POST['ad']);
            flash('Ad saved.');
            break;
        case 'ad_delete':
            $db->prepare('DELETE FROM home_advertisement WHERE id = :id')->execute(['id' => (int) $_POST['ad']]);
            admin_log('app_ad_deleted', '#' . (int) $_POST['ad']);
            flash('Ad deleted.');
            break;
    }
}, '/admin/app-home.php?country=' . ((int) ($_POST['country'] ?? $_GET['country'] ?? 1) === 2 ? 2 : 1));

$country = (int) ($_GET['country'] ?? 1) === 2 ? 2 : 1;
$hero = $db->prepare('SELECT id, header_text, header_big_text, button_text, button_action, button_value, active, LENGTH(header_image) n FROM home_hero WHERE country = :c ORDER BY id LIMIT 1');
$hero->execute(['c' => $country]);
$hero = $hero->fetch() ?: ['id' => 0, 'header_text' => '', 'header_big_text' => '', 'button_text' => '', 'button_action' => 'url', 'button_value' => '', 'active' => 1, 'n' => 0];
$ads = $db->prepare('SELECT id, link_type, link_value, sort_order, active FROM home_advertisement WHERE country = :c ORDER BY sort_order, id');
$ads->execute(['c' => $country]);
$ads = $ads->fetchAll();
$partners = $db->prepare('SELECT id, TRIM(name) name FROM voucher_company WHERE country = :c AND active = 1 ORDER BY name');
$partners->execute(['c' => $country]);
$partners = $partners->fetchAll();

$linkFields = function (string $type, string $value, string $prefix) use ($partners): string {
    ob_start(); ?>
    <div><label>When someone taps it, open</label>
      <select name="<?= $prefix ?>_type" onchange="adLink(this)">
        <option value="url"<?= $type === 'url' ? ' selected' : '' ?>>A website (link)</option>
        <option value="company"<?= $type === 'company' ? ' selected' : '' ?>>A partner's page in the app</option>
      </select></div>
    <div class="lk-url"<?= $type === 'url' ? '' : ' hidden' ?>><label>Web address</label><input type="text" data-v name="<?= $prefix ?>_value" value="<?= $type === 'url' ? e($value) : '' ?>" placeholder="https://djakimita.com"<?= $type === 'url' ? '' : ' disabled' ?>></div>
    <div class="lk-co"<?= $type === 'company' ? '' : ' hidden' ?>><label>Partner</label><select data-v name="<?= $prefix ?>_value"<?= $type === 'company' ? '' : ' disabled' ?>>
      <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $type === 'company' && (int) $value === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <?php return (string) ob_get_clean();
};

admin_head('App home', 'app-home');
?>
<h1>App home screen</h1>
<p class="ad-muted">What people see first when they open the app: one big picture at the top with a button, and below it a row of banners (ads) they can swipe through.</p>
<form class="ad-filter" method="get"><select name="country" onchange="this.form.submit()"><?php foreach ($countries as $cid => $cn): ?><option value="<?= $cid ?>"<?= $country === $cid ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></form>

<form method="post" enctype="multipart/form-data" class="ad-box ad-form"><?= csrf_field() ?>
  <input type="hidden" name="country" value="<?= $country ?>">
  <h2 class="full" style="margin-top:0">Big picture at the top</h2>
  <?php if ($hero['n']): ?><div class="full"><img src="/admin/banner.php?k=hero&amp;id=<?= (int) $hero['id'] ?>&amp;t=<?= time() ?>" alt="" style="max-height:200px;max-width:100%;border-radius:12px"></div><?php endif; ?>
  <div><label>Big text</label><input type="text" name="header_big_text" value="<?= e($hero['header_big_text']) ?>"></div>
  <div><label>Small text</label><input type="text" name="header_text" value="<?= e($hero['header_text']) ?>"></div>
  <div><label>Button text</label><input type="text" name="button_text" value="<?= e($hero['button_text']) ?>"></div>
  <?= str_replace(['name="button_type"', 'name="button_value"'], ['name="button_action"', 'name="button_value"'], $linkFields((string) $hero['button_action'], (string) $hero['button_value'], 'button')) ?>
  <div><label>New picture (optional)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
  <label class="check full"><input type="checkbox" name="active" value="1"<?= (int) $hero['active'] ? ' checked' : '' ?>> <span>Show it</span></label>
  <div class="full ad-actions"><button class="btn btn-sm" name="action" value="hero">Save</button></div>
</form>

<div class="ad-box">
  <h2 style="margin-top:0">Banners under the big picture</h2>
  <p class="ad-muted">Small pictures (ads) below the big picture. When someone taps a banner, the app opens the page you choose here.</p>
  <?php foreach ($ads as $a): ?>
    <form method="post" class="ad-form" style="border-top:1px solid #edf1ea;padding-top:12px;margin-top:12px"><?= csrf_field() ?>
      <input type="hidden" name="country" value="<?= $country ?>"><input type="hidden" name="ad" value="<?= (int) $a['id'] ?>">
      <div><img src="/admin/banner.php?k=ad&amp;id=<?= (int) $a['id'] ?>" alt="" style="width:100%;max-width:260px;border-radius:10px"></div>
      <?= $linkFields((string) $a['link_type'], (string) $a['link_value'], 'link') ?>
      <div><label>Position (1 = first, 2 = second…)</label><input type="number" name="sort_order" value="<?= (int) $a['sort_order'] ?>"></div>
      <label class="check"><input type="checkbox" name="active" value="1"<?= (int) $a['active'] ? ' checked' : '' ?>> <span>Show it</span></label>
      <div class="full ad-actions"><button class="btn btn-sm" name="action" value="ad_save">Save</button>
        <button class="btn btn-sm btn-outline" name="action" value="ad_delete" onclick="return confirm('Delete this banner?')">Delete</button></div>
    </form>
  <?php endforeach; ?>
  <?php if (!$ads): ?><p class="ad-muted">No banners yet.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="ad-form" style="margin-top:16px;padding:14px;border:2px dashed #cfe0c9;border-radius:14px"><?= csrf_field() ?>
    <input type="hidden" name="country" value="<?= $country ?>">
    <h3 class="full" style="margin:0">Add a banner</h3>
    <div class="full"><label>Picture (best size: 1600 × 800 pixels, wide like 2:1)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></div>
    <?= $linkFields('url', '', 'link') ?>
    <div class="full"><button class="btn" name="action" value="ad_add">Save banner</button></div>
  </form>
</div>
<script>
// Uploading a picture can take a few seconds: show it, and stop double clicks
document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    var b = ev.submitter || f.querySelector('button[name=action]');
    if (!b) return;
    var h = document.createElement('input'); h.type = 'hidden'; h.name = b.name; h.value = b.value; f.appendChild(h);
    setTimeout(function () { b.disabled = true; b.textContent = 'Uploading… one moment'; }, 0);
  });
});
</script>
<?php admin_foot();
