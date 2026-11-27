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
        // Visits per account type, so the partner works in the app for every account right away
        $dv = max(1, (int) setting('default_visits', '10'));
        $iv = $db->prepare('INSERT INTO voucher_package_amount (v_company_id, v_package_id, v_amount_total) VALUES (:c, :p, :a)');
        foreach (array_keys($cardTypes) as $pkg) {
            $iv->execute(['c' => $id, 'p' => $pkg, 'a' => $dv]);
        }
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
        case 'visits_fill':
            $dv = max(1, (int) setting('default_visits', '10'));
            $have = $db->prepare('SELECT v_package_id FROM voucher_package_amount WHERE v_company_id = :c AND v_amount_total > 0');
            $have->execute(['c' => $id]);
            $have = array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN));
            $find = $db->prepare('SELECT id FROM voucher_package_amount WHERE v_company_id = :c AND v_package_id = :p ORDER BY id LIMIT 1');
            $n = 0;
            foreach (array_keys($cardTypes) as $pkg) {
                if (in_array($pkg, $have, true)) {
                    continue;
                }
                $find->execute(['c' => $id, 'p' => $pkg]);
                if ($row = $find->fetchColumn()) {
                    $db->prepare('UPDATE voucher_package_amount SET v_amount_total = :a WHERE id = :i')->execute(['a' => $dv, 'i' => $row]);
                } else {
                    $db->prepare('INSERT INTO voucher_package_amount (v_company_id, v_package_id, v_amount_total) VALUES (:c, :p, :a)')->execute(['c' => $id, 'p' => $pkg, 'a' => $dv]);
                }
                $n++;
            }
            admin_log('partner_visits_filled', '#' . $id . ' ' . $n);
            flash($n ? 'Done: ' . $dv . ' visits set for ' . $n . ' account type' . ($n > 1 ? 's' : '') . '. Change the numbers in Partner visits if you like.' : 'All account types already have visits.');
            break;
        case 'video':
            $url = trim((string) $_POST['video_url']);
            if ($url !== '' && !preg_match('~^https?://~i', $url)) {
                $url = 'https://' . $url;
            }
            if (preg_match('~^/assets/media/partners/([A-Za-z0-9._-]+)$~', partner_video($id), $m)) {
                @unlink(public_dir() . '/assets/media/partners/' . $m[1]);
            }
            $db->prepare('INSERT INTO partner_extra (company_id, video_url) VALUES (:c, :v) ON DUPLICATE KEY UPDATE video_url = VALUES(video_url)')
                ->execute(['c' => $id, 'v' => mb_substr($url, 0, 500)]);
            admin_log('partner_video', '#' . $id . ' ' . $p['name']);
            flash($url === '' ? 'Video removed.' : 'Video saved. It shows on the partner page.');
            break;
        case 'video_upload':
            $f = $_FILES['video'] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException('The video is too big for the server (max ' . ini_get('upload_max_filesize') . '). Make it shorter or smaller, or put it on YouTube and paste the link.');
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed. Please try again.');
            }
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true) || strpos($mime, 'video/') !== 0 && $mime !== 'application/octet-stream') {
                throw new RuntimeException('Use an MP4, MOV or WEBM video.');
            }
            $dir = public_dir() . '/assets/media/partners';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                throw new RuntimeException('Could not make the video folder.');
            }
            $name = $id . '-' . bin2hex(random_bytes(4)) . '.' . ($ext === 'mov' ? 'mov' : $ext);
            if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                throw new RuntimeException('Could not save the video.');
            }
            @chmod($dir . '/' . $name, 0644);
            $old = partner_video($id);
            if (preg_match('~^/assets/media/partners/([A-Za-z0-9._-]+)$~', $old, $m)) {
                @unlink($dir . '/' . $m[1]);
            }
            $db->prepare('INSERT INTO partner_extra (company_id, video_url) VALUES (:c, :v) ON DUPLICATE KEY UPDATE video_url = VALUES(video_url)')
                ->execute(['c' => $id, 'v' => '/assets/media/partners/' . $name]);
            admin_log('partner_video', '#' . $id . ' ' . $p['name'] . ' (upload)');
            flash('Video uploaded. It shows on the partner page.');
            break;
        case 'deal_text':
            $dt = mb_substr(trim((string) $_POST['deal_text']), 0, 160);
            $db->prepare('INSERT INTO partner_extra (company_id, deal_text) VALUES (:c, :t) ON DUPLICATE KEY UPDATE deal_text = VALUES(deal_text)')
                ->execute(['c' => $id, 't' => $dt]);
            admin_log('partner_deal_text', '#' . $id . ' ' . $p['name'] . ': ' . $dt);
            flash($dt === '' ? 'Deal text removed.' : 'Saved. The deal shows on the partner page and in the list.');
            break;
        case 'note':
            $note = trim((string) $_POST['note']);
            $db->prepare('INSERT INTO partner_extra (company_id, note) VALUES (:c, :n) ON DUPLICATE KEY UPDATE note = VALUES(note)')
                ->execute(['c' => $id, 'n' => mb_substr($note, 0, 2000)]);
            admin_log('partner_note', '#' . $id . ' ' . $p['name']);
            flash($note === '' ? 'Note removed.' : 'Saved. The note shows under the description.');
            break;
        case 'login_add':
            $u = trim((string) $_POST['username']);
            $pw = (string) $_POST['password'];
            if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $u)) {
                throw new RuntimeException('Username: 3 to 60 letters, numbers, dots or dashes. No spaces.');
            }
            if (mb_strlen($pw) < 8) {
                throw new RuntimeException('The password needs at least 8 characters.');
            }
            $dup = $db->prepare('SELECT id FROM users WHERE LOWER(name) = LOWER(:n)');
            $dup->execute(['n' => $u]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('That username is already taken. Try another one.');
            }
            $db->prepare('INSERT INTO users (name, password, users_type_id, active) VALUES (:n, :p, 2, 1)')
                ->execute(['n' => $u, 'p' => password_hash($pw, PASSWORD_BCRYPT)]);
            $uid = (int) $db->lastInsertId();
            $db->prepare('INSERT INTO users_properties (voucher_company_id, user_id) VALUES (:c, :u)')->execute(['c' => $id, 'u' => $uid]);
            admin_log('partner_login_added', $u . ' for #' . $id . ' ' . $p['name']);
            flash('Login ' . $u . ' made. Give the password to the partner in person or by phone. They can change it themselves after logging in.');
            break;
        case 'login_pw':
            $pw = (string) $_POST['password'];
            if (mb_strlen($pw) < 8) {
                throw new RuntimeException('The password needs at least 8 characters.');
            }
            $db->prepare('UPDATE users u JOIN users_properties up ON up.user_id = u.id AND up.voucher_company_id = :c SET u.password = :p WHERE u.id = :u')
                ->execute(['p' => password_hash($pw, PASSWORD_BCRYPT), 'u' => (int) $_POST['user'], 'c' => $id]);
            admin_log('partner_login_password', 'user #' . (int) $_POST['user'] . ' of #' . $id);
            flash('New password set.');
            break;
        case 'login_toggle':
            $db->prepare('UPDATE users u JOIN users_properties up ON up.user_id = u.id AND up.voucher_company_id = :c SET u.active = 1 - u.active WHERE u.id = :u')
                ->execute(['u' => (int) $_POST['user'], 'c' => $id]);
            admin_log('partner_login_toggled', 'user #' . (int) $_POST['user'] . ' of #' . $id);
            flash('Updated.');
            break;
        case 'delete':
            $used = (int) $db->query('SELECT COUNT(*) FROM client_package_info WHERE company_id = ' . (int) $id)->fetchColumn();
            if ($used > 0) {
                throw new RuntimeException('This partner has been used ' . $used . ' times by account holders, so it cannot be deleted (the history would break). Untick "Show this partner" instead: then nobody sees it.');
            }
            if (trim((string) ($_POST['confirm'] ?? '')) !== trim((string) $p['name'])) {
                throw new RuntimeException('Type the exact partner name to delete it.');
            }
            $db->beginTransaction();
            foreach (['DELETE FROM voucher_company_images WHERE company_id = :c', 'DELETE FROM voucher_company_info_deals WHERE company_id = :c',
                      'DELETE FROM voucher_package_amount WHERE v_company_id = :c', 'DELETE FROM partner_extra WHERE company_id = :c',
                      'DELETE p FROM partner_visit_photos p JOIN partner_visits v ON v.id = p.visit_id WHERE v.company_id = :c',
                      'DELETE FROM partner_visits WHERE company_id = :c',
                      'DELETE u FROM users u JOIN users_properties up ON up.user_id = u.id WHERE up.voucher_company_id = :c AND NOT EXISTS (SELECT 1 FROM users_properties o WHERE o.user_id = u.id AND o.voucher_company_id <> :c2)',
                      'DELETE FROM users_properties WHERE voucher_company_id = :c', 'DELETE FROM voucher_company WHERE id = :c'] as $sql) {
                $params = ['c' => $id];
                if (str_contains($sql, ':c2')) {
                    $params['c2'] = $id;
                }
                $db->prepare($sql)->execute($params);
            }
            $db->commit();
            admin_log('partner_deleted', '#' . $id . ' ' . $p['name']);
            flash($p['name'] . ' is deleted.');
            header('Location: /admin/partners.php');
            exit;
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
            admin_log('partner_deals', '#' . $id . ' ' . $p['name'] . ': ' . $n . ' account types');
            flash('Discounts saved for ' . $n . ' account type' . ($n === 1 ? '' : 's') . '.');
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
    $video = partner_video($id);
    $logins = [];
    try {
        $l = $db->prepare('SELECT u.id, u.name, u.active, u.users_type_id FROM users u JOIN users_properties up ON up.user_id = u.id WHERE up.voucher_company_id = :c ORDER BY u.name');
        $l->execute(['c' => $id]);
        $logins = $l->fetchAll();
    } catch (Throwable $e) {
    }
    $usedCount = 0;
    try {
        $usedCount = (int) $db->query('SELECT COUNT(*) FROM client_package_info WHERE company_id = ' . (int) $id)->fetchColumn();
    } catch (Throwable $e) {
    }

    // Account types without visits can't use this partner (and don't see it in the old store app)
    $missing = [];
    try {
        $hv = $db->prepare('SELECT v_package_id FROM voucher_package_amount WHERE v_company_id = :c AND v_amount_total > 0');
        $hv->execute(['c' => $id]);
        $hv = array_map('intval', $hv->fetchAll(PDO::FETCH_COLUMN));
        foreach ($cardTypes as $pkg => $label) {
            if (!in_array($pkg, $hv, true)) {
                $missing[] = $label;
            }
        }
    } catch (Throwable $e) {
    }

    admin_head($p['name'], 'partners');
    if ($missing): ?>
      <form method="post" class="ad-box" style="background:#fff7dc;border-color:#f0d77a"><?= csrf_field() ?>
        <strong>No visits set for: <?= e(implode(', ', $missing)) ?>.</strong>
        Customers with <?= count($missing) > 1 ? 'these accounts' : 'this account' ?> can't use their discount here.
        <button class="btn btn-sm" name="action" value="visits_fill" style="margin-left:8px">Give them <?= (int) setting('default_visits', '10') ?> visits</button>
        <a href="/admin/freebies.php" style="margin-left:8px">or set numbers in Partner visits</a>
      </form>
    <?php endif;
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
      <h2 style="margin-top:0">Discount per account</h2>
      <p class="ad-muted">Fill in the normal price and the price with the account, or just the % off. Leave an account empty if it gets no discount. 100% means free.</p>
      <div class="ad-wrap"><table class="ad-table" style="max-width:640px">
        <thead><tr><th>Account</th><th>Normal price ($)</th><th>With account ($)</th><th>% off</th></tr></thead>
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
      <form method="post" enctype="multipart/form-data" id="photo-form" style="margin-top:14px;padding:14px;border:2px dashed #cfe0c9;border-radius:14px"><?= csrf_field() ?>
        <strong>Add photos</strong> <span class="ad-muted">(1 or more, JPG or PNG; a wide photo like 1600 × 1200 looks best)</span><br>
        <input type="file" name="photos[]" id="photo-pick" accept="image/jpeg,image/png,image/webp" multiple required style="margin:10px 0">
        <div id="photo-preview" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px"></div>
        <button class="btn" name="action" value="photo_add" id="photo-save">Save photos</button>
      </form>
      <script>
      (function () {
        var pick = document.getElementById('photo-pick'), prev = document.getElementById('photo-preview'), f = document.getElementById('photo-form'), b = document.getElementById('photo-save');
        pick.addEventListener('change', function () {
          prev.innerHTML = '';
          Array.prototype.forEach.call(pick.files, function (file) {
            var im = document.createElement('img'); im.src = URL.createObjectURL(file);
            im.style.cssText = 'height:80px;border-radius:10px;object-fit:cover'; prev.appendChild(im);
          });
          b.textContent = pick.files.length > 1 ? 'Save ' + pick.files.length + ' photos' : 'Save photo';
        });
        f.addEventListener('submit', function () {
          var h = document.createElement('input'); h.type = 'hidden'; h.name = 'action'; h.value = 'photo_add'; f.appendChild(h);
          setTimeout(function () { b.disabled = true; b.textContent = 'Uploading… one moment'; }, 0);
        });
      })();
      </script>
    </div>

    <form method="post" class="ad-box ad-form"><?= csrf_field() ?>
      <h2 class="full" style="margin-top:0">Deal in words</h2>
      <div class="full"><label>For a deal that is not a %, for example "Buy 1, get 1 free" or "Free drink with every lunch". Leave empty if the % says it all.</label>
        <input type="text" name="deal_text" maxlength="160" value="<?= e(partner_deal_text($id)) ?>" placeholder="Buy 1, get 1 free"></div>
      <div class="full ad-actions"><button class="btn btn-sm" name="action" value="deal_text">Save deal</button></div>
    </form>

    <form method="post" class="ad-box ad-form"><?= csrf_field() ?>
      <h2 class="full" style="margin-top:0">Please note</h2>
      <div class="full"><label>Important things customers must know, shown in a yellow box under the description. For example: "Book 24 hours ahead", "Not on Sundays", "Show your ID". Leave empty for none.</label>
        <textarea name="note" rows="3" maxlength="2000"><?= e(partner_note($id)) ?></textarea></div>
      <div class="full ad-actions"><button class="btn btn-sm" name="action" value="note">Save note</button></div>
    </form>

    <form method="post" enctype="multipart/form-data" class="ad-box ad-form"><?= csrf_field() ?>
      <h2 class="full" style="margin-top:0">Video</h2>
      <?php if ($video && $video[0] === '/'): ?><div class="full"><?= video_embed($video) ?></div><?php endif; ?>
      <div class="full"><label>Upload a video file from your phone or computer (MP4 or MOV, max <?= e(ini_get('upload_max_filesize')) ?>)</label>
        <input type="file" name="video" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm,.m4v"></div>
      <div class="full ad-actions"><button class="btn btn-sm" name="action" value="video_upload">Upload video</button></div>
      <p class="full ad-muted" style="margin:6px 0 0">Or paste a link instead:</p>
      <div class="full"><label>YouTube or Vimeo link (shows as a video on the partner page). Instagram or TikTok links show as a button.</label>
        <input type="text" name="video_url" value="<?= e($video && $video[0] === '/' ? '' : $video) ?>" placeholder="https://www.youtube.com/watch?v=…"></div>
      <div class="full ad-actions"><button class="btn btn-sm" name="action" value="video">Save link</button><?php if ($video && $video[0] !== '/'): ?> <a href="<?= e($video) ?>" target="_blank" rel="noopener">Open ↗</a><?php endif; ?><?php if ($video): ?> <button class="btn btn-sm btn-outline" name="action" value="video" onclick="this.form.video_url.value=''">Remove video</button><?php endif; ?></div>
    </form>

    <div class="ad-two">
      <div class="ad-box">
        <h2 style="margin-top:0">QR code for the counter</h2>
        <p class="ad-muted">Customers scan this with the app at the partner. Print it and put it where people can see it.</p>
        <?php if ((int) $p['active']): ?>
          <img src="/admin/qr.php?id=<?= $id ?>" alt="QR code" width="160" height="160" style="display:block;margin:8px 0;image-rendering:pixelated">
          <div class="ad-actions"><a class="btn btn-sm" href="/admin/qr.php?id=<?= $id ?>&amp;download=1">⬇ Download QR</a> <a class="btn btn-sm btn-outline" href="/admin/qr.php?id=<?= $id ?>&amp;poster=1" target="_blank">Print poster</a></div>
        <?php else: ?><p class="ad-muted">Switch the partner on first.</p><?php endif; ?>
      </div>
      <div class="ad-box">
        <h2 style="margin-top:0">Partner logins</h2>
        <p class="ad-muted">For the partner's staff. They log in at <strong>djakimita.com/partner-login.php</strong> to check accounts (QR code, barcode or number), register visits, see their numbers and edit their own page and photos. They can change their own password there. They cannot change discounts or switch their page off.</p>
        <?php foreach ($logins as $lg): ?>
          <div class="ad-actions" style="margin-top:8px">
            <strong><?= e($lg['name']) ?></strong> <?= (int) $lg['active'] ? '<span class="ad-tag ok">Active</span>' : '<span class="ad-tag">Off</span>' ?>
            <form method="post" class="ad-inline"><?= csrf_field() ?><input type="hidden" name="user" value="<?= (int) $lg['id'] ?>"><button class="btn btn-sm btn-outline" name="action" value="login_toggle"><?= (int) $lg['active'] ? 'Switch off' : 'Switch on' ?></button></form>
            <details class="ad-inline"><summary class="btn btn-sm btn-outline">New password</summary>
              <form method="post" class="ad-actions"><?= csrf_field() ?><input type="hidden" name="user" value="<?= (int) $lg['id'] ?>">
                <input class="ad-in" type="text" name="password" minlength="8" placeholder="8+ characters" autocomplete="off" style="width:180px"><button class="btn btn-sm" name="action" value="login_pw">Set</button></form></details>
          </div>
        <?php endforeach; ?>
        <?php if (!$logins): ?><p class="ad-muted">No logins yet.</p><?php endif; ?>
        <details style="margin-top:12px"><summary class="btn btn-sm">+ New login</summary>
          <form method="post" class="ad-form" style="margin-top:10px" autocomplete="off"><?= csrf_field() ?>
            <div><label>Username</label><input type="text" name="username" required autocomplete="off"></div>
            <div><label>Password (8+ characters)</label><input type="text" name="password" minlength="8" required autocomplete="off"></div>
            <div class="full"><button class="btn btn-sm" name="action" value="login_add">Make login</button></div>
          </form></details>
      </div>
    </div>

    <details class="ad-box" style="margin-top:8px"><summary class="btn btn-sm btn-outline">Delete this partner</summary>
      <?php if ($usedCount > 0): ?>
        <p class="ad-muted" style="margin-top:10px">This partner was used <?= $usedCount ?> times by account holders, so it can't be deleted. Untick <strong>Show this partner</strong> at the top instead: then nobody sees it anymore.</p>
      <?php else: ?>
        <form method="post" class="ad-actions" style="margin-top:10px"><?= csrf_field() ?>
          <span class="ad-muted">This removes the partner, its photos, discounts and logins for good. Type the name to confirm:</span>
          <input class="ad-in" type="text" name="confirm" id="del-name" placeholder="<?= e(trim($p['name'])) ?>" style="max-width:260px" autocomplete="off">
          <button class="btn btn-sm btn-outline" name="action" value="delete" id="del-btn" disabled style="color:#8a2216;border-color:#8a2216;opacity:.4">Delete for good</button>
        </form>
        <script>
        (function () {
          var want = <?= json_encode(trim((string) $p['name']), JSON_HEX_TAG | JSON_HEX_APOS) ?>;
          var box = document.getElementById('del-name'), btn = document.getElementById('del-btn');
          box.addEventListener('input', function () { var ok = box.value.trim() === want; btn.disabled = !ok; btn.style.opacity = ok ? 1 : .4; });
          btn.addEventListener('click', function (e) {
            if (!confirm('Are you being clumsy? 😅\n\nYou are about to DELETE "' + want + '" for good: its photos, discounts and partner logins are gone too.\n\nClick OK only if you really mean it.')) { e.preventDefault(); return; }
            if (!confirm('Last chance! Delete "' + want + '" forever? This can NOT be undone.\n\n(Tip: to hide a partner, just untick "Show this partner" at the top.)')) { e.preventDefault(); }
          });
        })();
        </script>
      <?php endif; ?>
    </details>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$country = (int) ($_GET['country'] ?? 1);
$show = (string) ($_GET['show'] ?? 'on');
$cnt = $db->prepare('SELECT SUM(active = 1) a, SUM(active = 0) o FROM voucher_company WHERE country = :co');
$cnt->execute(['co' => $country === 2 ? 2 : 1]);
$cnt = $cnt->fetch();
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
  <input type="hidden" name="show" value="<?= e($show) ?>">
  <button class="btn btn-sm">Search</button>
  <span class="ad-muted"><?= count($list) ?> partners</span>
</form>
<nav class="ad-tabs">
  <?php foreach (['on' => 'On the website (' . (int) $cnt['a'] . ')', 'off' => 'Off / stopped (' . (int) $cnt['o'] . ')', 'all' => 'All'] as $k => $lbl): ?>
    <a href="?country=<?= $country ?>&amp;show=<?= $k ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>" class="<?= $show === $k ? 'on' : '' ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</nav>
<?php if ($show === 'off'): ?><p class="ad-muted">Partners that stopped or are on hold. They are not on the website or in the app. Open one and tick <strong>Show this partner</strong> to put it back.</p><?php endif; ?>
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
