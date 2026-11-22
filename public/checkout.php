<?php
require __DIR__ . '/../app/bootstrap.php';

$cardKeys = array_keys(CARD_TYPES);
$holderSlots = ['kids' => 1, 'teen' => 1, 'adult' => 1, 'senior' => 1, 'family' => 5];
$esimEur = prices()[ESIM_PRODUCT]['eur'] ?? 11;
$esimUsd = prices()[ESIM_PRODUCT]['usd'] ?? 12.5;
$esimXcg = prices()[ESIM_PRODUCT]['xcg'] ?? 22;

$requested = (string) ($_GET['card'] ?? 'adult');
$form = [
    'card' => in_array($requested, $cardKeys, true) ? $requested : ($requested === 'esim' ? '' : 'adult'),
    'esim' => $requested === 'esim',
    'length' => preg_match('/^(10d|1m|3m|6m|12m)$/', (string) ($_GET['length'] ?? '')) ? (string) $_GET['length'] : '12m',
    'holders' => array_fill(0, 5, ''),
    'births' => array_fill(0, 5, ''),
    'born' => false,
    'first' => '', 'last' => '', 'email' => '', 'phone' => '',
    'currency' => 'eur',
    'terms' => false,
    'code' => strtoupper(mb_substr(trim((string) ($_GET['code'] ?? '')), 0, 40)),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['card'] = in_array($_POST['card'] ?? '', $cardKeys, true) ? $_POST['card'] : '';
    $form['esim'] = !empty($_POST['esim']) || ($_POST['card'] ?? '') === 'none';
    $form['length'] = (string) ($_POST['length'] ?? '');
    $form['holders'] = array_map(fn($v) => mb_substr(trim((string) $v), 0, 80), array_pad((array) ($_POST['holders'] ?? []), 5, ''));
    $form['births'] = array_map(fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '', array_pad((array) ($_POST['births'] ?? []), 5, ''));
    $form['born'] = !empty($_POST['born']);
    $form['first'] = mb_substr(trim((string) ($_POST['first'] ?? '')), 0, 60);
    $form['last'] = mb_substr(trim((string) ($_POST['last'] ?? '')), 0, 60);
    $form['email'] = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120);
    $form['phone'] = mb_substr(preg_replace('/[^0-9+ ]/', '', (string) ($_POST['phone'] ?? '')), 0, 20);
    $form['currency'] = in_array($_POST['currency'] ?? '', ['usd', 'xcg'], true) ? $_POST['currency'] : 'eur';
    if ($form['currency'] === 'xcg' && !sentoo_ready()) {
        $form['currency'] = 'eur';
    }
    $form['terms'] = !empty($_POST['terms']);
    $form['code'] = strtoupper(mb_substr(trim((string) ($_POST['code'] ?? '')), 0, 40));
    $discount = null;
    if ($form['code'] !== '' && $form['card'] !== '') {
        $discount = discount_find($form['code']);
        if (!$discount) {
            $errors[] = t('co.code.bad');
        }
    }

    if (!csrf_check()) {
        $errors[] = t('co.err.session');
    }
    if ($form['card'] === '' && !$form['esim']) {
        $errors[] = t('co.err.empty');
    }
    if ($form['card'] !== '' && !card_length($form['card'], $form['length'])) {
        $errors[] = t('co.err.length');
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('co.err.email');
    }
    $holders = [];
    if ($form['card'] !== '') {
        $slots = $holderSlots[$form['card']];
        if ($form['holders'][0] === '' && $form['first'] !== '') {
            // Card for yourself: use the buyer's name.
            $form['holders'][0] = trim($form['first'] . ' ' . $form['last']);
        }
        $today = date('Y-m-d');
        $holderError = false;
        for ($i = 0; $i < $slots; $i++) {
            $name = $form['holders'][$i];
            $birth = $form['births'][$i];
            if ($name === '' && $birth === '') {
                continue;
            }
            $okDate = $birth !== '' && checkdate((int) substr($birth, 5, 2), (int) substr($birth, 8, 2), (int) substr($birth, 0, 4))
                && $birth > '1900-01-01' && $birth <= $today;
            if ($name === '') {
                $errors[] = t('co.err.name');
                $holderError = true;
            } elseif (!$okDate) {
                $errors[] = t('co.err.birth', ['name' => $name]);
                $holderError = true;
            } else {
                $holders[] = ['name' => $name, 'birth' => $birth];
            }
        }
        if (!$holders && !$holderError) {
            $errors[] = t('co.err.name');
        }
        if ($holders && ($ageError = card_age_error($form['card'], $holders))) {
            $errors[] = $ageError;
        }
        if (!$form['born']) {
            $errors[] = t('co.err.born');
        }
    }
    if ($form['first'] === '') {
        $errors[] = t('co.err.name');
    }
    if ($form['esim'] && strlen(preg_replace('/\D/', '', $form['phone'])) < 7) {
        $errors[] = t('co.err.phone');
    }
    if (!$form['terms']) {
        $errors[] = t('co.err.terms');
    }

    if (!$errors && !payments_ready()) {
        $errors[] = t('co.preview');
    }
    if (!$errors) {
        try {
            $orderId = create_order([
                'card' => $form['card'],
                'length' => $form['length'],
                'esim' => $form['esim'],
                'holders' => $holders,
                'first' => $form['first'],
                'last' => $form['last'],
                'email' => $form['email'],
                'phone' => $form['phone'],
                'currency' => $form['currency'],
                'discount' => $discount,
            ]);
            header('Location: /pay.php?' . http_build_query(['o' => $orderId, 's' => order_sig($orderId)]));
            exit;
        } catch (Throwable $ex) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            error_log('Checkout failed: ' . $ex->getMessage());
            $errors[] = t('co.err.general');
        }
    }
    $errors = array_values(array_unique($errors));
}

