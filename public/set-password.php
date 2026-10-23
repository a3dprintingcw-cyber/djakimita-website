<?php
require __DIR__ . '/../app/bootstrap.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$state = 'form';
$clientId = 0;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = db()->prepare('SELECT client_id FROM password_resets WHERE token = :t AND expires_at > NOW() LIMIT 1');
    $st->execute(['t' => hash('sha256', $token)]);
    $clientId = (int) $st->fetchColumn();
}
if (!$clientId) {
    $state = 'bad';
}
$error = '';
if ($state === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string) ($_POST['p1'] ?? '');
    $p2 = (string) ($_POST['p2'] ?? '');
    if (!csrf_check()) {
        $error = t('co.err.session');
    } elseif (mb_strlen($p1) < 8 || $p1 !== $p2) {
        $error = t('pw.err');
    } else {
        db()->prepare('UPDATE clients SET password = :p WHERE id = :id')->execute(['p' => password_hash($p1, PASSWORD_DEFAULT), 'id' => $clientId]);
        db()->prepare('DELETE FROM password_resets WHERE client_id = :id')->execute(['id' => $clientId]);
        $state = 'ok';
    }
}

page_header(t('pw.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap narrow">
    <h1 class="co-h1"><?= e(t('pw.title')) ?></h1>
    <?php if ($state === 'bad'): ?>
      <p class="alert"><?= e(t('pw.bad')) ?></p>
    <?php elseif ($state === 'ok'): ?>
      <p class="notice"><?= e(t('pw.ok')) ?></p>
    <?php else: ?>
      <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
      <form method="post" class="box pw-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <div class="field"><label for="p1"><?= e(t('pw.new')) ?></label><input id="p1" name="p1" type="password" autocomplete="new-password" minlength="8" required aria-describedby="pwh"><p class="hint" id="pwh"><?= e(t('pw.hint')) ?></p></div>
        <div class="field"><label for="p2"><?= e(t('pw.repeat')) ?></label><input id="p2" name="p2" type="password" autocomplete="new-password" minlength="8" required></div>
        <button class="btn btn-lg btn-block" type="submit"><?= e(t('pw.save')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php page_footer();
