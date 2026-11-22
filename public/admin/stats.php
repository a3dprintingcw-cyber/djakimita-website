<?php
// Staff backend: charts. Sales, card use at partners and app views per day, week, month or year,
// the most popular partners, and downloads of all data.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

$period = in_array($_GET['by'] ?? '', ['day', 'week', 'month', 'year'], true) ? $_GET['by'] : 'day';
$defaults = ['day' => '-29 days', 'week' => '-11 weeks', 'month' => '-11 months', 'year' => '-4 years'];
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime($defaults[$period], strtotime($to)));
if ($period === 'week') {
    $from = date('Y-m-d', strtotime('monday this week', strtotime($from)));
} elseif ($period === 'month') {
    $from = date('Y-m-01', strtotime($from));
} elseif ($period === 'year') {
    $from = date('Y-01-01', strtotime($from));
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// Bucket keys from $from to $to
$keyOf = [
    'day' => fn($ts) => date('Y-m-d', $ts),
    'week' => fn($ts) => date('o-\WW', $ts),
    'month' => fn($ts) => date('Y-m', $ts),
    'year' => fn($ts) => date('Y', $ts),
][$period];
$step = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month', 'year' => '+1 year'][$period];
$buckets = [];
for ($ts = strtotime($from), $end = strtotime($to); $ts <= $end && count($buckets) < 400; $ts = strtotime($step, $ts)) {
    $buckets[$keyOf($ts)] = 0;
}
$sqlKey = fn(string $col) => [
    'day' => "DATE($col)",
    'week' => "CONCAT(LEFT(YEARWEEK($col, 3), 4), '-W', RIGHT(YEARWEEK($col, 3), 2))",
    'month' => "DATE_FORMAT($col, '%Y-%m')",
    'year' => "YEAR($col)",
][$period];
$range = ['f' => $from . ' 00:00:00', 't' => $to . ' 23:59:59'];

function series(PDO $db, string $sql, array $range, array $buckets): array
{
    $st = $db->prepare($sql);
    $st->execute($range);
    foreach ($st as $r) {
        if (array_key_exists((string) $r['k'], $buckets)) {
            $buckets[(string) $r['k']] = (float) $r['n'];
        }
    }
    return $buckets;
}

$orders = series($db, 'SELECT ' . $sqlKey('dia_orario') . ' k, COUNT(DISTINCT token) n FROM sale_receits WHERE status = 1 AND dia_orario BETWEEN :f AND :t GROUP BY k', $range, $buckets);
$scans = $views = $buckets;
try {
    $scans = series($db, 'SELECT ' . $sqlKey('date') . ' k, COUNT(*) n FROM client_package_info WHERE date BETWEEN :f AND :t GROUP BY k', $range, $buckets);
    $views = series($db, 'SELECT ' . $sqlKey('date') . ' k, SUM(view_count) n FROM client_view_history WHERE date BETWEEN :f AND :t GROUP BY k', $range, $buckets);
} catch (Throwable $e) {
}

// Money per currency in the period (card prices of paid orders, minus discount codes)
$money = $db->prepare("SELECT LOWER(COALESCE(s.currency, 'eur')) cur,
        SUM(CASE WHEN LOWER(s.currency) = 'xcg' THEN COALESCE(l.xcg, 0) WHEN LOWER(s.currency) = 'usd' THEN COALESCE(l.usd, p.dollars, 0) ELSE COALESCE(l.eur, p.eur, 0) END) total
    FROM sale_receits s
    LEFT JOIN sale_lengths sl ON sl.sale_id = s.id
    LEFT JOIN card_lengths l ON l.id = sl.length_id
    LEFT JOIN website_product_prices p ON p.product_id = s.package AND sl.sale_id IS NULL
    WHERE s.status = 1 AND s.dia_orario BETWEEN :f AND :t GROUP BY cur");
$money->execute($range);
$money = $money->fetchAll();
$off = [];
try {
    $o = $db->prepare("SELECT LOWER(COALESCE(s.currency, 'eur')) cur, SUM(d.amount) a FROM sale_discounts d JOIN sale_receits s ON s.id = d.sale_id WHERE s.status = 1 AND s.dia_orario BETWEEN :f AND :t GROUP BY cur");
    $o->execute($range);
    foreach ($o as $r) {
        $off[$r['cur']] = (float) $r['a'];
    }
} catch (Throwable $e) {
}
$byCard = $db->prepare('SELECT package, COUNT(*) n FROM sale_receits WHERE status = 1 AND dia_orario BETWEEN :f AND :t GROUP BY package ORDER BY n DESC');
$byCard->execute($range);
$byCard = $byCard->fetchAll();

// Most popular partners
$topScans = $topViews = [];
try {
    $st = $db->prepare('SELECT TRIM(v.name) name, v.id, COUNT(*) n FROM client_package_info i JOIN voucher_company v ON v.id = i.company_id WHERE i.date BETWEEN :f AND :t GROUP BY v.id ORDER BY n DESC LIMIT 15');
    $st->execute($range);
    $topScans = $st->fetchAll();
    $st = $db->prepare('SELECT TRIM(v.name) name, v.id, SUM(h.view_count) n FROM client_view_history h JOIN voucher_company v ON v.id = h.company_id WHERE h.date BETWEEN :f AND :t GROUP BY v.id ORDER BY n DESC LIMIT 15');
    $st->execute($range);
    $topViews = $st->fetchAll();
} catch (Throwable $e) {
}

function bar_chart(array $data, string $color, string $title): string
{
    $max = max(1, max($data ?: [0]));
    $n = max(1, count($data));
    $w = 720;
    $h = 180;
    $bw = $w / $n;
    $out = '<svg viewBox="0 0 ' . $w . ' ' . ($h + 34) . '" class="st-chart" role="img" aria-label="' . e($title) . '">';
    foreach ([0.5, 1] as $g) {
        $y = $h - $h * $g;
        $out .= '<line x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" stroke="#e3e9df"/><text x="2" y="' . ($y + 12) . '" font-size="11" fill="#8a968c">' . e(rtrim(rtrim(number_format($max * $g, 1, '.', ''), '0'), '.')) . '</text>';
    }
    $i = 0;
    $every = max(1, (int) ceil($n / 12));
    foreach ($data as $label => $v) {
        $bh = $v > 0 ? max(2, $h * $v / $max) : 0;
        $x = $i * $bw;
        $out .= '<rect x="' . round($x + $bw * 0.15, 1) . '" y="' . round($h - $bh, 1) . '" width="' . round($bw * 0.7, 1) . '" height="' . round($bh, 1) . '" rx="3" fill="' . $color . '"><title>' . e($label . ': ' . $v) . '</title></rect>';
        if ($i % $every === 0) {
            $short = strlen((string) $label) === 10 ? date('j M', strtotime((string) $label)) : (string) $label;
            $out .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h + 16) . '" font-size="11" text-anchor="middle" fill="#5b6a5d">' . e($short) . '</text>';
        }
        $i++;
    }
    return $out . '</svg>';
}

function top_list(array $rows, string $color): string
{
    if (!$rows) {
        return '<p class="ad-muted">No data in this period.</p>';
    }
    $max = max(1, (float) $rows[0]['n']);
    $out = '<div class="st-top">';
    foreach ($rows as $r) {
        $out .= '<div class="st-row"><span class="st-name"><a href="/admin/partners.php?id=' . (int) $r['id'] . '">' . e($r['name']) . '</a></span>'
            . '<span class="st-bar"><i style="width:' . round(100 * $r['n'] / $max, 1) . '%;background:' . $color . '"></i></span><b>' . (int) $r['n'] . '</b></div>';
    }
    return $out . '</div>';
}

$q = fn(array $extra) => '?' . http_build_query(array_merge(['by' => $period, 'from' => $from, 'to' => $to], $extra));
admin_head('Charts', 'stats');
?>
<style>
.st-chart{width:100%;height:auto;display:block}
.st-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.st-top{display:flex;flex-direction:column;gap:6px}
.st-row{display:grid;grid-template-columns:minmax(120px,40%) 1fr 44px;gap:10px;align-items:center;font-size:.9rem}
.st-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.st-bar{background:#eef3eb;border-radius:6px;height:14px;overflow:hidden}.st-bar i{display:block;height:100%;border-radius:6px}
.st-row b{text-align:right}
.st-tabs{display:flex;gap:6px;flex-wrap:wrap}.st-tabs a{padding:7px 14px;border-radius:999px;border:1px solid #cfd9ca;text-decoration:none;color:inherit;font-weight:600;background:#fff}
.st-tabs a.on{background:#0f3d14;color:#fff;border-color:#0f3d14}
.st-dl{display:flex;flex-wrap:wrap;gap:8px}
@media (max-width:860px){.st-grid{grid-template-columns:1fr}}
</style>
<h1>Charts</h1>
<form class="ad-filter" method="get">
  <div class="st-tabs">
    <?php foreach (['day' => 'Per day', 'week' => 'Per week', 'month' => 'Per month', 'year' => 'Per year'] as $k => $l): ?>
      <a href="?by=<?= $k ?>" class="<?= $period === $k ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="by" value="<?= e($period) ?>">
  <label class="ad-muted">from <input type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="ad-muted">to <input type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="btn btn-sm">Show</button>
</form>

<div class="ad-cards">
  <div class="ad-card"><b><?= (int) array_sum($orders) ?></b><span>Paid orders</span></div>
  <?php foreach ($money as $m): $net = (float) $m['total'] - ($off[$m['cur']] ?? 0); ?>
    <div class="ad-card"><b><?= e(admin_money($net, $m['cur'])) ?></b><span>Sales in <?= e(strtoupper($m['cur'])) ?><?= !empty($off[$m['cur']]) ? ' (after codes)' : '' ?></span></div>
  <?php endforeach; ?>
  <div class="ad-card"><b><?= (int) array_sum($scans) ?></b><span>Card used at partners</span></div>
  <div class="ad-card"><b><?= (int) array_sum($views) ?></b><span>Partner pages opened in the app</span></div>
</div>

<div class="ad-box"><h2 style="margin-top:0">Paid orders</h2><?= bar_chart($orders, '#3d8b40', 'Paid orders') ?></div>
<div class="st-grid">
  <div class="ad-box"><h2 style="margin-top:0">Card used at partners</h2><?= bar_chart($scans, '#e8b84a', 'Card used at partners') ?></div>
  <div class="ad-box"><h2 style="margin-top:0">Partner pages opened in the app</h2><?= bar_chart($views, '#5aa0d6', 'App views') ?></div>
</div>

<div class="st-grid">
  <div class="ad-box"><h2 style="margin-top:0">Most visited partners</h2><p class="ad-muted">Where the card was scanned most.</p><?= top_list($topScans, '#e8b84a') ?></div>
  <div class="ad-box"><h2 style="margin-top:0">Most viewed partners</h2><p class="ad-muted">Opened most in the app: what people find most interesting.</p><?= top_list($topViews, '#5aa0d6') ?></div>
</div>

<div class="ad-box">
  <h2 style="margin-top:0">Cards sold in this period</h2>
  <?php if (!$byCard): ?><p class="ad-muted">None.</p><?php endif; ?>
  <div class="st-top">
    <?php $mx = max(1, (int) ($byCard[0]['n'] ?? 1)); foreach ($byCard as $b): ?>
      <div class="st-row"><span class="st-name"><?= e((int) $b['package'] === ESIM_PACKAGE ? 'Flow eSIM' : card_name((int) $b['package'])) ?></span><span class="st-bar"><i style="width:<?= round(100 * $b['n'] / $mx, 1) ?>%;background:#3d8b40"></i></span><b><?= (int) $b['n'] ?></b></div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (admin_role() === 'admin'): ?>
<div class="ad-box" id="download">
  <h2 style="margin-top:0">Download data</h2>
  <p class="ad-muted">Opens in Excel or Google Sheets. Contains personal data: keep these files safe and delete them when you are done.</p>
  <div class="st-dl">
    <?php foreach (['orders' => 'Orders', 'customers' => 'Customers', 'cards' => 'Cards', 'holders' => 'Card holders', 'scans' => 'Card use at partners', 'views' => 'App views', 'partners' => 'Partners', 'freebies' => 'Freebies', 'visits' => 'Partner visits', 'codes' => 'Discount codes', 'wheel' => 'Spin the wheel'] as $k => $l): ?>
      <a class="btn btn-sm btn-outline" href="/admin/export.php?what=<?= $k ?>&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>">⬇ <?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
  <p class="ad-muted" style="margin-top:10px">Orders, card use and app views follow the dates above. The other lists are complete.</p>
</div>
<?php endif; ?>
<?php admin_foot();
