<?php
// Shared page header, footer and icons.

function icon(string $name, int $size = 20): string
{
    $paths = [
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'qr' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4z"/><path d="M14 14h2v2h-2zM18 18h2v2h-2zM18 14h2M14 18v2"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'wifi' => '<path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M2 9a15 15 0 0 1 20 0"/><circle cx="12" cy="19.5" r="1"/>',
        'check' => '<path d="m5 12 4.5 4.5L19 7"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'back' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/>',
        'facebook' => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8a0 0 0 0 1 0 0z"/>',
        'tiktok' => '<path d="M14 3v11a4 4 0 1 1-4-4M14 3c.5 3 2.5 5 5.5 5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'id' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16a3.4 3.4 0 0 1 6.4 0M14 10h4M14 13.5h3"/>',
        'shield' => '<path d="M12 3 5 6v6c0 4.5 3 7.8 7 9 4-1.2 7-4.5 7-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function lang_switch(): string
{
    $out = '<div class="lang" role="group" aria-label="Language">';
    foreach (LANGS as $code => $label) {
        $q = $_GET;
        $q['lang'] = $code;
        $cls = current_lang() === $code ? ' class="on" aria-current="true"' : '';
        $out .= '<a' . $cls . ' href="?' . e(http_build_query($q)) . '">' . $label . '</a>';
    }
    return $out . '</div>';
}

function page_header(string $title, string $active = '', string $description = ''): void
{
    $desc = $description ?: t('hero.text', ['n' => 50]);
    ?>
<!doctype html>
<html lang="<?= e(current_lang() === 'pap' ? 'pap' : current_lang()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <?php if (config('preview_pass')): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <?php
    $u = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    parse_str((string) ($u['query'] ?? ''), $qs);
    unset($qs['lang']);
    $canon = rtrim((string) config('base_url'), '/') . ($u['path'] ?? '/');
    $withLang = fn(string $l) => $canon . '?' . http_build_query($qs + ['lang' => $l]);
  ?>
  <link rel="canonical" href="<?= e($withLang(current_lang())) ?>">
  <?php foreach (array_keys(LANGS) as $l): ?><link rel="alternate" hreflang="<?= e($l) ?>" href="<?= e($withLang($l)) ?>">
  <?php endforeach; ?><link rel="alternate" hreflang="x-default" href="<?= e($canon . ($qs ? '?' . http_build_query($qs) : '')) ?>">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:image" content="<?= e(config('base_url') . '/assets/img/og.jpg') ?>">
  <link rel="icon" href="/assets/img/logo-mark.png">
  <link rel="preload" href="/assets/fonts/bricolage-grotesque-latin-800-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/assets/css/site.css?v=<?= (int) @filemtime(__DIR__ . '/../public/assets/css/site.css') ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="nav">
  <div class="wrap nav-in">
    <a class="brand" href="/" aria-label="Dj'aki mi ta! home">
      <img src="/assets/img/logo-mark.png" alt="" width="40" height="40">
      <span class="brand-name">Dj'aki <em>mi ta!</em></span>
    </a>
    <nav class="links" id="nav-menu" aria-label="Main">
      <a href="/partners.php"<?= $active === 'partners' ? ' aria-current="page"' : '' ?>><?= e(t('nav.partners')) ?></a>
      <a href="/#how"><?= e(t('nav.how')) ?></a>
      <a href="/#cards"><?= e(t('nav.cards')) ?></a>
      <a href="/faq.php"><?= e(t('nav.faq')) ?></a>
      <a class="m-only" href="<?= current_client() ? '/account.php' : '/login.php' ?>"><?= e(t(current_client() ? 'ac.title' : 'nav.login')) ?></a>
      <div class="m-only m-lang"><?= lang_switch() ?></div>
    </nav>
    <div class="nav-right">
      <span class="d-only"><?= lang_switch() ?></span>
      <a class="login d-only" href="<?= current_client() ? '/account.php' : '/login.php' ?>"><?= e(t(current_client() ? 'ac.title' : 'nav.login')) ?></a>
      <a class="btn btn-sm" href="/#cards"><?= e(t('nav.cta')) ?></a>
      <button class="menu-btn" type="button" aria-expanded="false" aria-controls="nav-menu" aria-label="Menu"><?= icon('menu', 24) ?></button>
    </div>
  </div>
  <script>
    (function () {
      var b = document.querySelector('.menu-btn'), nav = document.querySelector('.nav');
      b.addEventListener('click', function () { var o = nav.classList.toggle('open'); b.setAttribute('aria-expanded', o); });
      document.querySelectorAll('#nav-menu a').forEach(function (a) { a.addEventListener('click', function () { nav.classList.remove('open'); b.setAttribute('aria-expanded', 'false'); }); });
    })();
  </script>
</header>
<main id="main">
    <?php
}

function page_footer(): void
{
    ?>
</main>
<footer class="foot">
  <div class="wrap foot-grid">
    <div>
      <img src="/assets/img/logo-full.png" alt="Dj'aki mi ta!" width="120" height="154" class="foot-logo">
      <p class="muted"><?= e(t('footer.tag')) ?></p>
      <div class="social">
        <a href="https://www.instagram.com/djakimita/" aria-label="Instagram" rel="noopener"><?= icon('instagram') ?></a>
        <a href="https://www.facebook.com/share/1ZT1D2dMHx/" aria-label="Facebook" rel="noopener"><?= icon('facebook') ?></a>
        <a href="https://www.tiktok.com/@djakimita.cw" aria-label="TikTok" rel="noopener"><?= icon('tiktok') ?></a>
      </div>
    </div>
    <div>
      <h3><?= e(t('footer.links')) ?></h3>
      <ul>
        <li><a href="/partners.php"><?= e(t('nav.partners')) ?></a></li>
        <li><a href="/#cards"><?= e(t('nav.cards')) ?></a></li>
        <li><a href="/become-a-partner.php"><?= e(t('join.cta')) ?></a></li>
        <li><a href="/faq.php"><?= e(t('nav.faq')) ?></a></li>
      </ul>
    </div>
    <div>
      <h3><?= e(t('footer.contact')) ?></h3>
      <ul>
        <li><a href="mailto:contact@djakimita.com"><?= icon('mail', 16) ?> contact@djakimita.com</a></li>
        <li>Curaçao · Nederland</li>
        <li><a href="/termsandconditions.php"><?= e(t('footer.terms')) ?></a></li>
        <li><a href="/privacy_policy.php"><?= e(t('footer.privacy')) ?></a></li>
      </ul>
    </div>
  </div>
  <div class="wrap foot-bottom">
    <span>© <?= date('Y') ?> Deals With Discounts. <?= e(t('footer.rights')) ?> · <?= e(t('footer.madeby')) ?> <a href="https://a3dprinting.com" rel="noopener">A3D Printing</a></span>
    <?= lang_switch() ?>
  </div>
</footer>
</body>
</html>
    <?php
}
