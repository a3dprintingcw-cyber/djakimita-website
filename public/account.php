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
    <div class="acc-head"><h1 class="co-h1"><?= e(t('ac.hi', ['name' => $me['username'] ?: $me['email']])) ?></h1><span class="acc-lang"><span class="muted small"><?= e(t('ac.lang')) ?></span> <?= lang_switch() ?></span></div>
    <style>.acc-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:10px}.acc-lang{display:inline-flex;align-items:center;gap:8px}.acc-lang .lang{display:inline-flex;gap:4px}.acc-lang .lang a{padding:4px 10px;border-radius:999px;border:1px solid var(--line,#d9e2d5);text-decoration:none;font-weight:700;font-size:.85rem}.acc-lang .lang a.on{background:var(--green,#187c19);color:#fff;border-color:transparent}</style>
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

    <?php if (wheel_on() && array_filter($cards, fn($c) => $c['active'])):
        $prizes = wheel_prizes();
        $next = wheel_next((int) $me['id']);
        $colors = ['#187c19', '#e8b84a', '#2f9e44', '#f2d27a', '#0f5c12', '#f6c453', '#3a8f3d', '#ffe39a'];
        $n = count($prizes);
        $stops = [];
        foreach ($prizes as $i => $p) {
            $stops[] = $colors[$i % count($colors)] . ' ' . round(360 * $i / $n, 3) . 'deg ' . round(360 * ($i + 1) / $n, 3) . 'deg';
        } ?>
      <div class="box wheel-box">
        <h2 class="h3"><?= e(t('w.title')) ?></h2>
        <p class="muted"><?= e(t('w.text', ['days' => wheel_every_days()])) ?></p>
        <div class="wheel-wrap">
          <div class="wheel-pin" aria-hidden="true"></div>
          <div class="wheel" id="wheel" style="background:conic-gradient(<?= e(implode(', ', $stops)) ?>)" data-n="<?= $n ?>">
            <?php foreach ($prizes as $i => $p): ?>
              <span style="transform:rotate(<?= round(360 * ($i + 0.5) / $n - 90, 3) ?>deg)"><b><?= e(mb_strimwidth($p['label'], 0, 22, '…')) ?></b></span>
            <?php endforeach; ?>
          </div>
        </div>
        <p class="wheel-result" id="wheel-result" aria-live="polite"><?= $next ? e(t('w.next', ['date' => date('d-m-Y', strtotime($next))])) : '' ?></p>
        <button class="btn" type="button" id="wheel-btn"<?= $next ? ' disabled' : '' ?>><?= e(t('w.spin')) ?></button>
      </div>
      <style>
        .wheel-box{text-align:center}
        .wheel-wrap{position:relative;width:min(300px,80vw);aspect-ratio:1;margin:10px auto 14px}
        .wheel{width:100%;height:100%;border-radius:50%;border:6px solid #fff;box-shadow:0 8px 24px rgba(0,0,0,.18);position:relative;transition:transform 4.2s cubic-bezier(.17,.67,.12,1)}
        .wheel span{position:absolute;left:50%;top:50%;width:50%;height:0;transform-origin:0 0}
        .wheel b{position:absolute;right:10%;top:0;transform:translateY(-50%);max-width:72%;overflow:hidden;text-overflow:ellipsis;color:#fff;font-size:.74rem;font-weight:800;text-shadow:0 1px 2px rgba(0,0,0,.55);white-space:nowrap}
        .wheel-pin{position:absolute;left:50%;top:-6px;transform:translateX(-50%);width:0;height:0;border-left:14px solid transparent;border-right:14px solid transparent;border-top:24px solid #b3261e;z-index:2;filter:drop-shadow(0 2px 2px rgba(0,0,0,.3))}
        .wheel-result{font-weight:700;min-height:1.4em}
        .wheel-result.win{color:#187c19;font-size:1.2rem}
      </style>
      <script>
      (function () {
        var btn = document.getElementById('wheel-btn'), wheel = document.getElementById('wheel'), out = document.getElementById('wheel-result'), turns = 0;
        btn.addEventListener('click', function () {
          btn.disabled = true; out.textContent = ''; out.className = 'wheel-result';
          var fd = new FormData(); fd.append('csrf', <?= json_encode(csrf_token()) ?>);
          fetch('/wheel-spin.php', {method: 'POST', body: fd}).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { out.textContent = d.error || ''; return; }
            var n = +wheel.dataset.n, slice = 360 / n, target = 360 - (d.index + 0.5) * slice + (Math.random() - 0.5) * slice * 0.6;
            turns += 5; wheel.style.transform = 'rotate(' + (turns * 360 + target) + 'deg)';
            setTimeout(function () {
              out.textContent = d.text + (d.sub ? ' ' + d.sub : '') + (d.next ? ' ' + d.next : '');
              if (d.prize) { out.className = 'wheel-result win'; }
            }, 4300);
          }).catch(function () { btn.disabled = false; });
        });
      })();
      </script>
    <?php endif; ?>

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
