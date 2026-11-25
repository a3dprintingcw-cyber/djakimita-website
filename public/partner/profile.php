<?php
// Partner portal: the partner edits their own page (description, hours, address, contact, photos).
// Name, category, discounts and switching the page on or off stay with the Dj'aki mi ta! team.
require __DIR__ . '/../../app/bootstrap.php';
$u = pp_require();
$cid = (int) $u['company_id'];
$db = db();
$days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_check()) {
            throw new RuntimeException('The page was open too long. Please try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $hours = [];
            foreach ($days as $d) {
                $closed = !empty($_POST['closed'][$d]);
                $open = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['open'][$d] ?? '')) ? $_POST['open'][$d] : '';
                $close = preg_match('/^\d{2}:\d{2}$/', (string) ($_POST['close'][$d] ?? '')) ? $_POST['close'][$d] : '';
                $hours[] = ($closed || $open === '') ? ['day' => $d, 'open' => 'Closed', 'close' => ''] : ['day' => $d, 'open' => $open, 'close' => $close];
            }
            $website = trim((string) $_POST['website']);
            if ($website !== '' && !preg_match('~^https?://~i', $website)) {
                $website = 'https://' . $website;
            }
            $email = trim((string) $_POST['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('That email address does not look right.');
            }
            $db->prepare('UPDATE voucher_company SET description = :d, website = :w, phone = :ph, email = :e, location = :l, opening_hours = :h WHERE id = :id')->execute([
                'd' => mb_substr(trim((string) $_POST['description']), 0, 6000), 'w' => mb_substr($website, 0, 300),
                'ph' => mb_substr(trim((string) $_POST['phone']), 0, 60), 'e' => mb_substr($email, 0, 150),
                'l' => mb_substr(trim((string) $_POST['location']), 0, 300), 'h' => json_encode($hours), 'id' => $cid,
            ]);
            admin_log('partner_self_edit', '#' . $cid . ' ' . $u['company'] . ' by partner login ' . $u['name']);
            pp_flash('Saved. Your page on the website and in the app shows the changes right away.');
        } elseif ($action === 'photo_add') {
            $files = $_FILES['photos'] ?? null;
            $count = is_array($files['name'] ?? null) ? count($files['name']) : 0;
            $have = (int) $db->query('SELECT COUNT(*) FROM voucher_company_images WHERE company_id = ' . $cid)->fetchColumn();
            if ($have + $count > 20) {
                throw new RuntimeException('You can have up to 20 photos. Delete some first.');
            }
            $max = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM voucher_company_images WHERE company_id = ' . $cid)->fetchColumn();
            $ins = $db->prepare('INSERT INTO voucher_company_images (company_id, image_blob, image_type, sort_order) VALUES (:c, :b, :t, :s)');
            $added = 0;
            for ($i = 0; $i < min($count, 10); $i++) {
                [$bytes, $mime] = admin_image_upload(['error' => $files['error'][$i], 'tmp_name' => $files['tmp_name'][$i]]);
                $ins->bindValue('c', $cid);
                $ins->bindValue('b', $bytes, PDO::PARAM_LOB);
                $ins->bindValue('t', $mime);
                $ins->bindValue('s', $max + $i + 1);
                $ins->execute();
                $added++;
            }
            admin_log('partner_self_photos', '#' . $cid . ' +' . $added . ' by ' . $u['name']);
            pp_flash($added ? $added . ' photo' . ($added === 1 ? '' : 's') . ' added.' : 'Pick one or more photos first.');
        } elseif ($action === 'photo_delete') {
            $pid = (int) $_POST['photo'];
            $left = (int) $db->query('SELECT COUNT(*) FROM voucher_company_images WHERE company_id = ' . $cid)->fetchColumn();
            if ($left <= 1) {
                throw new RuntimeException('Keep at least one photo. Add a new one first, then delete this one.');
            }
            $db->prepare('DELETE FROM voucher_company_images WHERE id = :p AND company_id = :c')->execute(['p' => $pid, 'c' => $cid]);
            if (function_exists('clear_image_cache')) {
                clear_image_cache('i', $pid);
            }
            admin_log('partner_self_photo_deleted', '#' . $cid . ' photo ' . $pid . ' by ' . $u['name']);
            pp_flash('Photo deleted.');
        } elseif ($action === 'photo_first') {
            $pid = (int) $_POST['photo'];
            $db->prepare('UPDATE voucher_company_images SET sort_order = sort_order + 1 WHERE company_id = :c')->execute(['c' => $cid]);
            $db->prepare('UPDATE voucher_company_images SET sort_order = 0 WHERE id = :p AND company_id = :c')->execute(['p' => $pid, 'c' => $cid]);
            pp_flash('That photo is now your main photo.');
        }
        header('Location: /partner/profile.php');
        exit;
    } catch (Throwable $e) {
        $err = $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.';
    }
}

