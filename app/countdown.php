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
    'checkout.php', 'pay.php', 'done.php', 'points-done.php', 'verify.php', 'mollie-webhook.php', 'stripe-webhook.php', 'sentoo-webhook.php',
    'login.php', 'account.php', 'set-password.php', 'forgot-password.php', 'deletedata.php',
    'termsandconditions.php', 'privacy_policy.php', 'img.php', 'robots.txt',
    'partners.php', 'partner.php', 'discount.php', 'wheel-spin.php', 'contact.php',
];

function countdown_texts(): array
{
    $all = [
        'en' => [
            'kicker' => 'Something new is coming',
            'title' => 'The new Dj\'aki mi ta! is almost here',
            'text' => 'A brand new website, more partners and your account straight in your phone. Pre-order now and your account is ready on day one.',
            'days' => 'days', 'hours' => 'hours', 'mins' => 'minutes', 'secs' => 'seconds',
            'cta' => 'Pre-order your account',
            'note' => 'Pre-ordered accounts start on launch day, so you don\'t lose a single day.',
            'live' => 'We\'re live! Loading the new site...',
            'login' => 'Already a member? Log in',
            'follow' => 'Follow us so you don\'t miss the launch',
            'partners' => 'See our partners',
        ],
        'nl' => [
            'kicker' => 'Er komt iets nieuws aan',
            'title' => 'De nieuwe Dj\'aki mi ta! is er bijna',
            'text' => 'Een gloednieuwe website, meer partners en je account direct in je telefoon. Bestel nu alvast en je account is klaar op dag één.',
            'days' => 'dagen', 'hours' => 'uur', 'mins' => 'minuten', 'secs' => 'seconden',
            'cta' => 'Bestel je account alvast',
            'note' => 'Vooraf bestelde accounts gaan in op de lanceerdag, je mist dus geen enkele dag.',
            'live' => 'We zijn live! De nieuwe site wordt geladen...',
            'login' => 'Al lid? Log in',
            'follow' => 'Volg ons en mis de lancering niet',
            'partners' => 'Bekijk onze partners',
        ],
        'pap' => [
            'kicker' => 'Algu nobo ta bini',
            'title' => 'E Dj\'aki mi ta! nobo ta kasi aki',
            'text' => 'Un website kompletamente nobo, mas partner i bo account direkto den bo telefon. Pre-ordená awor i bo account ta kla dia un.',
            'days' => 'dia', 'hours' => 'ora', 'mins' => 'minüt', 'secs' => 'sekònde',
            'cta' => 'Pre-ordená bo account',
            'note' => 'Accountnan pre-ordená ta kuminsá dia di lansamentu, asina bo no ta pèrdè ni un dia.',
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
    $strip = [];
    try {
        $strip = array_values(array_filter(partners(), fn($p) => !empty($p['image_id']) || !empty($p['has_picture'])));
    } catch (Throwable $e) {
    }
    $brand = "Dj'aki mi ta!";
    $titleHtml = str_replace(e($brand), '<span class="cd-brand">' . e($brand) . '</span>', e($c['title']));
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
    .cd-brand { display: inline-block; color: var(--gold); font-weight: 800; letter-spacing: .01em; text-shadow: 0 3px 14px rgba(0,0,0,.45); white-space: nowrap; }
    .cd h1 { text-shadow: 0 2px 12px rgba(0,0,0,.35); line-height: 1.1; }
    .cd.has-strip { padding-bottom: calc(clamp(64px, 11vh, 96px) + 8px); }
    .cd-strip { position: fixed; left: 0; right: 0; bottom: 0; height: clamp(64px, 11vh, 96px); overflow: hidden; background: rgba(6,30,8,.55);
      backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); border-top: 1px solid rgba(255,255,255,.18); display: flex; align-items: center; }
    .cd-track { display: flex; gap: 12px; width: max-content; animation: cd-roll var(--dur, 60s) linear infinite; padding-left: 12px; }
    .cd-strip:hover .cd-track { animation-play-state: paused; }
    .cd-chip { display: flex; align-items: center; gap: 10px; height: clamp(48px, 8.5vh, 74px); padding: 0 16px 0 0; background: rgba(255,255,255,.12); border-radius: 999px; color: #fff; text-decoration: none; white-space: nowrap; font-weight: 700; font-size: clamp(.8rem, 1.6vh, .95rem); }
    .cd-chip { flex: none; }
    .cd-chip img { height: 100%; width: auto; aspect-ratio: 1; flex: none; object-fit: cover; border-radius: 50%; border: 2px solid rgba(255,255,255,.8); }
    .cd-chip:hover { background: rgba(255,255,255,.25); color: #fff; }
    @keyframes cd-roll { to { transform: translateX(-50%); } }
    @media (prefers-reduced-motion: reduce) { .cd-strip { overflow-x: auto; } .cd-track { animation: none; } }
  </style>
</head>
<body>
<main class="cd<?= count($strip) >= 4 ? ' has-strip' : '' ?>">
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
    <h1><?= $titleHtml ?></h1>
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
<?php if (count($strip) >= 4): ?>
<nav class="cd-strip" aria-label="<?= e($c['partners']) ?>">
  <div class="cd-track" style="--dur:<?= max(30, count($strip) * 4) ?>s">
    <?php foreach ([0, 1] as $copy): foreach ($strip as $p): ?>
      <a class="cd-chip" href="/partner.php?id=<?= (int) $p['id'] ?>"<?= $copy ? ' aria-hidden="true" tabindex="-1"' : '' ?>><img src="<?= e(partner_image_url($p, 's')) ?>" alt="" loading="lazy" width="74" height="74"><?= e($p['name']) ?><?= (float) $p['best_percent'] > 0 ? ' · ' . e(discount_label($p['best_percent'], $p['low_percent'] ?? null)) : '' ?></a>
    <?php endforeach; endforeach; ?>
  </div>
</nav>
<?php endif; ?>
<?= jingle_html() ?>
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
    if (in_array($script, COUNTDOWN_OPEN, true) || str_starts_with($path, '/admin') || str_starts_with($path, '/partner/') || str_starts_with($path, '/api/') || $script === 'partner-login.php') {
        return;
    }
    // Staff can look at the full site before launch with ?peek=<preview key>
    if (($_GET['peek'] ?? '') === 'off') {
        unset($_SESSION['peek']);
        setcookie('djk_peek', '', ['expires' => time() - 3600, 'path' => '/']);
        unset($_COOKIE['djk_peek']);
    }
    // Logged-in staff: "Preview the live website" button in the backend
    if (function_exists('admin_user') && admin_user() !== null) {
        if (isset($_GET['preview'])) {
            if ($_GET['preview'] === '1') {
                $_SESSION['staff_preview'] = 1;
            } else {
                unset($_SESSION['staff_preview']);
                header('Location: /admin/');
                exit;
            }
        }
        if (!empty($_SESSION['staff_preview'])) {
            $GLOBALS['staff_preview'] = true;
            return;
        }
    }
    // Private team preview link: /?peek=<code>. The code is set in the backend (Settings),
    // or in config.php. Opening the link once is enough for that browser (cookie, 14 days).
    $codes = array_values(array_filter([(string) config('countdown_peek', ''), (string) setting('preview_code', '')], fn ($c) => strlen($c) >= 8));
    $given = (string) ($_GET['peek'] ?? ($_SESSION['peek'] ?? ($_COOKIE['djk_peek'] ?? '')));
    foreach ($codes as $c) {
        if (hash_equals($c, $given)) {
            $_SESSION['peek'] = $c;
            if (($_COOKIE['djk_peek'] ?? '') !== $c) {
                setcookie('djk_peek', $c, ['expires' => time() + 14 * 86400, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
            }
            $GLOBALS['team_preview'] = true;
            return;
        }
    }
    render_countdown();
    exit;
}
