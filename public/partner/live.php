<?php
// Partner portal: new visits since ?after=<visit id>, so the open page shows a scan as soon as a
// customer scans this partner's QR code in the app. JSON, logged-in partner only.
require __DIR__ . '/../../app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$u = pp_user();
if (!$u) {
    http_response_code(401);
    echo '{"login":true}';
    exit;
}
$cid = (int) $u['company_id'];
$after = (int) ($_GET['after'] ?? 0);
$db = db();
if ($after <= 0) {
    echo json_encode(['last' => (int) $db->query('SELECT COALESCE(MAX(id), 0) FROM client_package_info WHERE company_id = ' . $cid)->fetchColumn(), 'visits' => []]);
    exit;
}
$st = $db->prepare('SELECT i.id, i.date, p.users_id AS customer FROM client_package_info i
                    LEFT JOIN clients_package p ON p.id = i.users_package_id
                    WHERE i.company_id = :c AND i.id > :a ORDER BY i.id LIMIT 5');
$st->execute(['c' => $cid, 'a' => $after]);
$out = [];
$last = $after;
foreach ($st->fetchAll() as $v) {
    $last = max($last, (int) $v['id']);
    $a = $v['customer'] ? pp_lookup((int) $v['customer'], $cid) : null;
    if (!$a) {
        continue;
    }
    $_SESSION['pp_seen'][(int) $a['customer']] = time();
    $d = $a['deal'];
    $deal = '';
    if ($d && (float) $d['the_percent'] >= 100) {
        $deal = 'Free';
    } elseif ($d && (float) $d['the_percent'] > 0) {
        $deal = (int) round((float) $d['the_percent']) . '% off';
    } elseif ($a['deal_text'] !== '') {
        $deal = $a['deal_text'];
    }
    $photo = 0;
    $names = [];
    foreach ($a['holders'] as $h) {
        $names[] = pp_short_name($h['name']);
        if (!$photo && $h['photo']) {
            $photo = (int) $h['photo'];
        }
    }
    $out[] = [
        'time' => date('H:i', strtotime((string) $v['date'])),
        'who' => pp_short_name((string) $a['first'], (string) $a['last']) ?: ($names[0] ?? 'Customer'),
        'people' => $names,
        'type' => $a['type'],
        'account' => PP_ACCOUNT_PREFIX . (int) $a['customer'],
        'valid_until' => $a['expire'] ? date('d-m-Y', strtotime((string) $a['expire'])) : '',
        'valid' => (bool) $a['valid'],
        'deal' => $deal,
        'deal_text' => $a['deal_text'],
        'left' => $a['visits_total'] === null ? null : max(0, $a['visits_total'] - $a['visits_used']),
        'total' => $a['visits_total'],
        'photo' => $photo ? '/partner/photo.php?v=' . $photo . '&a=' . (int) $a['customer'] : '',
    ];
}
echo json_encode(['last' => $last, 'visits' => $out], JSON_UNESCAPED_UNICODE);
