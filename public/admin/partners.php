<?php
// Staff backend: partners (shops/activities), their photos and their discounts per card type.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$id = (int) ($_GET['id'] ?? 0);
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$cardTypes = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior'];
$countries = [1 => "Dj'aki mi ta! (Curaçao)", 2 => "Rib'e luga! (Netherlands)"];

admin_post(function () use (&$id, $db, $days, $cardTypes) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the partner a name.');
        }
        $db->prepare("INSERT INTO voucher_company (name, description, website, phone, email, opening_hours, location, category_id, additional_category_ids, point_accumulation, country, active)
                      VALUES (:n, '', '', '', '', '[]', '', 0, '[]', 0, :c, 0)")->execute(['n' => mb_substr($name, 0, 150), 'c' => (int) ($_POST['country'] ?? 1) === 2 ? 2 : 1]);
        $id = (int) $db->lastInsertId();
        admin_log('partner_created', '#' . $id . ' ' . $name);
        flash('Partner added. Fill in the details, add photos and discounts, then switch it on.');
        header('Location: /admin/partners.php?id=' . $id);
        exit;
    }
    $p = $db->prepare('SELECT id, name FROM voucher_company WHERE id = :id');
    $p->execute(['id' => $id]);
    $p = $p->fetch();
    if (!$p) {
        throw new RuntimeException('Partner not found');
    }
    switch ($action) {
        case 'save':
            $name = trim((string) $_POST['name']);
            if ($name === '') {
                throw new RuntimeException('The name cannot be empty.');
            }
            $hours = [];
            foreach ($days as $d) {
                $closed = !empty($_POST['closed'][$d]);
                $open = (string) ($_POST['open'][$d] ?? '');
                $close = (string) ($_POST['close'][$d] ?? '');
                $hours[] = ($closed || $open === '') ? ['day' => $d, 'open' => 'Closed', 'close' => ''] : ['day' => $d, 'open' => $open, 'close' => $close];
            }
            $main = (int) ($_POST['category_id'] ?? 0);
            $extra = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['extra'] ?? [])), fn($c) => $c > 0 && $c !== $main)));
            $website = trim((string) $_POST['website']);
            if ($website !== '' && !preg_match('~^https?://~i', $website)) {
                $website = 'https://' . $website;
            }
            $db->prepare('UPDATE voucher_company SET name = :n, description = :d, website = :w, phone = :ph, email = :e, location = :l,
                          opening_hours = :h, category_id = :c, additional_category_ids = :x, country = :co, active = :a WHERE id = :id')->execute([
                'n' => mb_substr($name, 0, 150), 'd' => trim((string) $_POST['description']), 'w' => mb_substr($website, 0, 300),
                'ph' => mb_substr(trim((string) $_POST['phone']), 0, 60), 'e' => mb_substr(trim((string) $_POST['email']), 0, 150),
                'l' => mb_substr(trim((string) $_POST['location']), 0, 300), 'h' => json_encode($hours), 'c' => $main,
                'x' => json_encode($extra), 'co' => (int) $_POST['country'] === 2 ? 2 : 1, 'a' => empty($_POST['active']) ? 0 : 1, 'id' => $id,
            ]);
            admin_log('partner_saved', '#' . $id . ' ' . $name . (empty($_POST['active']) ? ' (off)' : ' (on)'));
            flash('Saved. The website shows the changes right away.');
            break;
        case 'deals':
            $db->beginTransaction();
            $db->prepare('DELETE FROM voucher_company_info_deals WHERE company_id = :id')->execute(['id' => $id]);
            $ins = $db->prepare('INSERT INTO voucher_company_info_deals (company_id, voucher_id, was, after, the_percent) VALUES (:c, :v, :w, :a, :p)');
            $n = 0;
            foreach ($cardTypes as $pkg => $label) {
                $was = (float) str_replace(',', '.', (string) ($_POST['was'][$pkg] ?? ''));
                $after = (float) str_replace(',', '.', (string) ($_POST['after'][$pkg] ?? ''));
                $pct = (float) str_replace(',', '.', (string) ($_POST['pct'][$pkg] ?? ''));
                if ($was <= 0 && $pct <= 0) {
                    continue; // no discount for this card
                }
                if ($pct <= 0 && $was > 0) {
                    $pct = round(($was - $after) / $was * 100, 2);
                }
                if ($after <= 0 && $was > 0) {
                    $after = round($was * (1 - $pct / 100), 2);
                }
                $ins->execute(['c' => $id, 'v' => $pkg, 'w' => $was, 'a' => $after, 'p' => max(0, min(100, $pct))]);
                $n++;
            }
            $db->commit();
            admin_log('partner_deals', '#' . $id . ' ' . $p['name'] . ': ' . $n . ' card types');
            flash('Discounts saved for ' . $n . ' card type' . ($n === 1 ? '' : 's') . '.');
            break;
        case 'photo_add':
            $files = $_FILES['photos'] ?? null;
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            $max = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM voucher_company_images WHERE company_id = ' . $id)->fetchColumn();
            $ins = $db->prepare('INSERT INTO voucher_company_images (company_id, image_blob, image_type, sort_order) VALUES (:c, :b, :t, :s)');
            $added = 0;
            for ($i = 0; $i < min($count, 12); $i++) {
                [$bytes, $mime] = admin_image_upload(['error' => $files['error'][$i], 'tmp_name' => $files['tmp_name'][$i]]);
                $ins->bindValue('c', $id);
                $ins->bindValue('b', $bytes, PDO::PARAM_LOB);
                $ins->bindValue('t', $mime);
                $ins->bindValue('s', $max + $i + 1);
                $ins->execute();
                $added++;
            }
            admin_log('partner_photos_added', '#' . $id . ': ' . $added);
            flash($added ? $added . ' photo' . ($added === 1 ? '' : 's') . ' added.' : 'Pick one or more photos first.', $added ? 'ok' : 'bad');
            break;
        case 'photo_delete':
            $pid = (int) $_POST['photo'];
            $db->prepare('DELETE FROM voucher_company_images WHERE id = :p AND company_id = :c')->execute(['p' => $pid, 'c' => $id]);
            clear_image_cache('i', $pid);
            admin_log('partner_photo_deleted', '#' . $id . ' photo ' . $pid);
            flash('Photo deleted.');
            break;
        case 'photo_first':
            $pid = (int) $_POST['photo'];
            $db->prepare('UPDATE voucher_company_images SET sort_order = sort_order + 1 WHERE company_id = :c')->execute(['c' => $id]);
            $db->prepare('UPDATE voucher_company_images SET sort_order = 0 WHERE id = :p AND company_id = :c')->execute(['p' => $pid, 'c' => $id]);
            admin_log('partner_photo_first', '#' . $id . ' photo ' . $pid);
            flash('That photo is now the main photo.');
            break;
    }
}, '/admin/partners.php' . ($id ? '?id=' . $id : ''));

