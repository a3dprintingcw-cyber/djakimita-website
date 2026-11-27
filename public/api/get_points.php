<?php
// Points for the app: ?id=<client id>&dt=<phone key>. Brings the points up to date first.
require __DIR__ . '/_auth.php';
$db = api_db();
if (!pts_on($db)) {
    api_json(['on' => false, 'balance' => 0, 'earned_12m' => 0, 'history' => []]);
}
$id = api_customer((int) ($_GET['id'] ?? 0), (string) ($_GET['dt'] ?? ''));
try {
    pts_sync($db, $id);
    $r = pts_rules($db);
    $hist = array_map(fn ($h) => [
        'id' => (int) $h['id'], 'label' => $h['label'], 'points' => (int) $h['points'],
        'date' => date('d-m-Y', strtotime($h['created_at'])),
        'pending' => (int) $h['is_pending'] ? date('d-m-Y', strtotime($h['available_at'])) : null,
    ], pts_history($db, $id));
    $st = $db->prepare("SELECT c.code, c.expires_at, r.title, r.partner_id, TRIM(v.name) AS partner FROM reward_claims c
                        JOIN rewards r ON r.id = c.reward_id LEFT JOIN voucher_company v ON v.id = r.partner_id
                        WHERE c.client_id = :c AND c.used_at IS NULL AND c.cancelled_at IS NULL AND c.expires_at > NOW() ORDER BY c.id DESC");
    $st->execute(['c' => $id]);
    $codes = array_map(fn ($c) => ['code' => $c['code'], 'title' => $c['title'], 'partner' => $c['partner'] ?: "Dj'aki mi ta!",
                                   'until' => date('d-m-Y', strtotime($c['expires_at']))], $st->fetchAll());
    api_json([
        'on' => true,
        'balance' => pts_balance($db, $id),
        'pending' => pts_pending($db, $id),
        'earned_12m' => pts_earned_12m($db, $id),
        'history' => $hist,
        'codes' => $codes,
        'invite_code' => invite_code($id),
        'buy' => (function () use ($db) {
            if (pts_setting($db, 'pts_buy_on', '1') !== '1') {
                return null;
            }
            $c = require __DIR__ . '/../../app/config.local.php';
            $sentoo = ($c['sentoo_merchant_id'] ?? '') !== '' && ($c['sentoo_secret'] ?? '') !== '';
            $mollie = ($c['mollie_api_key'] ?? '') !== '';
            $cur = [1 => $sentoo ? 'xcg' : ($mollie ? 'usd' : null), 2 => $mollie ? 'eur' : null];
            if (!$cur[1] && !$cur[2]) {
                return null;
            }
            return ['currency' => $cur, 'packs' => pts_packs($db)];
        })(),
        'birthday' => ($bd = pts_birthday_offer($db, $id)) ? ['name' => $bd['name'], 'points' => $bd['points'], 'test' => $bd['test']] : null,
        'rules' => [
            'visit' => $r['pts_visit'], 'visit_day_max' => $r['pts_visit_day_max'], 'purchase_month' => $r['pts_purchase_month'],
            'invite' => $r['pts_invite'], 'invited' => $r['pts_invited'], 'invite_hold_days' => $r['invite_hold_days'],
            'invite_year_max' => $r['invite_year_max'], 'birthday' => $r['pts_birthday'],
        ],
    ]);
} catch (Throwable $e) {
    error_log('get_points: ' . $e->getMessage());
    api_json(['on' => true, 'balance' => 0, 'earned_12m' => 0, 'history' => [], 'error' => 1]);
}
