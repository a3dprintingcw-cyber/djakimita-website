<?php
require __DIR__ . '/../app/bootstrap.php';

$orderId = (int) ($_GET['o'] ?? 0);
$sig = (string) ($_GET['s'] ?? '');
if (!order_sig_ok($orderId, $sig)) {
    header('Location: /');
    exit;
}
$rows = order_rows($orderId);
$holders = $rows ? order_holders($orderId) : [];
if (!$holders) {
    header('Location: /');
    exit;
}
$paid = (int) $rows[0]['status'] === 1;
$family = is_family_package((int) $holders[0]['package']);

$needs = fn(array $h) => !$h['v'] || (int) $h['v']['status'] === VERIFY_REJECTED;
// Family card: at least one parent's passport is enough. Is that already covered?
$idCovered = false;
foreach ($holders as $h) {
    if ($h['v'] && ((int) $h['v']['status'] === VERIFY_APPROVED || ((int) $h['v']['status'] === VERIFY_PENDING && $h['v']['id_file']))) {
        $idCovered = true;
    }
}

$errors = [];
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $paid) {
    if (!csrf_check()) {
        $errors[] = t('co.err.session');
    }
    if (empty($_POST['consent'])) {
        $errors[] = t('v.err.consent');
    }
    $todo = [];
    foreach ($holders as $h) {
        if (!$needs($h)) {
            continue;
        }
        $face = $_FILES['face']['error'][$h['id']] ?? UPLOAD_ERR_NO_FILE;
        $idp = $_FILES['idp']['error'][$h['id']] ?? UPLOAD_ERR_NO_FILE;
        if ($face === UPLOAD_ERR_NO_FILE && $idp === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($face === UPLOAD_ERR_NO_FILE) {
            $errors[] = t('v.err.face', ['name' => $h['name']]);
        } elseif (!$family && $idp === UPLOAD_ERR_NO_FILE) {
            $errors[] = t('v.err.id', ['name' => $h['name']]);
        }
        $todo[] = ['h' => $h, 'hasId' => $idp !== UPLOAD_ERR_NO_FILE];
    }
    if (!$todo && !$errors) {
        $errors[] = t('v.err.none');
    }
    if ($family && !$idCovered && $todo && !array_filter($todo, fn($x) => $x['hasId'])) {
        $errors[] = t('v.err.family');
    }

    if (!$errors) {
        $pick = fn(string $field, int $id) => [
            'error' => $_FILES[$field]['error'][$id] ?? UPLOAD_ERR_NO_FILE,
            'tmp_name' => $_FILES[$field]['tmp_name'][$id] ?? '',
            'size' => $_FILES[$field]['size'][$id] ?? 0,
        ];
        $saved = [];
        try {
            foreach ($todo as $x) {
                $id = (int) $x['h']['id'];
                $face = save_photo($pick('face', $id), 900);
                $saved[] = $face;
                $idPhoto = $x['hasId'] ? save_photo($pick('idp', $id), 1800) : null;
                if ($idPhoto) {
                    $saved[] = $idPhoto;
                }
                submit_verification($x['h'], $orderId, $face, $idPhoto);
            }
            header('Location: /verify.php?' . http_build_query(['o' => $orderId, 's' => $sig, 'sent' => 1]));
            exit;
        } catch (InvalidArgumentException $ex) {
            $errors[] = t('v.err.photo');
        } catch (Throwable $ex) {
            error_log('verify.php: ' . $ex->getMessage());
            $errors[] = t('co.err.general');
        }
        // Clean up only files not yet linked to a submission.
        foreach ($saved as $f) {
            $st = db()->prepare('SELECT COUNT(*) FROM holder_verifications WHERE face_file = :f OR id_file = :f2');
            $st->execute(['f' => $f, 'f2' => $f]);
            if (!(int) $st->fetchColumn()) {
                delete_photo($f);
            }
        }
    }
    $holders = order_holders($orderId);
}
$sent = !empty($_GET['sent']);

page_header(t('v.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap narrow">
    <h1 class="co-h1"><?= e(t('v.title')) ?></h1>
    <p class="lead"><?= e(t('v.lead')) ?></p>

    <?php if (!$paid): ?>
      <p class="notice"><?= e(t('v.unpaid')) ?></p>
    <?php else: ?>

    <?php if ($sent): ?>
      <div class="ok-box" role="status"><?= icon('check', 20) ?> <?= e(t('v.sent')) ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert" role="alert"><ul><?php foreach (array_unique($errors) as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <ol class="v-how">
      <li><?= e(t('v.how1')) ?></li>
      <li><?= e(t('v.how2')) ?></li>
      <li><?= e(t('v.how3')) ?></li>
    </ol>

    <form method="post" enctype="multipart/form-data" class="v-form" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php $open = 0; foreach ($holders as $i => $h): $st = $h['v'] ? (int) $h['v']['status'] : -1; ?>
        <div class="v-person">
          <div class="v-head">
            <div>
              <strong><?= e($h['name']) ?></strong>
              <span class="muted small"><?= e(date('d-m-Y', strtotime($h['birth']))) ?></span>
            </div>
            <?php if ($st === VERIFY_APPROVED): ?>
              <span class="tag tag-ok"><?= icon('check', 14) ?> <?= e(t('v.st.ok')) ?></span>
            <?php elseif ($st === VERIFY_PENDING): ?>
              <span class="tag tag-wait"><?= e(t('v.st.wait')) ?></span>
            <?php elseif ($st === VERIFY_REJECTED): ?>
              <span class="tag tag-bad"><?= e(t('v.st.bad')) ?></span>
            <?php else: ?>
              <span class="tag"><?= e(t('v.st.todo')) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($st === VERIFY_REJECTED && $h['v']['note']): ?>
            <p class="v-note"><?= e($h['v']['note']) ?></p>
          <?php endif; ?>
          <?php if ($needs($h)): $open++; ?>
            <div class="v-uploads">
              <label class="v-drop">
                <input type="file" name="face[<?= (int) $h['id'] ?>]" accept="image/*" capture="user" data-max="900">
                <span class="v-drop-in"><?= icon('user', 26) ?><strong><?= e(t('v.face')) ?></strong><span class="muted small"><?= e(t('v.face.t')) ?></span></span>
              </label>
              <label class="v-drop">
                <input type="file" name="idp[<?= (int) $h['id'] ?>]" accept="image/*" capture="environment" data-max="1800">
                <span class="v-drop-in"><?= icon('id', 26) ?><strong><?= e(t('v.id')) ?><?= $family ? ' <span class="muted small">(' . e(t('co.holder.opt')) . ')</span>' : '' ?></strong><span class="muted small"><?= e(t($family ? 'v.id.fam' : 'v.id.t')) ?></span></span>
              </label>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($open): ?>
        <label class="check">
          <input type="checkbox" name="consent" value="1">
          <span><?= e(t('v.consent')) ?></span>
        </label>
        <button class="btn btn-lg btn-block" type="submit"><?= e(t('v.send')) ?> <?= icon('arrow', 18) ?></button>
      <?php endif; ?>
    </form>
    <p class="trust"><?= icon('check', 16) ?> <?= e(t('v.privacy')) ?></p>
    <?php endif; ?>
  </div>
</section>
<script>
// Shrink phone photos before upload: faster on mobile data, same quality for the check.
document.querySelectorAll('.v-drop input[type=file]').forEach(function (input) {
  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    var box = input.closest('.v-drop');
    if (!file) { box.classList.remove('has-file'); return; }
    box.classList.add('has-file');
    var url = URL.createObjectURL(file);
    box.style.setProperty('--preview', 'url("' + url + '")');
    if (!window.DataTransfer || !/^image\//.test(file.type)) { return; }
    var img = new Image();
    img.onload = function () {
      var max = Number(input.dataset.max) || 1600;
      var s = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
      if (s === 1 && file.size < 2e6) { return; }
      var c = document.createElement('canvas');
      c.width = Math.round(img.naturalWidth * s); c.height = Math.round(img.naturalHeight * s);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(function (blob) {
        if (!blob) { return; }
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'photo.jpg', {type: 'image/jpeg'}));
        input.files = dt.files;
      }, 'image/jpeg', 0.85);
    };
    img.src = url;
  });
});
</script>
<?php page_footer();
