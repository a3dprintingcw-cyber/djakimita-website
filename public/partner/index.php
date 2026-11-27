<?php
// Partner portal: check an account (QR code, barcode or typed number) and register a visit.
require __DIR__ . '/../../app/bootstrap.php';
$u = pp_require();
$cid = (int) $u['company_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'visit') {
    $acc = pp_account_id((string) ($_POST['acc'] ?? ''));
    if (!csrf_check()) {
        pp_flash('The page was open too long. Check the account again.');
    } elseif ($a = pp_lookup($acc, $cid)) {
        $why = pp_block_reason($a);
        if ($why !== '') {
            pp_flash('Not registered: ' . $why);
        } else {
            pp_register_visit((int) $a['id'], $cid);
            pp_flash('Visit registered for account ' . PP_ACCOUNT_PREFIX . $acc . '. Give the discount.');
        }
    }
    header('Location: /partner/?acc=' . $acc . '&done=1');
    exit;
}

// Reward codes (points): the customer shows a code from the app, the partner marks it as given
require_once __DIR__ . '/../../app/points.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reward') {
    $rc = (string) ($_POST['rcode'] ?? '');
    $c = pts_claim_by_code(db(), $rc);
    if (!csrf_check()) {
        pp_flash('The page was open too long. Check the code again.');
    } elseif ($c && (int) $c['partner_id'] === $cid && pts_claim_state($c) === 'open') {
        db()->prepare('UPDATE reward_claims SET used_at = NOW(), used_where = :w WHERE id = :i AND used_at IS NULL')
            ->execute(['w' => mb_substr(trim((string) ($u['company'] ?? '') . ' (' . ($u['name'] ?? '') . ')'), 0, 120), 'i' => $c['id']]);
        pp_flash('Done: give "' . $c['title'] . '" to ' . $c['first'] . '.');
    } else {
        pp_flash('This code can not be used here.');
    }
    header('Location: /partner/?rcode=' . urlencode((string) ($c['code'] ?? '')) . '&rdone=1');
    exit;
}
$rcode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['rcode'] ?? '')));
$claim = $rcode !== '' ? pts_claim_by_code(db(), $rcode) : null;
if ($claim && (int) $claim['partner_id'] !== $cid) {
    $claim = null; // codes of other partners are not shown
}

$raw = trim((string) ($_GET['acc'] ?? ''));
$acc = pp_account_id($raw);
$a = $raw !== '' ? pp_lookup($acc, $cid) : null;
$why = $a ? pp_block_reason($a) : '';
if ($a) {
    $_SESSION['pp_seen'][(int) $a['customer']] = time(); // may see the check photo for 30 minutes
}
$stats = pp_stats($cid);

pp_head('Check an account', $u, 'scan');
?>
<div class="pp-box" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
  <img src="/partner/qr.php" alt="Your QR code" width="96" height="96" style="border-radius:10px;border:1px solid #e3e9df">
  <div style="flex:1 1 220px">
    <h2 style="margin:0 0 4px">Customers scan your QR code</h2>
    <p class="pp-muted" style="margin:0 0 8px">Put this code at your counter. When a customer scans it in the Dj'aki mi ta! app, they pop up on this page within a few seconds, with photo, account and discount. Keep this page open.</p>
    <a class="btn btn-sm" href="/partner/qr.php?poster=1" target="_blank" rel="noopener">Print poster</a>
    <a class="btn btn-sm btn-outline" href="/partner/qr.php?download=1">Download QR code</a>
  </div>
</div>

<div class="pp-box">
  <h2>Check an account</h2>
  <p class="pp-muted" style="margin:0 0 10px">Or check a customer yourself: scan the QR code or barcode in their app, or type the account number.</p>
  <form method="get" class="pp-scan" id="pp-f">
    <input type="text" name="acc" id="pp-acc" inputmode="numeric" autocomplete="off" onfocus="this.select()" placeholder="Account number" value="<?= e($raw !== '' && empty($_GET['done']) ? $raw : '') ?>" autofocus>
    <button class="btn">Check</button>
    <button class="btn btn-outline" type="button" id="pp-cam-btn">Scan with camera</button>
  </form>
  <div id="pp-cam" hidden></div>