$p = $db->prepare('SELECT name, description, website, phone, email, location, opening_hours FROM voucher_company WHERE id = :id');
$p->execute(['id' => $cid]);
$p = $p->fetch();
$hours = [];
foreach ((array) json_decode((string) $p['opening_hours'], true) as $h) {
    if (isset($h['day'])) {
        $hours[$h['day']] = $h;
    }
}
$photos = $db->query('SELECT id FROM voucher_company_images WHERE company_id = ' . $cid . ' ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);

pp_head('My page', $u, 'profile');
?>
<?php if ($err): ?><div class="pp-err" role="alert"><?= e($err) ?></div><?php endif; ?>
<p class="pp-muted" style="margin:0 0 12px">This is what customers see about <?= e($u['company']) ?> on the website and in the app.
  <a href="/partner.php?id=<?= $cid ?>" target="_blank" rel="noopener">See your page ↗</a><br>
  Your name, category and discounts are set by the Dj'aki mi ta! team. To change those, mail contact@djakimita.com.</p>

<form method="post" class="pp-box pp-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <h2>Details</h2>
  <label for="d">Description</label>
  <textarea id="d" name="description" rows="7"><?= e((string) $p['description']) ?></textarea>
  <label for="l">Address</label>
  <input type="text" id="l" name="location" value="<?= e((string) $p['location']) ?>" placeholder="Street and number, area">
  <label for="ph">Phone</label>
  <input type="text" id="ph" name="phone" value="<?= e((string) $p['phone']) ?>" inputmode="tel" placeholder="+599 9 …">
  <label for="em">Email</label>
  <input type="email" id="em" name="email" value="<?= e((string) $p['email']) ?>">
  <label for="w">Website</label>
  <input type="text" id="w" name="website" value="<?= e((string) $p['website']) ?>" placeholder="https://…">
  <label>Opening hours</label>
  <div class="pp-hours">
    <?php foreach ($days as $dn): $h = $hours[$dn] ?? ['open' => 'Closed', 'close' => '']; $closed = ($h['open'] ?? 'Closed') === 'Closed'; ?>
      <div class="r"><b><?= $dn ?></b>
        <input type="time" name="open[<?= $dn ?>]" value="<?= $closed ? '' : e((string) $h['open']) ?>" aria-label="<?= $dn ?> open">
        <input type="time" name="close[<?= $dn ?>]" value="<?= $closed ? '' : e((string) $h['close']) ?>" aria-label="<?= $dn ?> close">
        <label style="margin:0;font-weight:500;display:flex;gap:6px;align-items:center"><input type="checkbox" name="closed[<?= $dn ?>]" value="1"<?= $closed ? ' checked' : '' ?>> Closed</label>
      </div>
    <?php endforeach; ?>
  </div>
  <button class="btn btn-lg" name="action" value="save" style="margin-top:18px">Save details</button>
</form>

<div class="pp-box">
  <h2>Photos</h2>
  <p class="pp-muted" style="margin:0 0 10px">The first photo is your main photo in the list.</p>
  <div class="pp-photos">
    <?php foreach ($photos as $i => $pid): ?>
      <figure><img src="/img.php?i=<?= (int) $pid ?>&amp;s=m" alt="" loading="lazy">
        <figcaption>
          <?php if ($i === 0): ?><span class="pp-tag ok">Main photo</span><?php else: ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="photo" value="<?= (int) $pid ?>"><button class="btn btn-sm btn-outline" name="action" value="photo_first">Make main</button></form><?php endif; ?>
          <form method="post" onsubmit="return confirm('Delete this photo?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="photo" value="<?= (int) $pid ?>"><button class="btn btn-sm btn-outline" name="action" value="photo_delete">Delete</button></form>
        </figcaption></figure>
    <?php endforeach; ?>
  </div>
  <form method="post" enctype="multipart/form-data" class="pp-form" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label for="ph2">Add photos (up to 10 at a time)</label>
    <input type="file" id="ph2" name="photos[]" accept="image/*" multiple>
    <button class="btn" name="action" value="photo_add" style="margin-top:10px">Upload</button>
  </form>
</div>
<?php pp_foot();