$cats = $db->query('SELECT id, TRIM(name) name, active FROM category ORDER BY name')->fetchAll();

// ---------------------------------------------------------------------------
// One partner
// ---------------------------------------------------------------------------
if ($id) {
    $p = $db->prepare('SELECT id, name, description, website, phone, email, opening_hours, location, category_id, additional_category_ids, country, active FROM voucher_company WHERE id = :id');
    $p->execute(['id' => $id]);
    $p = $p->fetch();
    if (!$p) {
        http_response_code(404);
        admin_head('Partner not found', 'partners');
        echo '<h1>Partner not found</h1><p><a href="/admin/partners.php">← Partners</a></p>';
        admin_foot();
        exit;
    }
    $hours = [];
    foreach ((array) json_decode((string) $p['opening_hours'], true) as $h) {
        if (isset($h['day'])) {
            $hours[$h['day']] = $h;
        }
    }
    $extra = array_map('intval', (array) json_decode((string) $p['additional_category_ids'], true));
    $photos = $db->prepare('SELECT id FROM voucher_company_images WHERE company_id = :id ORDER BY sort_order, id');
    $photos->execute(['id' => $id]);
    $photos = array_column($photos->fetchAll(), 'id');
    $deals = [];
    $d = $db->prepare('SELECT voucher_id, was, after, the_percent FROM voucher_company_info_deals WHERE company_id = :id');
    $d->execute(['id' => $id]);
    foreach ($d->fetchAll() as $row) {
        $deals[(int) $row['voucher_id']] = $row;
    }
    $num = fn($v) => $v === null || (float) $v == 0.0 ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

    admin_head($p['name'], 'partners');
    ?>
    <p><a href="/admin/partners.php">← Partners</a><?php if ((int) $p['active'] && (int) $p['country'] === 1): ?> · <a href="/partner.php?id=<?= $id ?>" target="_blank" rel="noopener">View on the website ↗</a><?php endif; ?></p>
    <h1><?= e($p['name']) ?> <?= (int) $p['active'] ? '<span class="ad-tag ok">On the website</span>' : '<span class="ad-tag">Off</span>' ?></h1>

    <form method="post" class="ad-box ad-form"><?= csrf_field() ?>
      <h2 class="full" style="margin-top:0">Details</h2>
      <div><label>Name</label><input type="text" name="name" value="<?= e(trim($p['name'])) ?>" required></div>
      <div><label>Shown in</label><select name="country"><?php foreach ($countries as $cid => $cn): ?><option value="<?= $cid ?>"<?= (int) $p['country'] === $cid ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
      <div><label>Main category</label><select name="category_id"><option value="0">–</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $p['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?><?= (int) $c['active'] ? '' : ' (hidden)' ?></option><?php endforeach; ?></select></div>
      <div class="full"><label>Also show under</label>
        <div style="display:flex;flex-wrap:wrap;gap:4px 16px"><?php foreach ($cats as $c): if (!(int) $c['active']) { continue; } ?>
          <label class="check" style="font-weight:500"><input type="checkbox" name="extra[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $extra, true) ? ' checked' : '' ?>> <span><?= e($c['name']) ?></span></label>
        <?php endforeach; ?></div></div>
      <div class="full"><label>Description (shown on the partner page)</label><textarea name="description" rows="7"><?= e((string) $p['description']) ?></textarea></div>
      <div><label>Address / location</label><input type="text" name="location" value="<?= e((string) $p['location']) ?>"></div>
      <div><label>Phone</label><input type="text" name="phone" value="<?= e((string) $p['phone']) ?>"></div>
      <div><label>Email</label><input type="email" name="email" value="<?= e((string) $p['email']) ?>"></div>
      <div><label>Website</label><input type="text" name="website" value="<?= e((string) $p['website']) ?>"></div>
      <div class="full"><label>Opening hours</label>
        <div class="ad-hours">
          <span></span><span class="ad-muted">Open</span><span class="ad-muted">Close</span><span></span>
          <?php foreach ($days as $dname): $h = $hours[$dname] ?? ['open' => 'Closed', 'close' => '']; $isClosed = ($h['open'] ?? 'Closed') === 'Closed'; ?>
            <strong><?= $dname ?></strong>
            <input class="ad-in" type="time" name="open[<?= $dname ?>]" value="<?= $isClosed ? '' : e($h['open']) ?>">
            <input class="ad-in" type="time" name="close[<?= $dname ?>]" value="<?= $isClosed ? '' : e($h['close']) ?>">
            <label class="check" style="font-weight:500"><input type="checkbox" name="closed[<?= $dname ?>]" value="1"<?= $isClosed ? ' checked' : '' ?>> <span>Closed</span></label>
          <?php endforeach; ?>
        </div></div>
      <label class="check full"><input type="checkbox" name="active" value="1"<?= (int) $p['active'] ? ' checked' : '' ?>> <span><strong>Show this partner</strong> on the website and in the app</span></label>
      <div class="full ad-actions"><button class="btn" name="action" value="save">Save details</button></div>
    </form>

    <form method="post" class="ad-box"><?= csrf_field() ?>
      <h2 style="margin-top:0">Discount per card</h2>
      <p class="ad-muted">Fill in the normal price and the price with the card, or just the % off. Leave a card empty if it gets no discount. 100% means free.</p>
      <div class="ad-wrap"><table class="ad-table" style="max-width:640px">
        <thead><tr><th>Card</th><th>Normal price</th><th>With card</th><th>% off</th></tr></thead>
        <tbody><?php foreach ($cardTypes as $pkg => $label): $dl = $deals[$pkg] ?? null; ?>
          <tr><td><strong><?= e($label) ?></strong></td>
            <td><input class="ad-in" name="was[<?= $pkg ?>]" inputmode="decimal" value="<?= e($num($dl['was'] ?? null)) ?>"></td>
            <td><input class="ad-in" name="after[<?= $pkg ?>]" inputmode="decimal" value="<?= e($num($dl['after'] ?? null)) ?>"></td>
            <td><input class="ad-in" name="pct[<?= $pkg ?>]" inputmode="decimal" value="<?= e($num($dl['the_percent'] ?? null)) ?>"></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <div class="ad-actions"><button class="btn btn-sm" name="action" value="deals">Save discounts</button></div>
    </form>

    <div class="ad-box">
      <h2 style="margin-top:0">Photos</h2>
      <p class="ad-muted">The first photo is the one on the partner list. Big photos are made smaller by themselves.</p>
      <div class="ad-photos">
        <?php foreach ($photos as $i => $ph): ?>
          <div class="ad-photo">
            <img src="/img.php?i=<?= (int) $ph ?>&amp;s=s&amp;v=<?= (int) $ph ?>" alt="" loading="lazy">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="photo" value="<?= (int) $ph ?>">
              <?php if ($i > 0): ?><button name="action" value="photo_first">★ Make main</button><?php else: ?><span class="ad-tag ok" style="margin:4px">Main</span><?php endif; ?>
              <button name="action" value="photo_delete" onclick="return confirm('Delete this photo?')">Delete</button></form>
          </div>
        <?php endforeach; ?>
        <?php if (!$photos): ?><p class="ad-muted">No photos yet.</p><?php endif; ?>
      </div>
      <form method="post" enctype="multipart/form-data" class="ad-actions"><?= csrf_field() ?>
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
        <button class="btn btn-sm" name="action" value="photo_add">Upload</button>
      </form>
    </div>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$country = (int) ($_GET['country'] ?? 1);
$show = (string) ($_GET['show'] ?? '');
$where = ['v.country = :co'];
$params = ['co' => $country === 2 ? 2 : 1];
if ($q !== '') {
    $where[] = 'v.name LIKE :q';
    $params['q'] = '%' . $q . '%';
}
if ($show === 'on') {
    $where[] = 'v.active = 1';
} elseif ($show === 'off') {
    $where[] = 'v.active = 0';
}
$st = $db->prepare('SELECT v.id, TRIM(v.name) name, v.active, TRIM(c.name) category,
        (SELECT COUNT(*) FROM voucher_company_images i WHERE i.company_id = v.id) photos,
        (SELECT MAX(the_percent) FROM voucher_company_info_deals d WHERE d.company_id = v.id) best,
        (SELECT i.id FROM voucher_company_images i WHERE i.company_id = v.id ORDER BY i.sort_order, i.id LIMIT 1) photo
    FROM voucher_company v LEFT JOIN category c ON c.id = v.category_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY v.active DESC, v.name');
$st->execute($params);
$list = $st->fetchAll();

admin_head('Partners', 'partners');
?>
<h1>Partners</h1>
<form class="ad-filter" method="get">
  <input name="q" value="<?= e($q) ?>" placeholder="Search by name">
  <select name="country"><?php foreach ($countries as $cid => $cn): ?><option value="<?= $cid ?>"<?= $country === $cid ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select>
  <select name="show"><option value="">On and off</option><option value="on"<?= $show === 'on' ? ' selected' : '' ?>>Only on</option><option value="off"<?= $show === 'off' ? ' selected' : '' ?>>Only off</option></select>
  <button class="btn btn-sm">Search</button>
  <span class="ad-muted"><?= count($list) ?> partners</span>
</form>
<details class="ad-box">
  <summary class="btn btn-sm">+ Add a partner</summary>
  <form method="post" class="ad-form" style="margin-top:12px"><?= csrf_field() ?>
    <div><label>Name</label><input type="text" name="name" required></div>
    <div><label>Shown in</label><select name="country"><?php foreach ($countries as $cid => $cn): ?><option value="<?= $cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="full"><button class="btn btn-sm" name="action" value="create">Add and fill in details</button></div>
  </form>
</details>
<div class="ad-wrap"><table class="ad-table">
  <thead><tr><th></th><th>Partner</th><th>Category</th><th>Best discount</th><th>Photos</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($list as $v): ?>
    <tr><td style="width:64px"><?php if ($v['photo']): ?><img src="/img.php?i=<?= (int) $v['photo'] ?>&amp;s=s" alt="" width="56" height="40" style="object-fit:cover;border-radius:6px" loading="lazy"><?php endif; ?></td>
      <td><a href="/admin/partners.php?id=<?= (int) $v['id'] ?>"><?= e($v['name']) ?></a></td>
      <td><?= e((string) $v['category']) ?></td>
      <td><?= $v['best'] !== null ? (int) round((float) $v['best']) . '%' : '<span class="ad-tag bad">none</span>' ?></td>
      <td><?= (int) $v['photos'] ?: '<span class="ad-tag bad">0</span>' ?></td>
      <td><?= (int) $v['active'] ? '<span class="ad-tag ok">On</span>' : '<span class="ad-tag">Off</span>' ?></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php admin_foot();
