<?php
// Daily reminder emails. Run once a day from cron (cPanel "Cron Jobs" on GoDaddy):
//   php /path/to/app/cron/reminders.php
// Options: --dry-run (show what would be sent, send nothing)
//
//  verify3 / verify10 : card holders who did not do the one-time check, 3 and 10 days after paying
//  exp30 / exp7       : card expires in 30 / 7 days and the customer has no newer card
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$dry = in_array('--dry-run', $argv, true);
// Only orders made on the new website get verify reminders (older customers are handled separately).
$since = (string) config('reminders_since', '2026-12-01');
$base = rtrim((string) config('base_url'), '/');
$sent = 0;

function already_sent(string $kind, int $ref): bool
{
    $st = db()->prepare('SELECT 1 FROM reminders_sent WHERE kind = :k AND ref_id = :r');
    $st->execute(['k' => $kind, 'r' => $ref]);
    return (bool) $st->fetchColumn();
}

function mark_sent(string $kind, int $ref): void
{
    db()->prepare('INSERT IGNORE INTO reminders_sent (kind, ref_id, sent_at) VALUES (:k, :r, NOW())')->execute(['k' => $kind, 'r' => $ref]);
}

function mail_wrap(string $title, string $body): string
{
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#17221a">'
        . '<div style="background:#187c19;color:#fff;padding:20px 24px;border-radius:12px 12px 0 0;font-size:22px;font-weight:bold">Dj\'aki mi ta!</div>'
        . '<div style="border:1px solid #dfe7dc;border-top:0;padding:24px;border-radius:0 0 12px 12px">'
        . '<h1 style="font-size:20px;margin:0 0 12px">' . $title . '</h1>' . $body
        . '<p style="color:#4f5d52;font-size:13px">Questions? contact@djakimita.com</p></div></div>';
}

function button(string $href, string $label): string
{
    return '<p><a href="' . e($href) . '" style="display:inline-block;background:#187c19;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:bold">' . e($label) . '</a></p>';
}

// ---------------------------------------------------------------------------
// 1. Verify reminders
// ---------------------------------------------------------------------------
$orders = db()->prepare('SELECT s.id, s.email, DATEDIFF(CURDATE(), DATE(s.dia_orario)) AS days, cp.expire
                         FROM sale_receits s
                         JOIN clients_package cp ON cp.id = s.client_package_id AND cp.expire >= CURDATE()
                         WHERE s.status = 1 AND s.package <> :esim AND s.dia_orario >= :since
                           AND DATEDIFF(CURDATE(), DATE(s.dia_orario)) BETWEEN 3 AND 30');
$orders->execute(['esim' => ESIM_PACKAGE, 'since' => $since]);
foreach ($orders->fetchAll() as $o) {
    $kind = (int) $o['days'] >= 10 ? 'verify10' : 'verify3';
    if (already_sent($kind, (int) $o['id'])) {
        continue;
    }
    $todo = array_filter(order_holders((int) $o['id']), fn($h) => !$h['v'] || (int) $h['v']['status'] === VERIFY_REJECTED);
    if (!$todo) {
        continue;
    }
    $names = implode(', ', array_map(fn($h) => e($h['name']), $todo));
    $html = mail_wrap('One small step left for your card',
        '<p>Your card is paid and active. To use it at our partners, these card holders still need to do the one-time check: <strong>' . $names . '</strong>.</p>'
        . '<p>It takes 2 minutes: one selfie and one photo of the passport page. After that you just scan at the shop, no passport needed. We delete the passport photo right after the check.</p>'
        . button(verify_link((int) $o['id']), 'Verify my card'));
    echo ($dry ? '[dry] ' : '') . "$kind order #{$o['id']} -> {$o['email']}\n";
    if (!$dry && brevo_send((string) $o['email'], "Dj'aki mi ta!: verify your card (2 minutes)", $html)) {
        mark_sent($kind, (int) $o['id']);
        $sent++;
    }
}

// ---------------------------------------------------------------------------
// 2. Expiry reminders (skip customers who already have a newer card)
// ---------------------------------------------------------------------------
$cards = db()->query('SELECT cp.id, cp.users_id, cp.package_id, cp.expire, DATEDIFF(cp.expire, CURDATE()) AS left_days, c.email, c.username
                      FROM clients_package cp JOIN clients c ON c.id = cp.users_id AND c.active = 1
                      WHERE DATEDIFF(cp.expire, CURDATE()) IN (30, 7)
                        AND NOT EXISTS (SELECT 1 FROM clients_package n WHERE n.users_id = cp.users_id AND n.expire > cp.expire)');
foreach ($cards->fetchAll() as $c) {
    $kind = (int) $c['left_days'] === 30 ? 'exp30' : 'exp7';
    if (already_sent($kind, (int) $c['id']) || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        continue;
    }
    $cardKey = '';
    foreach (CARD_TYPES as $k => $ids) {
        if ($ids['package'] === (int) $c['package_id']) {
            $cardKey = $k;
        }
    }
    $html = mail_wrap('Your card expires on ' . e(date('d-m-Y', strtotime($c['expire']))),
        '<p>Hi ' . e($c['username'] ?: 'there') . ',</p>'
        . '<p>Your ' . e(package_label((int) $c['package_id'])) . ' card is valid for ' . (int) $c['left_days'] . ' more days. Going to Curaçao again? Renew in a minute and keep your discounts. You do not need to do the passport check again.</p>'
        . button($base . '/checkout.php' . ($cardKey ? '?card=' . $cardKey : ''), 'Renew my card'));
    echo ($dry ? '[dry] ' : '') . "$kind card #{$c['id']} -> {$c['email']}\n";
    if (!$dry && brevo_send((string) $c['email'], "Dj'aki mi ta!: your card expires in " . (int) $c['left_days'] . ' days', $html)) {
        mark_sent($kind, (int) $c['id']);
        $sent++;
    }
}

echo "Done. Sent: $sent\n";
