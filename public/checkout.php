<?php
require __DIR__ . '/../app/bootstrap.php';

$cardKeys = array_keys(CARD_TYPES);
$holderSlots = ['kids' => 1, 'teen' => 1, 'adult' => 1, 'senior' => 1, 'family' => 5];
$esimEur = prices()[ESIM_PRODUCT]['eur'] ?? 11;
$esimUsd = prices()[ESIM_PRODUCT]['usd'] ?? 12.5;

$requested = (string) ($_GET['card'] ?? 'adult');
$form = [
    'card' => in_array($requested, $cardKeys, true) ? $requested : ($requested === 'esim' ? '' : 'adult'),
    'esim' => $requested === 'esim',
    'holders' => array_fill(0, 5, ''),
    'births' => array_fill(0, 5, ''),
    'born' => false,
    'first' => '', 'last' => '', 'email' => '', 'phone' => '',
    'currency' => 'eur',
    'terms' => false,
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['card'] = in_array($_POST['card'] ?? '', $cardKeys, true) ? $_POST['card'] : '';
    $form['esim'] = !empty($_POST['esim']) || ($_POST['card'] ?? '') === 'none';
    $form['holders'] = array_map(fn($v) => mb_substr(trim((string) $v), 0, 80), array_pad((array) ($_POST['holders'] ?? []), 5, ''));
    $form['births'] = array_map(fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '', array_pad((array) ($_POST['births'] ?? []), 5, ''));
    $form['born'] = !empty($_POST['born']);
    $form['first'] = mb_substr(trim((string) ($_POST['first'] ?? '')), 0, 60);
    $form['last'] = mb_substr(trim((string) ($_POST['last'] ?? '')), 0, 60);
    $form['email'] = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120);
    $form['phone'] = mb_substr(preg_replace('/[^0-9+ ]/', '', (string) ($_POST['phone'] ?? '')), 0, 20);
    $form['currency'] = ($_POST['currency'] ?? '') === 'usd' ? 'usd' : 'eur';
    $form['terms'] = !empty($_POST['terms']);

    if (!csrf_check()) {
        $errors[] = t('co.err.session');
    }
    if ($form['card'] === '' && !$form['esim']) {
        $errors[] = t('co.err.empty');
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

    if (!$errors && !stripe_ready()) {
        $errors[] = t('co.preview');
    }
    if (!$errors) {
        try {
            $orderId = create_order([
                'card' => $form['card'],
                'esim' => $form['esim'],
                'holders' => $holders,
                'first' => $form['first'],
                'last' => $form['last'],
                'email' => $form['email'],
                'phone' => $form['phone'],
                'currency' => $form['currency'],
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
    $priceData[$k] = ['eur' => card_price($k, 'eur'), 'usd' => card_price($k, 'usd'), 'label' => t('cards.' . $k), 'slots' => $holderSlots[$k]];
}

page_header(t('co.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap">
    <h1 class="co-h1"><?= e(t('co.title')) ?></h1>

    <?php if (!stripe_ready()): ?>
      <p class="notice"><?= e(t('co.preview')) ?></p>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert" role="alert">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

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
                  <span class="pick-price" data-eur="<?= e(money(card_price($k))) ?>" data-usd="<?= e(money(card_price($k, 'usd'), 'usd')) ?>"><?= e(money(card_price($k))) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
            <label class="pick pick-wide">
              <input type="radio" name="card" value="none"<?= $form['card'] === '' ? ' checked' : '' ?>>
              <span class="pick-body"><strong><?= e(t('co.esim.only')) ?></strong></span>
            </label>
          </div>
          <label class="check esim-check">
            <input type="checkbox" name="esim" value="1"<?= $form['esim'] ? ' checked' : '' ?>>
            <span><?= icon('wifi', 20) ?> <strong><?= e(t('co.esim')) ?></strong> <span class="pick-price-inline" data-eur="+<?= e(money($esimEur)) ?>" data-usd="+<?= e(money($esimUsd, 'usd')) ?>">+<?= e(money($esimEur)) ?></span><br><span class="muted small"><?= e(t('co.esim.t')) ?></span></span>
          </label>
        </fieldset>

        <fieldset class="co-step" id="holders">
          <legend><span class="step-n sm">2</span> <?= e(t('co.step2')) ?></legend>
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
          <legend><span class="step-n sm">3</span> <?= e(t('co.step3')) ?></legend>
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
          </div>
          <ul class="sum-lines" id="sum-lines"></ul>
          <p class="sum-total"><span><?= e(t('co.total')) ?></span> <strong id="sum-total">–</strong></p>
          <label class="check">
            <input type="checkbox" name="terms" value="1"<?= $form['terms'] ? ' checked' : '' ?> required>
            <span><?= str_replace('{terms}', '<a href="/termsandconditions.php" target="_blank">' . e(t('co.terms.link')) . '</a>', e(t('co.terms'))) ?></span>
          </label>
          <button class="btn btn-lg btn-block" type="submit"><?= e(t('co.continue')) ?> <?= icon('arrow', 18) ?></button>
          <p class="trust"><?= icon('check', 16) ?> <?= e(t('co.secure')) ?></p>
          <p class="trust"><?= icon('check', 16) ?> <?= e(t('co.instant')) ?></p>
        </div>
      </aside>
    </form>
  </div>
</section>
<script>
(function () {
  var prices = <?= json_encode($priceData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var esim = {eur: <?= json_encode((float) $esimEur) ?>, usd: <?= json_encode((float) $esimUsd) ?>, label: <?= json_encode(t('co.esim'), JSON_HEX_TAG) ?>};
  var form = document.querySelector('.checkout');
  function fmt(n, cur) { var s = (n % 1 === 0) ? n.toFixed(0) : n.toFixed(2); return (cur === 'usd' ? '$' : '€') + s; }
  function update() {
    var card = (form.querySelector('input[name=card]:checked') || {}).value || 'none';
    var cur = form.querySelector('input[name=currency]:checked').value;
    var esimBox = form.querySelector('input[name=esim]');
    if (card === 'none') { esimBox.checked = true; }
    var withEsim = esimBox.checked;
    var slots = card === 'none' ? 0 : prices[card].slots;
    document.getElementById('holders').hidden = slots === 0;
    form.querySelectorAll('.holder').forEach(function (el) { el.hidden = Number(el.dataset.slot) > slots; });
    document.getElementById('phone-field').hidden = !withEsim;
    form.querySelectorAll('[data-eur]').forEach(function (el) { el.textContent = el.dataset[cur]; });
    var lines = [], total = 0;
    if (card !== 'none') { lines.push([prices[card].label, prices[card][cur]]); total += prices[card][cur]; }
    if (withEsim) { lines.push([esim.label, esim[cur]]); total += esim[cur]; }
    var ul = document.getElementById('sum-lines'); ul.textContent = '';
    lines.forEach(function (l) { var li = document.createElement('li'); var a = document.createElement('span'); a.textContent = l[0]; var b = document.createElement('span'); b.textContent = fmt(l[1], cur); li.append(a, b); ul.append(li); });
    document.getElementById('sum-total').textContent = fmt(total, cur);
  }
  form.addEventListener('change', update);
  update();
})();
</script>
<?php page_footer();
