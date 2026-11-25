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
            pp_register_visit($acc, $cid);
            pp_flash('Visit registered for account ' . PP_ACCOUNT_PREFIX . $acc . '. Give the discount.');
        }
    }
    header('Location: /partner/?acc=' . $acc . '&done=1');
    exit;
}

$raw = trim((string) ($_GET['acc'] ?? ''));
$acc = pp_account_id($raw);
$a = $raw !== '' ? pp_lookup($acc, $cid) : null;
$why = $a ? pp_block_reason($a) : '';
$stats = pp_stats($cid);

pp_head('Check an account', $u, 'scan');
?>
<div class="pp-box">
  <h2>Check an account</h2>
  <p class="pp-muted" style="margin:0 0 10px">Scan the QR code or barcode in the customer's app, or type the account number.</p>
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
      <div><b><?= e($a['type']) ?></b><span>Account <?= PP_ACCOUNT_PREFIX . (int) $a['id'] ?></span></div>
      <div><b><?= $a['expire'] ? e(date('d-m-Y', strtotime((string) $a['expire']))) : '–' ?></b><span>Valid until</span></div>
      <div><b><?= $a['visits_total'] === null ? '–' : max(0, $a['visits_total'] - $a['visits_used']) . ' of ' . $a['visits_total'] ?></b><span>Visits left at your business</span></div>
      <div><b><?php
        $d = $a['deal'];
        if ($d && (float) $d['the_percent'] >= 100) { echo 'Free'; }
        elseif ($d && (float) $d['the_percent'] > 0) { echo (int) round((float) $d['the_percent']) . '% off'; }
        elseif ($a['deal_text'] !== '') { echo e($a['deal_text']); }
        else { echo '–'; }
      ?></b><span>Discount<?= $d && (float) $d['was'] > 0 ? ' (normal ' . e(number_format((float) $d['was'], 2)) . ', now ' . e(number_format((float) $d['after'], 2)) . ')' : '' ?></span></div>
    </div>
    <?php if ($a['deal_text'] !== '' && $d && (float) $d['the_percent'] > 0): ?><p style="margin:0 0 10px"><b>Also:</b> <?= e($a['deal_text']) ?></p><?php endif; ?>
    <?php if ($a['holders']): ?>
      <p style="margin:6px 0 0;font-weight:700">On this account</p>
      <div class="pp-holders">
        <?php foreach ($a['holders'] as $h): ?>
          <div class="pp-holder">
            <?php if ($h['photo']): ?><img src="/partner/photo.php?v=<?= (int) $h['photo'] ?>&amp;a=<?= (int) $a['id'] ?>" alt="Photo of <?= e($h['name']) ?>"><?php else: ?><i>?</i><?php endif; ?>
            <div><?= e($h['name']) ?><small><?= $h['year'] ? 'Born ' . e($h['year']) . ' · ' : '' ?>
              <?php if ($h['check'] === VERIFY_APPROVED): ?><span class="pp-tag ok">Checked</span><?php elseif ($h['check'] === VERIFY_PENDING): ?><span class="pp-tag wait">Check pending</span><?php elseif ($h['check'] === VERIFY_REJECTED): ?><span class="pp-tag no">Check failed</span><?php else: ?><span class="pp-tag">Not checked</span><?php endif; ?></small></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="pp-muted" style="margin:0 0 12px;font-size:.9rem">Ask for an ID if you are not sure the person in front of you is on the account.</p>
    <?php endif; ?>
    <?php if ($a['last_visit']): ?><p class="pp-muted" style="margin:0 0 12px">Last visit at your business: <?= e(date('d-m-Y H:i', strtotime((string) $a['last_visit']))) ?></p><?php endif; ?>
    <?php if ($why === ''): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="acc" value="<?= (int) $a['id'] ?>">
        <button class="btn btn-lg" name="action" value="visit" style="width:100%">Register visit and give the discount</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>

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
