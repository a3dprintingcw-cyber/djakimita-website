<?php
// Countdown page shown on djakimita.com until launch_at (config).
// While it runs, only the pages needed to pre-order stay open (checkout, payment,
// account, legal). At launch_at the full site opens by itself, no upload needed.

/** Launch moment as a timestamp, or 0 when no countdown is set. */
function launch_ts(): int
{
    // Set in the admin under Settings; config.local.php is the fallback.
    $at = (string) setting('launch_at', (string) config('launch_at', ''));
    return $at === '' ? 0 : (int) strtotime($at);
}

function before_launch(): bool
{
    $ts = launch_ts();
    return $ts > 0 && time() < $ts;
}

/** Pages that stay open during the countdown, so people can pre-order. */
const COUNTDOWN_OPEN = [
    'checkout.php', 'pay.php', 'done.php', 'verify.php', 'mollie-webhook.php', 'stripe-webhook.php', 'sentoo-webhook.php',
    'login.php', 'account.php', 'set-password.php', 'forgot-password.php', 'deletedata.php',
    'termsandconditions.php', 'privacy_policy.php', 'img.php', 'robots.txt',
    'partners.php', 'partner.php',
];

function countdown_texts(): array
{
    $all = [
        'en' => [
            'kicker' => 'Something new is coming',
            'title' => 'The new Dj\'aki mi ta! is almost here',
            'text' => 'A brand new website, more partners and your card straight in your phone. Pre-order now and your card is ready on day one.',
            'days' => 'days', 'hours' => 'hours', 'mins' => 'minutes', 'secs' => 'seconds',
            'cta' => 'Pre-order your card',
            'note' => 'Pre-ordered cards start on launch day, so you don\'t lose a single day.',
            'live' => 'We\'re live! Loading the new site...',
            'login' => 'Already a member? Log in',
            'follow' => 'Follow us so you don\'t miss the launch',
            'partners' => 'See our partners',
        ],
        'nl' => [
            'kicker' => 'Er komt iets nieuws aan',
            'title' => 'De nieuwe Dj\'aki mi ta! is er bijna',
            'text' => 'Een gloednieuwe website, meer partners en je kaart direct in je telefoon. Bestel nu alvast en je kaart is klaar op dag één.',
            'days' => 'dagen', 'hours' => 'uur', 'mins' => 'minuten', 'secs' => 'seconden',
            'cta' => 'Bestel je kaart alvast',
            'note' => 'Vooraf bestelde kaarten gaan in op de lanceerdag, je mist dus geen enkele dag.',
            'live' => 'We zijn live! De nieuwe site wordt geladen...',
            'login' => 'Al lid? Log in',
            'follow' => 'Volg ons en mis de lancering niet',
            'partners' => 'Bekijk onze partners',
        ],
        'pap' => [
            'kicker' => 'Algu nobo ta bini',
            'title' => 'E Dj\'aki mi ta! nobo ta kasi aki',
            'text' => 'Un website kompletamente nobo, mas partner i bo karchi direkto den bo telefon. Pre-ordená awor i bo karchi ta kla dia un.',
            'days' => 'dia', 'hours' => 'ora', 'mins' => 'minüt', 'secs' => 'sekònde',
            'cta' => 'Pre-ordená bo karchi',
            'note' => 'Karchinan pre-ordená ta kuminsá dia di lansamentu, asina bo no ta pèrdè ni un dia.',
            'live' => 'Nos ta live! E website nobo ta kargando...',
            'login' => 'Kaba ta miembro? Log in',
            'follow' => 'Sigui nos pa bo no pèrdè e lansamentu',
            'partners' => 'Mira nos partnernan',
        ],
    ];
    return $all[current_lang()] ?? $all['en'];
}

