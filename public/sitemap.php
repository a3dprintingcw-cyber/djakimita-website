<?php
// sitemap.xml (served via .htaccess). Every page in all three languages.
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
$base = rtrim((string) config('base_url'), '/');
$pages = ['/', '/partners.php', '/checkout.php', '/become-a-partner.php', '/faq.php', '/termsandconditions.php', '/privacy_policy.php'];
foreach (partners(null, '', 1000) as $p) {
    $pages[] = '/partner.php?id=' . (int) $p['id'];
}
$link = function (string $path, string $lang) use ($base): string {
    return $base . $path . (str_contains($path, '?') ? '&' : '?') . 'lang=' . $lang;
};
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
foreach ($pages as $path) {
    foreach (array_keys(LANGS) as $lang) {
        echo "  <url>\n    <loc>" . e($link($path, $lang)) . "</loc>\n";
        foreach (array_keys(LANGS) as $alt) {
            echo '    <xhtml:link rel="alternate" hreflang="' . e($alt === 'pap' ? 'pap' : $alt) . '" href="' . e($link($path, $alt)) . '"/>' . "\n";
        }
        echo "  </url>\n";
    }
}
echo "</urlset>\n";