</div>

<?php if ($raw !== '' && !$a): ?>
  <div class="pp-res no"><p class="big no-t">Account not found</p><p style="margin:0">Check the number and try again. The account number is under the QR code in the customer's app.</p></div>
<?php elseif ($a): ?>
  <div class="pp-res <?= $why === '' ? 'ok' : 'no' ?>">
    <p class="big <?= $why === '' ? 'ok-t' : 'no-t' ?>"><?= $why === '' ? '✓ Valid account' : '✕ No discount now' ?></p>
    <?php if ($why !== ''): ?><p style="margin:0 0 6px;font-weight:600"><?= e($why) ?></p><?php endif; ?>
    <div class="pp-meta">
      <div><b><?= e($a['type']) ?></b><span>Account <?= PP_ACCOUNT_PREFIX . (int) $a['customer'] ?></span></div>
      <div><b><?= $a['expire'] ? e(date('d-m-Y', strtotime((string) $a['expire']))) : '–' ?></b><span>Valid until</span></div>
      <div><b><?= $a['visits_total'] === null ? '–' : max(0, $a['visits_total'] - $a['visits_used']) . ' of ' . $a['visits_total'] ?></b><span>Visits left at your business</span></div>
      <div><b><?php
        $d = $a['deal'];
        if ($d && (float) $d['the_percent'] >= 100) { echo 'Free'; }
        elseif ($d && (float) $d['the_percent'] > 0) { echo (int) round((float) $d['the_percent']) . '% off'; }
        elseif ($a['deal_text'] !== '') { echo e($a['deal_text']); }
        else { echo '–'; }
      ?></b><span>Discount<?= $d && (float) $d['was'] > 0 ? ' (normal $' . e(number_format((float) $d['was'], 2)) . ', now $' . e(number_format((float) $d['after'], 2)) . ')' : '' ?></span></div>
    </div>
    <?php if ($a['deal_text'] !== '' && $d && (float) $d['the_percent'] > 0): ?><p style="margin:0 0 10px"><b>Also:</b> <?= e($a['deal_text']) ?></p><?php endif; ?>
    <?php if ($a['holders']): ?>
      <p style="margin:6px 0 0;font-weight:700">On this account</p>
      <div class="pp-holders">
        <?php foreach ($a['holders'] as $h): ?>
          <div class="pp-holder">
            <?php if ($h['photo']): ?><img src="/partner/photo.php?v=<?= (int) $h['photo'] ?>&amp;a=<?= (int) $a['customer'] ?>" alt="Photo of <?= e($h['name']) ?>"><?php else: ?><i>?</i><?php endif; ?>
            <div><?= e($h['name']) ?><small><?= $h['year'] ? 'Born ' . e($h['year']) . ' · ' : '' ?>
              <?php if ($h['check'] === VERIFY_APPROVED): ?><span class="pp-tag ok">Checked</span><?php elseif ($h['check'] === VERIFY_PENDING): ?><span class="pp-tag wait">Check pending</span><?php elseif ($h['check'] === VERIFY_REJECTED): ?><span class="pp-tag no">Check failed</span><?php else: ?><span class="pp-tag">Not checked</span><?php endif; ?></small></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="pp-muted" style="margin:0 0 12px;font-size:.9rem">Ask for an ID if you are not sure the person in front of you is on the account.</p>
    <?php endif; ?>
    <?php if ($a['last_visit']): ?><p class="pp-muted" style="margin:0 0 12px">Last visit at your business: <?= e(date('d-m-Y H:i', strtotime((string) $a['last_visit']))) ?></p><?php endif; ?>
    <?php if ($why === ''): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="acc" value="<?= (int) $a['customer'] ?>">
        <button class="btn btn-lg" name="action" value="visit" style="width:100%">Register visit and give the discount</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="pp-box" id="reward">
  <h2 style="margin-top:0">Reward code</h2>
  <p class="pp-muted" style="margin-top:0">Customers can swap their points for your reward in the app. They show you a code of 8 letters. Check it here and mark it as given.</p>
  <form method="get" action="/partner/#reward" style="display:flex;gap:8px;flex-wrap:wrap">
    <input type="text" name="rcode" value="<?= e($rcode) ?>" placeholder="e.g. K7MX2QPA" maxlength="12" autocomplete="off" autocapitalize="characters" style="text-transform:uppercase;flex:1;min-width:160px;font-size:1.1rem;padding:10px 12px;border:1px solid #cfd9ca;border-radius:10px">
    <button class="btn">Check code</button>
  </form>
  <?php if ($rcode !== ''): ?>
    <?php if (!$claim): ?>
      <p style="color:#8a2216;font-weight:600;margin-bottom:0">This code is not valid at your place.</p>
    <?php else: $st = pts_claim_state($claim); ?>
      <div style="margin-top:12px;padding:12px 14px;border-radius:12px;background:<?= $st === 'open' ? '#eaf5e6' : '#fbeceb' ?>">
        <strong style="font-size:1.15rem"><?= e($claim['title']) ?></strong><br>
        <span><?= e(trim($claim['first'] . ' ' . mb_substr((string) $claim['last'], 0, 1) . '.')) ?> · code <?= e($claim['code']) ?></span><br>
        <?php if ($st === 'open'): ?>
          <span>✓ Valid until <?= e(date('d-m-Y', strtotime($claim['expires_at']))) ?></span>
          <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rcode" value="<?= e($claim['code']) ?>">
            <button class="btn btn-lg" name="action" value="reward" style="width:100%">Mark as given</button></form>
        <?php elseif ($st === 'used'): ?>
          <strong style="color:#8a2216">Already given on <?= e(date('d-m-Y H:i', strtotime((string) $claim['used_at']))) ?>. Do not give it again.</strong>
        <?php else: ?>
          <strong style="color:#8a2216">This code is no longer valid.</strong>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="pp-stats">
  <div><b><?= $stats['today'] ?></b><span>Visits today</span></div>
  <div><b><?= $stats['month'] ?></b><span>Visits this month</span></div>
  <div><b><?= $stats['all'] ?></b><span>Visits in total</span></div>
  <?php if ($stats['views'] !== null): ?><div><b><?= $stats['views'] ?></b><span>Page views in the app this month</span></div><?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js" defer></script>