function render_countdown(): void
{
    $c = countdown_texts();
    $ts = launch_ts();
    http_response_code(200);
    header('Cache-Control: no-store');
    ?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dj'aki mi ta! · <?= e($c['title']) ?></title>
  <meta name="description" content="<?= e($c['text']) ?>">
  <meta property="og:title" content="Dj'aki mi ta! · <?= e($c['title']) ?>">
  <meta property="og:description" content="<?= e($c['text']) ?>">
  <meta property="og:image" content="<?= e(rtrim((string) config('base_url'), '/')) ?>/assets/img/og.jpg">
  <link rel="icon" href="/assets/img/logo-mark.png">
  <link rel="stylesheet" href="/assets/css/site.css">
  <style>
    html, body { height: 100%; overflow: hidden; }
    .cd { height: 100vh; height: 100svh; display: flex; align-items: center; justify-content: center; padding: clamp(10px, 2.5vh, 28px) 16px;
      background: linear-gradient(180deg, rgba(11,46,13,.55), rgba(11,46,13,.82)), url(/assets/img/hero-beach.jpg) center/cover no-repeat; overflow: hidden; }
    .cd-box { width: 100%; max-width: 620px; max-height: 100%; text-align: center; color: #fff; display: flex; flex-direction: column; align-items: center; gap: clamp(6px, 1.6vh, 16px); }
    .cd-top { display: flex; align-items: flex-end; justify-content: center; gap: 14px; }
    .cd-logo { flex: none; width: clamp(84px, 15vh, 150px); height: clamp(84px, 15vh, 150px); object-fit: contain; background: #fff; border-radius: 22%; padding: 7%; box-shadow: 0 10px 30px rgba(0,0,0,.25); align-self: center; }
    .cd-mascot { width: auto; height: clamp(110px, 22vh, 230px); filter: drop-shadow(0 12px 24px rgba(0,0,0,.35)); animation: cd-wave 3s ease-in-out infinite; transform-origin: 50% 100%; }
    @keyframes cd-wave { 0%,100% { transform: rotate(0); } 50% { transform: rotate(-3deg) translateY(-4px); } }
    @media (prefers-reduced-motion: reduce) { .cd-mascot { animation: none; } }
    .cd-kicker { display: inline-block; background: var(--gold); color: var(--green-ink); font-weight: 700; font-size: clamp(.68rem, 1.5vh, .85rem);
      letter-spacing: .06em; text-transform: uppercase; padding: 5px 14px; border-radius: 999px; }
    .cd h1 { color: #fff; font-size: clamp(1.5rem, min(6vw, 5vh), 3.2rem); margin: 0; }
    .cd-text { font-size: clamp(.9rem, 2vh, 1.1rem); opacity: .92; max-width: 500px; margin: 0; line-height: 1.45; }
    .cd-clock { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; width: 100%; max-width: 480px; }
    .cd-unit { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.25); border-radius: 14px; padding: clamp(6px, 1.4vh, 14px) 4px clamp(5px, 1vh, 10px);
      backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
    .cd-num { display: block; font-family: var(--display); font-weight: 800; font-size: clamp(1.5rem, min(8vw, 5.5vh), 3rem); line-height: 1; font-variant-numeric: tabular-nums; }
    .cd-lbl { display: block; font-size: clamp(.6rem, 1.3vh, .78rem); text-transform: uppercase; letter-spacing: .06em; opacity: .85; margin-top: 4px; }
    .cd-cta { display: inline-flex; align-items: center; gap: 10px; background: var(--gold); color: var(--green-ink); font-weight: 700;
      font-size: clamp(1rem, 2.2vh, 1.15rem); padding: clamp(11px, 1.8vh, 16px) 28px; border-radius: 999px; text-decoration: none; box-shadow: 0 8px 24px rgba(0,0,0,.25); transition: transform .15s; }
    .cd-cta:hover { color: var(--green-ink); transform: translateY(-2px); }
    .cd-partners { display: inline-flex; align-items: center; gap: 8px; color: #fff; font-weight: 700; font-size: clamp(.95rem, 2vh, 1.05rem);
      border: 1.5px solid rgba(255,255,255,.7); border-radius: 999px; padding: clamp(7px, 1.2vh, 11px) 22px; text-decoration: none; }
    .cd-partners:hover { color: var(--green-ink); background: #fff; }
    .cd-note { font-size: clamp(.75rem, 1.6vh, .9rem); opacity: .85; margin: 0; max-width: 420px; }
    .cd-foot { display: flex; flex-wrap: wrap; gap: 6px 18px; justify-content: center; align-items: center; font-size: clamp(.78rem, 1.7vh, .92rem); }
    .cd-foot a { color: #fff; opacity: .9; }
    .cd-social { display: flex; gap: 2px; }
    .cd-social a { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; }
    .cd-social a:hover { background: rgba(255,255,255,.18); }
    @media (max-height: 640px) { .cd-note { display: none; } }
  </style>
</head>
<body>
<main class="cd">
  <div class="cd-box">
    <div class="cd-top">
      <?php $img = __DIR__ . '/../public_html/assets/img/'; $img2 = __DIR__ . '/../public/assets/img/'; ?>
      <?php if (is_file($img . 'logo-square.jpg') || is_file($img2 . 'logo-square.jpg')): ?>
      <img class="cd-logo" src="/assets/img/logo-square.jpg" alt="Dj'aki mi ta!" width="300" height="300" style="padding:0">
      <?php else: ?>
      <img class="cd-logo" src="/assets/img/logo-full.png" alt="Dj'aki mi ta!" width="325" height="417">
      <?php endif; ?>
      <?php if (is_file($img . 'mascot.webp') || is_file($img2 . 'mascot.webp')): ?>
      <img class="cd-mascot" src="/assets/img/mascot.webp" alt="" width="260" height="401">
      <?php endif; ?>
    </div>
    <span class="cd-kicker"><?= e($c['kicker']) ?></span>
    <h1><?= e($c['title']) ?></h1>
    <p class="cd-text"><?= e($c['text']) ?></p>

    <div class="cd-clock" id="cd" data-end="<?= $ts * 1000 ?>" aria-live="off">
      <div class="cd-unit"><span class="cd-num" data-u="d">--</span><span class="cd-lbl"><?= e($c['days']) ?></span></div>
      <div class="cd-unit"><span class="cd-num" data-u="h">--</span><span class="cd-lbl"><?= e($c['hours']) ?></span></div>
      <div class="cd-unit"><span class="cd-num" data-u="m">--</span><span class="cd-lbl"><?= e($c['mins']) ?></span></div>
      <div class="cd-unit"><span class="cd-num" data-u="s">--</span><span class="cd-lbl"><?= e($c['secs']) ?></span></div>
    </div>
    <p class="cd-text" id="cd-live" hidden><?= e($c['live']) ?></p>

    <a class="cd-cta" href="/checkout.php"><?= e($c['cta']) ?> <?= icon('arrow', 20) ?></a>
    <p class="cd-note"><?= e($c['note']) ?></p>

    <a class="cd-partners" href="/partners.php"><?= e($c['partners']) ?> <?= icon('arrow', 18) ?></a>

    <div class="cd-foot">
      <a href="/login.php"><?= e($c['login']) ?></a>
      <span class="cd-social">
        <a href="https://www.instagram.com/djakimita/" rel="noopener" aria-label="Instagram"><?= icon('instagram', 20) ?></a>
        <a href="https://www.facebook.com/share/1ZT1D2dMHx/" rel="noopener" aria-label="Facebook"><?= icon('facebook', 20) ?></a>
        <a href="https://www.tiktok.com/@djakimita.cw" rel="noopener" aria-label="TikTok"><?= icon('tiktok', 20) ?></a>
      </span>
    </div>
  </div>
</main>
<script>
(function () {
  var box = document.getElementById('cd'), end = +box.dataset.end, el = {};
  box.querySelectorAll('[data-u]').forEach(function (n) { el[n.dataset.u] = n; });
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function tick() {
    var left = Math.max(0, Math.floor((end - Date.now()) / 1000));
    el.d.textContent = Math.floor(left / 86400);
    el.h.textContent = pad(Math.floor(left % 86400 / 3600));
    el.m.textContent = pad(Math.floor(left % 3600 / 60));
    el.s.textContent = pad(left % 60);
    if (left === 0) {
      clearInterval(timer);
      document.getElementById('cd-live').hidden = false;
      setTimeout(function () { location.reload(); }, 2500 + Math.random() * 4000);
    }
  }
  var timer = setInterval(tick, 1000); tick();
})();
</script>
</body>
</html>
<?php
}

/** Called from bootstrap: show the countdown instead of the page, unless the page must stay open. */
function countdown_gate(): void
{
    if (PHP_SAPI === 'cli' || !before_launch()) {
        return;
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (in_array($script, COUNTDOWN_OPEN, true) || str_starts_with($path, '/admin')) {
        return;
    }
    // Staff can look at the full site before launch with ?peek=<preview key>
    if (($_GET['peek'] ?? '') === 'off') {
        unset($_SESSION['peek']);
    }
    $peek = (string) config('countdown_peek', '');
    if ($peek !== '' && (($_GET['peek'] ?? '') === $peek || ($_SESSION['peek'] ?? '') === $peek)) {
        $_SESSION['peek'] = $peek;
        return;
    }
    render_countdown();
    exit;
}
