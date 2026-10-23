<?php
// Dj'aki mi ta! staff: review the one-time "born in Curaçao" checks.
require __DIR__ . '/../../app/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$reasons = [
    'unreadable' => 'The passport photo is not readable. Please take a new photo in good light, with all text sharp.',
    'birthplace' => 'The passport does not show Curaçao as the place of birth.',
    'face' => 'The selfie is not clear. Please take a new one with your face fully visible.',
    'mismatch' => 'The name or date of birth does not match the passport.',
    'missing' => 'We need the passport page of at least one parent born in Curaçao.',
];

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $msg = 'Session expired, please try again.';
    } elseif (($_POST['action'] ?? '') === 'login') {
        if (!admin_login(trim((string) ($_POST['user'] ?? '')), (string) ($_POST['pass'] ?? ''))) {
            $msg = 'Wrong username or password.';
        }
    } elseif (($_POST['action'] ?? '') === 'logout') {
        unset($_SESSION['admin']);
        session_regenerate_id(true);
    } elseif (admin_user() && in_array($_POST['action'] ?? '', ['approve', 'reject'], true)) {
        $approve = $_POST['action'] === 'approve';
        $note = $approve ? '' : trim(($reasons[$_POST['reason'] ?? ''] ?? '') . ' ' . trim((string) ($_POST['note'] ?? '')));
        if (!$approve && $note === '') {
            $msg = 'Pick a reason so the customer knows what to fix.';
        } else {
            $v = review_verification((int) ($_POST['id'] ?? 0), $approve, admin_user(), $note);
            if ($v) {
                notify_if_order_reviewed((int) $v['order_id']);
                $msg = ($approve ? 'Approved: ' : 'Sent back: ') . $v['name'];
            }
        }
    }
    $_SESSION['admin_msg'] = $msg;
    header('Location: /admin/');
    exit;
}
$msg = $_SESSION['admin_msg'] ?? '';
unset($_SESSION['admin_msg']);

/** When nothing of an order is waiting anymore, email the customer the result once. */
function notify_if_order_reviewed(int $orderId): void
{
    $st = db()->prepare('SELECT * FROM holder_verifications WHERE order_id = :o');
    $st->execute(['o' => $orderId]);
    $all = $st->fetchAll();
    if (!$all || array_filter($all, fn($v) => (int) $v['status'] === VERIFY_PENDING)) {
        return;
    }
    $em = db()->prepare('SELECT email FROM sale_receits WHERE id = :o');
    $em->execute(['o' => $orderId]);
    $email = (string) $em->fetchColumn();
    if ($email === '') {
        return;
    }
    $ok = array_filter($all, fn($v) => (int) $v['status'] === VERIFY_APPROVED);
    $bad = array_filter($all, fn($v) => (int) $v['status'] === VERIFY_REJECTED);
    $body = '';
    if ($ok) {
        $body .= '<p><strong>Verified:</strong> ' . e(implode(', ', array_column($ok, 'name'))) . '. You can now scan at every partner, no passport needed.</p>';
    }
    foreach ($bad as $v) {
        $body .= '<p><strong>' . e($v['name']) . ':</strong> ' . e((string) $v['note']) . '</p>';
    }
    if ($bad) {
        $body .= '<p><a href="' . e(verify_link($orderId)) . '" style="display:inline-block;background:#187c19;color:#fff;padding:10px 20px;border-radius:999px;text-decoration:none;font-weight:bold">Send new photos</a></p>';
    }
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#17221a">'
        . '<div style="background:#187c19;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">Dj\'aki mi ta!</div>'
        . '<div style="border:1px solid #dfe7dc;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">' . ($bad ? 'Your card check: one more thing' : 'Your card is verified') . '</h1>'
        . $body . '<p style="color:#4f5d52;font-size:13px">We deleted the passport photos after the check. Questions? contact@djakimita.com</p></div></div>';
    brevo_send($email, $bad ? "Dj'aki mi ta!: please send a new photo" : "Dj'aki mi ta!: your card is verified", $html);
}