<script>
{
  // Barcode scanners type the number and press Enter: the form sends itself. Keep the cursor in the box.
  const box = document.getElementById('pp-acc');
  window.addEventListener('focus', () => box.focus());
  const btn = document.getElementById('pp-cam-btn'), cam = document.getElementById('pp-cam');
  let scanner = null;
  btn.addEventListener('click', async () => {
    if (scanner) { await scanner.stop().catch(() => {}); scanner = null; cam.hidden = true; btn.textContent = 'Scan with camera'; return; }
    if (!window.Html5Qrcode) { alert('The camera scanner is still loading. Try again in a moment.'); return; }
    cam.hidden = false; btn.textContent = 'Stop camera';
    scanner = new Html5Qrcode('pp-cam', { formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE, Html5QrcodeSupportedFormats.CODE_128] });
    scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 200 } }, (text) => {
      scanner.stop().catch(() => {});
      let v = text;
      try { const j = JSON.parse(text); if (j && j.id) v = String(j.id); } catch (e) {}
      box.value = v; document.getElementById('pp-f').submit();
    }, () => {}).catch(() => { cam.hidden = true; btn.textContent = 'Scan with camera'; scanner = null; alert('The camera could not start. Allow camera access, or type the number.'); });
  });
}
</script>
<?php pp_foot();
