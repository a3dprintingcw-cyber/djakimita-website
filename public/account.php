<?php
require __DIR__ . '/../app/bootstrap.php';

$me = require_login();
$msg = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = t('co.err.session');
    } elseif (($_POST['action'] ?? '') === 'logout') {
        client_logout();
        header('Location: /');
        exit;
    } elseif (($_POST['action'] ?? '') === 'password') {
        $st = db()->prepare('SELECT password FROM clients WHERE id = :id');
        $st->execute(['id' => $me['id']]);
        $p1 = (string) ($_POST['p1'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), (string) $st->fetchColumn())) {
            $error = t('ac.pw.err');
        } elseif (mb_strlen($p1) < 8 || $p1 !== (string) ($_POST['p2'] ?? '')) {
            $error = t('pw.err');
        } else {
            db()->prepare('UPDATE clients SET password = :p WHERE id = :id')->execute(['p' => password_hash($p1, PASSWORD_DEFAULT), 'id' => $me['id']]);
            session_regenerate_id(true);
            $msg = t('ac.pw.ok');
        }
    }
}

$cards = client_cards((int) $me['id']);
$esims = client_esims((int) $me['id']);
$fmt = fn(string $d) => date('d-m-Y', strtotime($d));

page_header(t('ac.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap narrow">
    <h1 class="co-h1"><?= e(t('ac.hi', ['name' => $me['username'] ?: $me['email']])) ?></h1>
    <?php if ($msg): ?><div class="ok-box" role="status"><?= icon('check', 20) ?> <?= e($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>

    <h2 class="h3"><?= e(t('ac.cards')) ?></h2>
    <?php if (!$cards): ?>
      <div class="box"><p><?= e(t('ac.none')) ?></p><a class="btn" href="/#cards"><?= e(t('li.new.cta')) ?> <?= icon('arrow', 18) ?></a></div>
    <?php endif; ?>
    <?php foreach ($cards as $c):
        $needsVerify = false;
        foreach ($c['holders'] as $h) {
            if (!$h['v'] || (int) $h['v']['status'] === VERIFY_REJECTED) {
                $needsVerify = true;
            }
        } ?>
      <article class="acc-card<?= $c['active'] ? '' : ' is-expired' ?>">
        <div class="acc-card-top">
          <img src="/assets/img/logo-mark.png" alt="" width="40" height="40">
          <div>
            <strong><?= e(ucfirst(package_label((int) $c['package_id']))) ?> card</strong>
            <span class="small"><?= e(t($c['active'] ? 'ac.valid' : 'ac.expired', ['date' => $fmt($c['expire'])])) ?></span>
          </div>
          <?php if (!$c['active']): ?><a class="btn btn-sm" href="/#cards"><?= e(t('ac.renew')) ?></a><?php endif; ?>
        </div>
        <?php if ($c['holders']): ?>
          <ul class="acc-holders">
            <?php foreach ($c['holders'] as $h): $st = $h['v'] ? (int) $h['v']['status'] : -1; ?>
              <li><span><?= e($h['name']) ?></span>
                <?php if ($st === VERIFY_APPROVED): ?><span class="tag tag-ok"><?= icon('check', 14) ?> <?= e(t('v.st.ok')) ?></span>
                <?php elseif ($st === VERIFY_PENDING): ?><span class="tag tag-wait"><?= e(t('v.st.wait')) ?></span>
                <?php elseif ($st === VERIFY_REJECTED): ?><span class="tag tag-bad"><?= e(t('v.st.bad')) ?></span>
                <?php else: ?><span class="tag"><?= e(t('v.st.todo')) ?></span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($needsVerify && $c['active'] && $c['order_id']): ?>
          <div class="acc-verify">
            <span><?= icon('shield', 18) ?> <?= e(t('ac.verify.t')) ?></span>
            <a class="btn btn-sm" href="/verify.php?<?= e(http_build_query(['o' => $c['order_id'], 's' => order_sig((int) $c['order_id'])])) ?>"><?= e(t('ac.verify')) ?></a>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>

    <?php foreach ($esims as $s): if (!(int) $s['confirmed']) { continue; } ?>
      <div class="acc-row"><?= icon('wifi', 20) ?> <strong><?= e(t('ac.esim')) ?></strong> <span class="muted"><?= e(t('ac.esim.ok', ['phone' => $s['phone']])) ?></span></div>
    <?php endforeach; ?>

    <div class="box acc-app">
      <h2 class="h3"><?= icon('qr', 22) ?> <?= e(t('ac.app')) ?></h2>
      <p><?= e(t('ac.app.t', ['email' => $me['email']])) ?></p>
      <span class="row-actions">
        <a class="btn btn-sm btn-outline" href="https://apps.apple.com/us/app/deals-with-discounts/id6753038072" rel="noopener">App Store</a>
        <a class="btn btn-sm btn-outline" href="https://play.google.com/store/apps/details?id=com.dealswithdiscout.app" rel="noopener">Google Play</a>
      </span>
    </div>

    <details class="box acc-pw"<?= $error && ($_POST['action'] ?? '') === 'password' ? ' open' : '' ?>>
      <summary class="h3"><?= e(t('ac.pw')) ?></summary>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">
        <div class="field"><label for="cur"><?= e(t('ac.pw.current')) ?></label><input id="cur" name="current" type="password" autocomplete="current-password" required></div>
        <div class="field"><label for="p1"><?= e(t('pw.new')) ?></label><input id="p1" name="p1" type="password" autocomplete="new-password" minlength="8" required><p class="hint"><?= e(t('pw.hint')) ?></p></div>
        <div class="field"><label for="p2"><?= e(t('pw.repeat')) ?></label><input id="p2" name="p2" type="password" autocomplete="new-password" minlength="8" required></div>
        <button class="btn" type="submit"><?= e(t('pw.save')) ?></button>
      </form>
    </details>

    <div class="acc-bottom">
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="btn btn-outline" name="action" value="logout"><?= e(t('ac.logout')) ?></button></form>
      <a class="muted small" href="/deletedata.php"><?= e(t('ac.delete')) ?></a>
    </div>
  </div>
</section>
<?php page_footer();