$user = admin_user();
$queue = [];
$recent = [];
if ($user) {
    $queue = db()->query('SELECT v.*, c.email, s.package FROM holder_verifications v
                          JOIN clients c ON c.id = v.client_id
                          LEFT JOIN sale_receits s ON s.id = v.order_id
                          WHERE v.status = 0 ORDER BY v.submitted_at, v.order_id, v.id')->fetchAll();
    $recent = db()->query('SELECT name, status, note, reviewed_at, reviewed_by FROM holder_verifications
                           WHERE status <> 0 ORDER BY reviewed_at DESC LIMIT 15')->fetchAll();
}
$groups = [];
foreach ($queue as $v) {
    $groups[$v['order_id']][] = $v;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Card checks · Dj'aki mi ta! admin</title>
  <link rel="icon" href="/assets/img/logo-mark.png">
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/site.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/site.css') ?>">
</head>
<body class="admin">
<header class="nav">
  <div class="wrap nav-in">
    <span class="brand"><img src="/assets/img/logo-mark.png" alt="" width="40" height="40"><span class="brand-name">Card checks</span></span>
    <?php if ($user): ?>
      <form method="post" class="nav-right"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <span class="muted small"><?= e($user) ?></span>
        <button class="btn btn-sm btn-outline" name="action" value="logout">Log out</button></form>
    <?php endif; ?>
  </div>
</header>
<main class="section pt-sm" id="main">
  <div class="wrap">
    <?php if ($msg): ?><p class="notice"><?= e($msg) ?></p><?php endif; ?>

    <?php if (!$user): ?>
      <form method="post" class="admin-login">
        <h1 class="h3">Staff login</h1>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="login">
        <div class="field"><label for="u">Username</label><input id="u" name="user" autocomplete="username" required></div>
        <div class="field"><label for="p">Password</label><input id="p" name="pass" type="password" autocomplete="current-password" required></div>
        <button class="btn btn-block">Log in</button>
      </form>
    <?php else: ?>
      <h1 class="co-h1">Waiting for a check <span class="count"><?= count($queue) ?></span></h1>
      <p class="muted">Compare the passport with the name and birthdate, check the place of birth says Curaçao, and that the selfie is a clear face. The passport photo is deleted as soon as you click Approve or Send back.</p>

      <?php if (!$groups): ?>
        <div class="ok-box"><?= icon('check', 20) ?> All done. Nothing waiting.</div>
      <?php endif; ?>

      <?php foreach ($groups as $orderId => $people): $fam = is_family_package((int) $people[0]['package']); ?>
        <section class="rv-order">
          <h2 class="h3">Order #<?= (int) $orderId ?> <span class="muted small"><?= e($people[0]['email']) ?> · <?= e(package_label((int) $people[0]['package'])) ?> card</span></h2>
          <?php if ($fam): ?><p class="hint">Family card: children can be approved when a parent's passport shows Curaçao.</p><?php endif; ?>
          <?php foreach ($people as $v): ?>
            <article class="rv-person">
              <div class="rv-photos">
                <figure><img src="/admin/photo.php?f=<?= e($v['face_file']) ?>" alt="Selfie of <?= e($v['name']) ?>" loading="lazy"><figcaption>Selfie</figcaption></figure>
                <?php if ($v['id_file']): ?>
                  <figure class="rv-id"><a href="/admin/photo.php?f=<?= e($v['id_file']) ?>" target="_blank"><img src="/admin/photo.php?f=<?= e($v['id_file']) ?>" alt="Passport of <?= e($v['name']) ?>" loading="lazy"></a><figcaption>Passport (click to enlarge)</figcaption></figure>
                <?php else: ?>
                  <figure class="rv-noid"><span>No passport sent</span></figure>
                <?php endif; ?>
              </div>
              <div class="rv-info">
                <p class="rv-name"><?= e($v['name']) ?></p>
                <p>Born <strong><?= e(date('d-m-Y', strtotime($v['birthdate']))) ?></strong> · age <?= (int) date_diff(date_create($v['birthdate']), date_create('today'))->y ?></p>
                <p class="muted small">Sent <?= e(date('d-m-Y H:i', strtotime($v['submitted_at']))) ?></p>
                <form method="post" class="rv-actions">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                  <button class="btn" name="action" value="approve"><?= icon('check', 18) ?> Approve</button>
                  <details>
                    <summary class="btn btn-outline">Send back</summary>
                    <div class="rv-reject">
                      <?php foreach ($reasons as $k => $text): ?>
                        <label class="check"><input type="radio" name="reason" value="<?= e($k) ?>"> <span><?= e($text) ?></span></label>
                      <?php endforeach; ?>
                      <div class="field"><label for="n<?= (int) $v['id'] ?>">Extra note (optional, the customer sees this)</label><input id="n<?= (int) $v['id'] ?>" name="note" maxlength="120"></div>
                      <button class="btn btn-danger" name="action" value="reject">Send back to customer</button>
                    </div>
                  </details>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <?php if ($recent): ?>
        <h2 class="h3 mt">Recently checked</h2>
        <table class="rv-recent">
          <thead><tr><th>Name</th><th>Result</th><th>By</th><th>When</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr><td><?= e($r['name']) ?></td>
                <td><?= (int) $r['status'] === VERIFY_APPROVED ? '<span class="tag tag-ok">Approved</span>' : '<span class="tag tag-bad">Sent back</span>' ?></td>
                <td><?= e($r['reviewed_by']) ?></td><td><?= e(date('d-m H:i', strtotime((string) $r['reviewed_at']))) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
