<?php
// Countdown page shown on djakimita.com until launch_at (config).
// While it runs, only the pages needed to pre-order stay open (checkout, payment,
// account, legal). At launch_at the full site opens by itself, no upload needed.

/** Launch moment as a timestamp, or 0 when no countdown is set. */
function launch_ts(): int
{
    $at = (string) config('launch_at', '');
    return $at === '' ? 0 : (int) strtotime($at);
}

function before_launch(): bool
{
    $ts = launch_ts();
    return $ts > 0 && time() < $ts;
}

/** Pages that stay open during the countdown, so people can pre-order. */
const COUNTDOWN_OPEN = [
    'checkout.php', 'pay.php', 'done.php', 'verify.php', 'mollie-webhook.php', 'stripe-webhook.php',
    'login.php', 'account.php', 'set-password.php', 'forgot-password.php', 'deletedata.php',
    'termsandconditions.php', 'privacy_policy.php', 'img.php', 'robots.txt',
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
    .cd { min-height: 100vh; min-height: 100svh; display: grid; place-items: center; padding: 28px 18px;
      background: linear-gradient(180deg, rgba(8,40,30,.55), rgba(8,40,30,.35) 45%, rgba(8,40,30,.7)), url(/assets/img/hero-beach.jpg) 30% 85%/cover no-repeat; }
    @media (min-width: 700px) { .cd { background-size: auto, 165% auto; background-position: 0 0, 0% 100%; background-color: #0b2e0d; } }
    .cd-box { width: 100%; max-width: 640px; text-align: center; color: #fff; text-shadow: 0 1px 12px rgba(0,0,0,.35); }
    .cd-top { display: flex; align-items: flex-end; justify-content: center; gap: 18px; margin-bottom: 22px; }
    .cd-logo-tall { width: 150px; padding: 14px 16px 10px; }
    .cd-mascot { width: auto; height: 250px; filter: drop-shadow(0 12px 24px rgba(0,0,0,.35)); animation: cd-wave 3s ease-in-out infinite; transform-origin: 50% 100%; }
    @keyframes cd-wave { 0%,100% { transform: rotate(0); } 50% { transform: rotate(-3deg) translateY(-4px); } }
    @media (prefers-reduced-motion: reduce) { .cd-mascot { animation: none; } }
    .cd-logo { flex: none; width: 210px; height: auto; align-self: center; margin: 0; background: #fff; border-radius: 28px; padding: 0; box-shadow: 0 12px 40px rgba(0,0,0,.25); }
    .cd-kicker { display: inline-block; background: var(--gold); color: var(--green-ink); font-weight: 700; font-size: .85rem;
      letter-spacing: .06em; text-transform: uppercase; padding: 6px 14px; border-radius: 999px; margin-bottom: 14px; }
    .cd h1 { color: #fff; font-size: clamp(2rem, 6vw, 3.4rem); margin-bottom: 12px; }
    .cd-text { font-size: 1.12rem; opacity: .92; max-width: 520px; margin: 0 auto 26px; }
    .cd-clock { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; max-width: 520px; margin: 0 auto 28px; }
    .cd-unit { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.25); border-radius: 16px; padding: 14px 4px 10px;
      backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
    .cd-num { display: block; font-family: var(--display); font-weight: 800; font-size: clamp(2rem, 8vw, 3.2rem); line-height: 1; font-variant-numeric: tabular-nums; }
    .cd-lbl { display: block; font-size: .8rem; text-transform: uppercase; letter-spacing: .08em; opacity: .85; margin-top: 6px; }
    .cd-cta { display: inline-flex; align-items: center; gap: 10px; background: var(--gold); color: var(--green-ink); font-weight: 700;
      font-size: 1.15rem; padding: 16px 30px; border-radius: 999px; text-decoration: none; box-shadow: 0 8px 24px rgba(0,0,0,.25); transition: transform .15s; }
    .cd-cta:hover { color: var(--green-ink); transform: translateY(-2px); }
    .cd-note { font-size: .92rem; opacity: .85; margin: 14px auto 0; max-width: 440px; }
    .cd-foot { margin-top: 26px; display: flex; flex-wrap: wrap; gap: 14px 22px; justify-content: center; align-items: center; font-size: .92rem; }
    .cd-foot a { color: #fff; opacity: .9; }
    .cd-follow { margin-top: 30px; padding-top: 22px; border-top: 1px solid rgba(255,255,255,.2); }
    .cd-follow-t { font-weight: 700; margin-bottom: 12px; }
    .cd-brands { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px 18px; }
    .cd-brand { display: flex; align-items: center; gap: 6px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.25); border-radius: 999px; padding: 6px 8px 6px 16px; }
    .cd-brand span { font-weight: 700; margin-right: 4px; }
    .cd-brand a { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 50%; color: #fff; }
    .cd-brand a:hover { background: rgba(255,255,255,.18); color: #fff; }
    @media (max-width: 520px) { .cd-logo { width: 150px; border-radius: 22px; } .cd-mascot { height: 190px; } .cd-top { gap: 12px; margin-bottom: 16px; } }
    @media (max-width: 420px) { .cd-clock { gap: 6px; } .cd-lbl { font-size: .68rem; letter-spacing: .03em; } }
  </style>
</head>
<body>
<main class="cd">
  <div class="cd-box">
    <div class="cd-top">
      <?php $img = __DIR__ . '/../public_html/assets/img/'; $img2 = __DIR__ . '/../public/assets/img/'; ?>
      <?php if (is_file($img . 'logo-square.jpg') || is_file($img2 . 'logo-square.jpg')): ?>
      <img class="cd-logo" src="/assets/img/logo-square.jpg" alt="Dj'aki mi ta!" width="300" height="300">
      <?php else: ?>
      <img class="cd-logo cd-logo-tall" src="/assets/img/logo-full.png" alt="Dj'aki mi ta!" width="325" height="417">
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

    <div class="cd-follow">
      <p class="cd-follow-t"><?= e($c['follow']) ?></p>
      <div class="cd-brands">
        <div class="cd-brand">
          <span>Dj'aki mi ta!</span>
          <a href="https://www.instagram.com/djakimita/" rel="noopener" aria-label="Dj'aki mi ta! on Instagram"><?= icon('instagram', 22) ?></a>
          <a href="https://www.facebook.com/share/1ZT1D2dMHx/" rel="noopener" aria-label="Dj'aki mi ta! on Facebook"><?= icon('facebook', 22) ?></a>
          <a href="https://www.tiktok.com/@djakimita.cw" rel="noopener" aria-label="Dj'aki mi ta! on TikTok"><?= icon('tiktok', 22) ?></a>
        </div>
        <div class="cd-brand">
          <span>Rib'e luga!</span>
          <a href="https://www.instagram.com/ribeluga/" rel="noopener" aria-label="Rib'e luga! on Instagram"><?= icon('instagram', 22) ?></a>
          <a href="https://www.facebook.com/profile.php?id=61578003292607" rel="noopener" aria-label="Rib'e luga! on Facebook"><?= icon('facebook', 22) ?></a>
          <a href="https://www.tiktok.com/@ribeluga" rel="noopener" aria-label="Rib'e luga! on TikTok"><?= icon('tiktok', 22) ?></a>
        </div>
      </div>
    </div>

    <div class="cd-foot">
      <a href="/login.php"><?= e($c['login']) ?></a>
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
