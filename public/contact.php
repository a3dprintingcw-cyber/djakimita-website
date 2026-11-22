<?php
// "Send us a message": stored in the backend and emailed to the team (Brevo).
require __DIR__ . '/../app/bootstrap.php';

$f = ['name' => '', 'email' => '', 'message' => ''];
$errors = [];
$sent = !empty($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['name' => 120, 'email' => 190, 'message' => 4000] as $k => $max) {
        $f[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
    }
    if (!csrf_check()) {
        $errors[] = t('co.err.session');
    }
    $bot = bot_check('contact', 4);
    if ($bot === 'bot') {
        header('Location: /contact.php?sent=1');
        exit;
    }
    if ($bot !== '') {
        $errors[] = t($bot);
    }
    if ($f['name'] === '' || $f['message'] === '' || !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('ct.err');
    }
    if (!$errors) {
        db()->prepare('INSERT INTO contact_messages (name, email, message, ip) VALUES (:n, :e, :m, :i)')
            ->execute(['n' => $f['name'], 'e' => $f['email'], 'm' => $f['message'], 'i' => substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 16)]);
        $html = dm_mail('New message from the website',
            '<p><strong>' . e($f['name']) . '</strong> · ' . e($f['email']) . '</p>'
            . '<p style="white-space:pre-wrap;background:#f1f7ef;padding:12px;border-radius:8px">' . e($f['message']) . '</p>'
            . '<p style="color:#4f5d52;font-size:13px">Reply to this email to answer ' . e($f['name']) . ' directly.</p>');
        if (setting('notify_contact', '1') === '1') {
            foreach (staff_emails() as $to) {
                brevo_send($to, 'Website message from ' . $f['name'], $html, ['email' => $f['email'], 'name' => $f['name']]);
            }
        }
        header('Location: /contact.php?sent=1');
        exit;
    }
}

page_header(t('ct.title') . " · Dj'aki mi ta!", 'contact', t('ct.text'));
?>
<section class="section">
  <div class="wrap narrow">
    <h1><?= e(t('ct.title')) ?></h1>
    <p class="lead"><?= e(t('ct.text')) ?></p>
    <?php if ($sent): ?>
      <div class="ok-box" role="status"><?= icon('check', 20) ?> <?= e(t('ct.ok')) ?></div>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert" role="alert"><ul><?php foreach (array_unique($errors) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>
      <form method="post" action="/contact.php" class="box bp-form">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <div class="field-row">
          <div class="field"><label for="name"><?= e(t('ct.name')) ?></label><input id="name" name="name" autocomplete="name" required maxlength="120" value="<?= e($f['name']) ?>"></div>
          <div class="field"><label for="email"><?= e(t('ct.email')) ?></label><input id="email" name="email" type="email" inputmode="email" autocomplete="email" required value="<?= e($f['email']) ?>"></div>
        </div>
        <div class="field"><label for="message"><?= e(t('ct.msg')) ?></label><textarea id="message" name="message" rows="6" required maxlength="4000"><?= e($f['message']) ?></textarea></div>
        <?= bot_fields() ?>
        <button class="btn btn-lg"><?= e(t('ct.send')) ?></button>
      </form>
      <p class="muted small" style="margin-top:14px"><?= icon('mail', 16) ?> contact@djakimita.com</p>
    <?php endif; ?>
  </div>
</section>
<?php page_footer();