// Prices for the live summary
$priceData = [];
foreach ($cardKeys as $k) {
    $lengths = [];
    foreach (card_lengths($k) as $code => $l) {
        $months = $l['months'] ?: 0;
        $lengths[$code] = ['eur' => $l['eur'], 'usd' => $l['usd'], 'xcg' => $l['xcg'], 'label' => t('co.len.' . $code), 'months' => $months];
    }
    $priceData[$k] = ['label' => t('cards.' . $k), 'slots' => $holderSlots[$k], 'lengths' => $lengths];
}
$lengthCodes = ['10d', '1m', '3m', '6m', '12m'];

page_header(t('co.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap">
    <h1 class="co-h1"><?= e(t('co.title')) ?></h1>

    <?php if (!payments_ready()): ?>
      <p class="notice"><?= e(t('co.preview')) ?></p>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert" role="alert">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <style>
      .code-box { margin: 4px 0 12px; }
      .code-row { display: flex; gap: 6px; margin-top: 4px; }
      .code-row input { flex: 1; min-width: 0; text-transform: uppercase; padding: 9px 11px; border: 1px solid var(--line, #cfd9ca); border-radius: 10px; font: inherit; }
      .code-ok { color: #1c5a1d; font-weight: 600; } .code-bad { color: #8a2216; font-weight: 600; }
      .sum-disc span:last-child { color: #1c5a1d; font-weight: 700; }
      #len-pick .len-body { padding: 18px 6px 14px; }
      #len-pick .len-price { font-size: 1.35rem; margin-top: 2px; }
      .card-pick .pick-body { min-height: 0; }
    </style>
    <form class="checkout" method="post" action="/checkout.php" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="co-main">

        <fieldset class="co-step">
          <legend><span class="step-n sm">1</span> <?= e(t('co.step1')) ?></legend>
          <div class="card-pick">
            <?php foreach ($cardKeys as $k): ?>
              <label class="pick">
                <input type="radio" name="card" value="<?= e($k) ?>"<?= $form['card'] === $k ? ' checked' : '' ?>>
                <span class="pick-body">
                  <strong><?= e(t('cards.' . $k)) ?></strong>
                  <span class="muted small"><?= e(t('cards.' . $k . '.t')) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
            <label class="pick pick-wide">
              <input type="radio" name="card" value="none"<?= $form['card'] === '' ? ' checked' : '' ?>>
              <span class="pick-body"><strong><?= e(t('co.esim.only')) ?></strong></span>
            </label>
          </div>

        </fieldset>

        <fieldset class="co-step" id="len-pick">
          <legend><span class="step-n sm">2</span> <span id="len-legend"><?= e(t('co.length')) ?></span></legend>
          <p class="hint"><?= e(t(function_exists('before_launch') && before_launch() ? 'co.len.hint.pre' : 'co.len.hint')) ?></p>
          <div>
            <div class="len-grid" role="radiogroup" aria-label="<?= e(t('co.length')) ?>">
              <?php foreach ($lengthCodes as $code): ?>
                <label class="len" data-code="<?= e($code) ?>">
                  <input type="radio" name="length" value="<?= e($code) ?>"<?= $form['length'] === $code ? ' checked' : '' ?>>
                  <span class="len-body">
                    <?php if ($code === '12m'): ?><span class="len-best"><?= e(t('co.len.best')) ?></span><?php endif; ?>
                    <strong><?= e(t('co.len.' . $code)) ?></strong>
                    <span class="len-price">–</span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <label class="check esim-check">
            <input type="checkbox" name="esim" value="1"<?= $form['esim'] ? ' checked' : '' ?>>
            <span><?= icon('wifi', 20) ?> <strong><?= e(t('co.esim')) ?></strong> <span class="pick-price-inline" data-eur="+<?= e(money($esimEur)) ?>" data-usd="+<?= e(money($esimUsd, 'usd')) ?>" data-xcg="+<?= e(money($esimXcg, 'xcg')) ?>">+<?= e(money($esimEur)) ?></span><br><span class="muted small"><?= e(t('co.esim.t')) ?></span></span>
          </label>
        </fieldset>

        <fieldset class="co-step" id="holders">
          <legend><span class="step-n sm" id="holders-n">3</span> <?= e(t('co.step2')) ?></legend>
          <p class="hint"><?= e(t('co.holder.hint')) ?></p>
          <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="field-row holder" data-slot="<?= $i + 1 ?>">
              <div class="field">
                <label for="h<?= $i ?>"><?= $i === 0 ? e(t('co.holder')) : e(t('co.holder.n', ['n' => $i + 1])) . ' <span class="muted small">(' . e(t('co.holder.opt')) . ')</span>' ?></label>
                <input id="h<?= $i ?>" name="holders[]" type="text" autocomplete="<?= $i === 0 ? 'name' : 'off' ?>" value="<?= e($form['holders'][$i]) ?>">
              </div>
              <div class="field">
                <label for="b<?= $i ?>"><?= e(t('co.birth')) ?></label>
                <input id="b<?= $i ?>" name="births[]" type="date" min="1900-01-01" max="<?= date('Y-m-d') ?>" value="<?= e($form['births'][$i]) ?>"<?= $i === 0 ? ' autocomplete="bday"' : '' ?>>
              </div>
            </div>
          <?php endfor; ?>
          <label class="check born-check">
            <input type="checkbox" name="born" value="1"<?= $form['born'] ? ' checked' : '' ?>>
            <span><strong><?= e(t('co.born')) ?></strong><br><span class="muted small"><?= e(t('co.born.t')) ?></span></span>
          </label>
        </fieldset>

        <fieldset class="co-step">
          <legend><span class="step-n sm" id="details-n">4</span> <?= e(t('co.step3')) ?></legend>
          <div class="field-row">
            <div class="field"><label for="first"><?= e(t('co.first')) ?></label><input id="first" name="first" type="text" autocomplete="given-name" required value="<?= e($form['first']) ?>"></div>
            <div class="field"><label for="last"><?= e(t('co.last')) ?></label><input id="last" name="last" type="text" autocomplete="family-name" value="<?= e($form['last']) ?>"></div>
          </div>
          <div class="field">
            <label for="email"><?= e(t('co.email')) ?></label>
            <input id="email" name="email" type="email" autocomplete="email" inputmode="email" required value="<?= e($form['email']) ?>" aria-describedby="email-hint">
            <p class="hint" id="email-hint"><?= e(t('co.email.hint')) ?></p>
          </div>
          <div class="field" id="phone-field">
            <label for="phone"><?= e(t('co.phone')) ?></label>
            <input id="phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" value="<?= e($form['phone']) ?>" aria-describedby="phone-hint">
            <p class="hint" id="phone-hint"><?= e(t('co.phone.hint')) ?></p>
          </div>
        </fieldset>
      </div>

      <aside class="co-side">
        <div class="summary">
          <h2 class="h3"><?= e(t('co.summary')) ?></h2>
          <div class="cur" role="radiogroup" aria-label="<?= e(t('co.currency')) ?>">
            <span class="muted small"><?= e(t('co.currency')) ?></span>
            <label><input type="radio" name="currency" value="eur"<?= $form['currency'] === 'eur' ? ' checked' : '' ?>> € EUR</label>
            <label><input type="radio" name="currency" value="usd"<?= $form['currency'] === 'usd' ? ' checked' : '' ?>> $ USD</label>
            <?php if (sentoo_ready()): ?><label><input type="radio" name="currency" value="xcg"<?= $form['currency'] === 'xcg' ? ' checked' : '' ?>> ƒ XCG</label><?php endif; ?>
          </div>
          <ul class="sum-lines" id="sum-lines"></ul>
          <?php $preDisc = $form['code'] !== '' ? discount_find($form['code']) : null; ?>
          <div class="code-box" id="code-box">
            <label for="code" class="small"><?= e(t('co.code')) ?></label>
            <div class="code-row"><input id="code" name="code" type="text" autocomplete="off" autocapitalize="characters" value="<?= e($form['code']) ?>" data-pct="<?= $preDisc ? (float) $preDisc['percent'] : 0 ?>"><button type="button" class="btn btn-sm btn-outline" id="code-apply"><?= e(t('co.code.apply')) ?></button></div>
            <p class="small" id="code-msg" aria-live="polite"><?= $preDisc ? e(t('co.code.ok', ['p' => (float) $preDisc['percent']])) : '' ?></p>
          </div>
          <p class="sum-total"><span><?= e(t('co.total')) ?></span> <strong id="sum-total">–</strong></p>
          <label class="check">
            <input type="checkbox" name="terms" value="1"<?= $form['terms'] ? ' checked' : '' ?> required>
            <span><?= str_replace('{terms}', '<a href="/termsandconditions.php" target="_blank">' . e(t('co.terms.link')) . '</a>', e(t('co.terms'))) ?></span>
          </label>
          <button class="btn btn-lg btn-block" type="submit"><?= e(t('co.continue')) ?> <?= icon('arrow', 18) ?></button>
          <p class="trust" data-pay="mollie"><?= icon('check', 16) ?> <?= e(t('co.secure')) ?></p>
          <p class="trust" data-pay="sentoo" hidden><?= icon('check', 16) ?> <?= e(t('co.secure.sentoo')) ?></p>
          <p class="trust"><?= icon('check', 16) ?> <?= e(t(function_exists('before_launch') && before_launch() ? 'co.instant.pre' : 'co.instant')) ?></p>
        </div>
      </aside>
    </form>
  </div>
</section>
<script>
(function () {
  var prices = <?= json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var esim = {eur: <?= json_encode((float) $esimEur) ?>, usd: <?= json_encode((float) $esimUsd) ?>, xcg: <?= json_encode((float) $esimXcg) ?>, label: <?= json_encode(t('co.esim'), JSON_HEX_TAG) ?>};
  var form = document.querySelector('.checkout');
  var lenTitle = <?= json_encode(t('co.len.for'), JSON_HEX_TAG) ?>;
  function fmt(n, cur) { var s = (n % 1 === 0) ? n.toFixed(0) : n.toFixed(2); return cur === 'xcg' ? 'XCG ' + s : (cur === 'usd' ? '$' : '€') + s; }
  function update() {
    var card = (form.querySelector('input[name=card]:checked') || {}).value || 'none';
    var cur = form.querySelector('input[name=currency]:checked').value;
    form.querySelector('[data-pay=mollie]').hidden = cur === 'xcg';
    form.querySelector('[data-pay=sentoo]').hidden = cur !== 'xcg';
    var esimBox = form.querySelector('input[name=esim]');
    if (card === 'none') { esimBox.checked = true; }
    var withEsim = esimBox.checked;
    var slots = card === 'none' ? 0 : prices[card].slots;
    var lens = card === 'none' ? {} : prices[card].lengths;
    var lenBox = document.getElementById('len-pick');
    lenBox.hidden = card === 'none' || Object.keys(lens).length < 2;
    var chosen = form.querySelector('input[name=length]:checked');
    if (card !== 'none' && (!chosen || !lens[chosen.value])) {
      var def = lens['12m'] ? '12m' : Object.keys(lens).pop();
      var r = form.querySelector('input[name=length][value="' + def + '"]');
      if (r) { r.checked = true; } chosen = r;
    }
    form.querySelectorAll('.len').forEach(function (el) {
      var l = lens[el.dataset.code];
      el.hidden = !l;
      if (!l) { return; }
      el.querySelector('.len-price').textContent = fmt(l[cur], cur);
    });
    var len = card !== 'none' && chosen ? lens[chosen.value] : null;
    if (card !== 'none') { document.getElementById('len-legend').textContent = lenTitle.replace('{card}', prices[card].label); }
    document.getElementById('holders-n').textContent = '3';
    document.getElementById('details-n').textContent = card === 'none' ? '2' : '4';
    document.getElementById('holders').hidden = slots === 0;
    form.querySelectorAll('.holder').forEach(function (el) { el.hidden = Number(el.dataset.slot) > slots; });
    document.getElementById('phone-field').hidden = !withEsim;
    form.querySelectorAll('[data-eur]').forEach(function (el) { el.textContent = el.dataset[cur] || el.dataset.eur; });
    var lines = [], total = 0;
    if (len) { lines.push([prices[card].label + ' · ' + len.label, len[cur]]); total += len[cur]; }
    document.getElementById('code-box').hidden = card === 'none';
    var pct = Math.min(90, parseFloat(codeIn.dataset.pct) || 0);
    if (len && pct > 0) { var off = Math.round(len[cur] * pct) / 100; lines.push([codeLine.replace('{code}', codeIn.value.trim().toUpperCase()), -off]); total -= off; }
    if (withEsim) { lines.push([esim.label, esim[cur]]); total += esim[cur]; }
    var ul = document.getElementById('sum-lines'); ul.textContent = '';
    lines.forEach(function (l) { var li = document.createElement('li'); var a = document.createElement('span'); a.textContent = l[0]; var b = document.createElement('span'); b.textContent = (l[1] < 0 ? '−' : '') + fmt(Math.abs(l[1]), cur); if (l[1] < 0) { li.className = 'sum-disc'; } li.append(a, b); ul.append(li); });
    document.getElementById('sum-total').textContent = fmt(total, cur);
  }
  var codeIn = document.getElementById('code'), codeMsg = document.getElementById('code-msg');
  var codeLine = <?= json_encode(t('co.code.line'), JSON_HEX_TAG) ?>, codeOk = <?= json_encode(t('co.code.ok'), JSON_HEX_TAG) ?>, codeBad = <?= json_encode(t('co.code.bad'), JSON_HEX_TAG) ?>;
  function applyCode() {
    var v = codeIn.value.trim();
    if (!v) { codeIn.dataset.pct = 0; codeMsg.textContent = ''; update(); return; }
    fetch('/discount.php?code=' + encodeURIComponent(v), {headers: {Accept: 'application/json'}}).then(function (r) { return r.json(); }).then(function (d) {
      codeIn.dataset.pct = d.ok ? d.percent : 0;
      codeMsg.textContent = d.ok ? codeOk.replace('{p}', d.percent) : codeBad;
      codeMsg.className = 'small ' + (d.ok ? 'code-ok' : 'code-bad');
      update();
    }).catch(function () {});
  }
  document.getElementById('code-apply').addEventListener('click', applyCode);
  codeIn.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); applyCode(); } });
  codeIn.addEventListener('input', function () { if (codeIn.dataset.pct !== '0') { codeIn.dataset.pct = 0; codeMsg.textContent = ''; update(); } });
  form.addEventListener('change', update);
  // The buyer is usually card holder 1: copy that name into First/Last name until they are edited by hand.
  var h0 = document.getElementById('h0'), fn = document.getElementById('first'), ln = document.getElementById('last');
  [fn, ln].forEach(function (el) { el.dataset.auto = el.value === '' ? '1' : ''; el.addEventListener('input', function () { el.dataset.auto = ''; }); });
  h0.addEventListener('input', function () {
    var parts = h0.value.trim().split(/\s+/), first = parts.shift() || '', last = parts.join(' ');
    if (fn.dataset.auto) { fn.value = first; }
    if (ln.dataset.auto) { ln.value = last; }
  });
  update();
})();
</script>
<?php page_footer();
