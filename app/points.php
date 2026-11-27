<?php
// Points ("Punto"). One balance per customer, for Dj'aki mi ta! and Rib'e lugá.
//
// Earning (numbers are set in the backend under Points):
//   - visit:    a registered visit at a partner. Max 1 per partner per day and max pts_visit_day_max per day.
//   - purchase: a paid account (not free accounts). Points per month of account length (10 days = half a month).
//               Taken back if the order is no longer paid (refund).
//   - invite:   a friend buys their first account with your invite code (DJ + account number). Both get points.
//               Points arrive invite_hold_days after the friend paid (refund window). Max invite_year_max per year.
//               Not for yourself (same customer, email or phone).
//   - birthday: once per year, from the birthday until 30 days after, with an account that was bought at least
//               30 days before the birthday.
// Spending: rewards set by staff (cost in points). A code works once, 30 days; unused codes give the points back.
//
// Abuse protection: every point is one ledger row with a unique (kind, ref), so nothing is counted twice.
// The app must send the phone key (one account, one phone) to see or spend points. Redeeming needs a valid account.
// Only activity from points_start (the day points were switched on) counts.
// Needs only a PDO, so the app API (no bootstrap) can use it.

function pts_setting(PDO $db, string $k, string $default): string
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach ($db->query("SELECT k, v FROM site_settings WHERE k LIKE 'pts\\_%' OR k LIKE 'points\\_%' OR k LIKE 'invite\\_%'") as $r) {
                $all[$r['k']] = (string) $r['v'];
            }
        } catch (Throwable $e) {
        }
    }
    return (isset($all[$k]) && $all[$k] !== '') ? $all[$k] : $default;
}

const PTS_DEFAULTS = [
    'points_on' => '0',
    'pts_visit' => '10',
    'pts_visit_day_max' => '3',
    'pts_purchase_month' => '10',
    'pts_invite' => '100',
    'pts_invited' => '50',
    'invite_hold_days' => '14',
    'invite_year_max' => '10',
    'pts_birthday' => '50',
    'pts_extra_scan' => '50',      // points for extra visits at a partner when all visits there are used
    'extra_scan_count' => '1',     // how many extra visits you get for that
    'pts_buy_on' => '1',           // customers can buy points with money
];

// Point packs for sale (backend → Points). Prices: xcg (Sentoo), usd and eur (Mollie).
const PTS_PACKS_DEFAULT = [
    ['points' => 250, 'xcg' => 15, 'usd' => 8.5, 'eur' => 8],
    ['points' => 500, 'xcg' => 27, 'usd' => 15, 'eur' => 14],
    ['points' => 1000, 'xcg' => 50, 'usd' => 28, 'eur' => 26],
];

function pts_packs(PDO $db): array
{
    $raw = pts_setting($db, 'points_packs', '');
    $p = $raw !== '' ? json_decode($raw, true) : null;
    $p = is_array($p) && $p ? $p : PTS_PACKS_DEFAULT;
    return array_values(array_filter($p, fn ($x) => (int) ($x['points'] ?? 0) > 0));
}

function pts_rules(PDO $db): array
{
    $r = [];
    foreach (PTS_DEFAULTS as $k => $v) {
        $r[$k] = (int) pts_setting($db, $k, $v);
    }
    $r['points_start'] = pts_setting($db, 'points_start', date('Y-m-d'));
    return $r;
}

function pts_on(PDO $db): bool
{
    return pts_setting($db, 'points_on', '0') === '1';
}

function invite_code(int $clientId): string
{
    return 'DJ' . (765459 + $clientId);
}

/** Customer id from an invite code, or 0. */
function invite_client(PDO $db, string $code): int
{
    if (!preg_match('/^DJ(\d{6,9})$/', strtoupper(trim($code)), $m)) {
        return 0;
    }
    $id = (int) $m[1] - 765459;
    if ($id <= 0) {
        return 0;
    }
    $st = $db->prepare('SELECT id FROM clients WHERE id = :i');
    $st->execute(['i' => $id]);
    return (int) $st->fetchColumn();
}

/** Adds a ledger row once. Returns true when it was new. */
function pts_add(PDO $db, int $clientId, int $points, string $kind, string $ref, string $label, ?string $when = null, ?string $available = null): bool
{
    if ($points === 0 && $kind !== 'adjust') {
        return false;
    }
    // Times come from the database clock (NOW()), the same clock the balance is checked against
    $st = $db->prepare('INSERT IGNORE INTO points_ledger (client_id, points, kind, ref, label, created_at, available_at)
                        VALUES (:c, :p, :k, :r, :l, COALESCE(:w, NOW()), COALESCE(:a, :w2, NOW()))');
    $st->execute(['c' => $clientId, 'p' => $points, 'k' => $kind, 'r' => $ref, 'l' => mb_substr($label, 0, 150),
                  'w' => $when, 'a' => $available, 'w2' => $when]);
    return $st->rowCount() > 0;
}

function pts_has(PDO $db, string $kind, string $ref): bool
{
    $st = $db->prepare('SELECT 1 FROM points_ledger WHERE kind = :k AND ref = :r');
    $st->execute(['k' => $kind, 'r' => $ref]);
    return (bool) $st->fetchColumn();
}

/** Brings one customer's points up to date. Cheap enough to run on every points request. */
function pts_sync(PDO $db, int $clientId): void
{
    if ($clientId <= 0 || !pts_on($db)) {
        return;
    }
    $r = pts_rules($db);
    $start = $r['points_start'] . ' 00:00:00';

    // Visits
    if ($r['pts_visit'] > 0) {
        $st = $db->prepare('SELECT i.id, i.company_id, i.date, TRIM(v.name) AS name FROM client_package_info i
                            JOIN clients_package p ON p.id = i.users_package_id
                            LEFT JOIN voucher_company v ON v.id = i.company_id
                            WHERE p.users_id = :c AND i.date >= :s ORDER BY i.date, i.id');
        $st->execute(['c' => $clientId, 's' => $start]);
        $done = $db->prepare("SELECT ref FROM points_ledger WHERE client_id = :c AND kind IN ('visit', 'visit_none')");
        $done->execute(['c' => $clientId]);
        $seen = array_flip($done->fetchAll(PDO::FETCH_COLUMN));
        // Count what was already given per day and per partner per day
        $perDay = [];
        $perPartnerDay = [];
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $given = $db->prepare("SELECT ref FROM points_ledger WHERE client_id = :c AND kind = 'visit'");
        $given->execute(['c' => $clientId]);
        $givenRefs = array_flip($given->fetchAll(PDO::FETCH_COLUMN));
        foreach ($rows as $v) {
            $day = substr((string) $v['date'], 0, 10);
            if (isset($givenRefs[(string) $v['id']])) {
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                $perPartnerDay[$day . ':' . $v['company_id']] = 1;
            }
        }
        foreach ($rows as $v) {
            $ref = (string) $v['id'];
            if (isset($seen[$ref])) {
                continue;
            }
            $day = substr((string) $v['date'], 0, 10);
            $pk = $day . ':' . $v['company_id'];
            if (isset($perPartnerDay[$pk]) || ($perDay[$day] ?? 0) >= $r['pts_visit_day_max']) {
                // remember it got no points, so it is not looked at again
                $db->prepare("INSERT IGNORE INTO points_ledger (client_id, points, kind, ref, label, created_at, available_at) VALUES (:c, 0, 'visit_none', :r, '', :w, :w)")
                    ->execute(['c' => $clientId, 'r' => $ref, 'w' => $v['date']]);
                continue;
            }
            if (pts_add($db, $clientId, $r['pts_visit'], 'visit', $ref, 'Visit: ' . ($v['name'] ?: 'partner'), (string) $v['date'])) {
                $perDay[$day] = ($perDay[$day] ?? 0) + 1;
                $perPartnerDay[$pk] = 1;
            }
        }
    }

    // Accounts bought (paid card rows; free "given" accounts have status 2 and get nothing)
    $st = $db->prepare('SELECT s.id, s.package, s.status, s.dia_orario, l.days, l.months FROM sale_receits s
                        LEFT JOIN sale_lengths sl ON sl.sale_id = s.id LEFT JOIN card_lengths l ON l.id = sl.length_id
                        WHERE s.user_id = :c AND s.package BETWEEN 1 AND 5 AND s.dia_orario >= :s');
    $st->execute(['c' => $clientId, 's' => $start]);
    $names = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior'];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $ref = (string) $o['id'];
        if ((int) $o['status'] === 1) {
            $months = $o['months'] !== null ? (float) $o['months'] : ($o['days'] !== null ? 0.5 : 12.0);
            $p = (int) round($r['pts_purchase_month'] * $months);
            if (!pts_has($db, 'purchase', $ref) && !pts_has($db, 'purchase_back', $ref)) {
                pts_add($db, $clientId, $p, 'purchase', $ref, ($names[(int) $o['package']] ?? '') . ' account bought', (string) $o['dia_orario']);
            }
        } elseif (pts_has($db, 'purchase', $ref)) {
            $g = $db->prepare("SELECT points FROM points_ledger WHERE kind = 'purchase' AND ref = :r");
            $g->execute(['r' => $ref]);
            pts_add($db, $clientId, -(int) $g->fetchColumn(), 'purchase_back', $ref, 'Order refunded: points taken back');
        }
    }

    // Invites: as the inviter, and as the new friend
    pts_sync_invites($db, $r, 'inviter_id = :c', $clientId, $start);
    pts_sync_invites($db, $r, 's.user_id = :c', $clientId, $start);

    // Reward codes that ran out unused: points back
    $st = $db->prepare('SELECT c.id, c.points, r.title FROM reward_claims c JOIN rewards r ON r.id = c.reward_id
                        WHERE c.client_id = :c AND c.used_at IS NULL AND c.cancelled_at IS NULL AND c.expires_at < NOW()');
    $st->execute(['c' => $clientId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $db->prepare('UPDATE reward_claims SET cancelled_at = NOW() WHERE id = :i')->execute(['i' => $c['id']]);
        pts_add($db, $clientId, (int) $c['points'], 'refund', (string) $c['id'], 'Code not used: points back (' . $c['title'] . ')');
    }
}

/**
 * Birthday gift: the customer claims it in the app (birthday screen with confetti).
 * Real: once per year, from the birthday until 30 days after, with a valid account bought at least 30 days before.
 * Test (staff button on the customer page): shows the screen once today, gives no points.
 * Returns null or ['name', 'points', 'test', 'ref'].
 */
function pts_birthday_offer(PDO $db, int $clientId): ?array
{
    if ($clientId <= 0 || !pts_on($db)) {
        return null;
    }
    $r = pts_rules($db);
    $st = $db->prepare('SELECT TRIM(username) FROM clients WHERE id = :c');
    $st->execute(['c' => $clientId]);
    $name = (string) $st->fetchColumn();
    $today = (string) $db->query('SELECT CURDATE()')->fetchColumn();

    $st = $db->prepare('SELECT v FROM site_settings WHERE k = :k');
    $st->execute(['k' => 'bday_test_' . $clientId]);
    if ((string) $st->fetchColumn() === $today) {
        return ['name' => $name, 'points' => $r['pts_birthday'], 'test' => true, 'ref' => ''];
    }
    if ($r['pts_birthday'] <= 0) {
        return null;
    }
    $st = $db->prepare('SELECT h.client_birthdate, TRIM(h.name) AS hname, MIN(s.dia_orario) AS bought FROM sale_receits s
                        JOIN client_package_customers h ON h.order_id = s.id
                        WHERE s.user_id = :c AND s.status IN (1, 2) AND h.client_birthdate IS NOT NULL
                        GROUP BY h.id ORDER BY h.id LIMIT 1');
    $st->execute(['c' => $clientId]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b || !pts_has_valid_account($db, $clientId)) {
        return null;
    }
    $y = (int) substr($today, 0, 4);
    $md = substr((string) $b['client_birthdate'], 5, 5);
    if ($md === '02-29' && !checkdate(2, 29, $y)) {
        $md = '02-28';
    }
    $bday = $y . '-' . $md;
    $ref = $clientId . ':' . $y;
    if ($today < $bday || $today > date('Y-m-d', strtotime($bday . ' +30 days')) || $bday < $r['points_start']
        || strtotime((string) $b['bought']) > strtotime($bday . ' -30 days') || pts_has($db, 'birthday', $ref)) {
        return null;
    }
    return ['name' => $name !== '' ? $name : (string) strtok((string) $b['hname'], ' '), 'points' => $r['pts_birthday'], 'test' => false, 'ref' => $ref];
}

/** Claim the birthday gift. Returns the offer that was claimed, or null. */
function pts_birthday_claim(PDO $db, int $clientId): ?array
{
    $o = pts_birthday_offer($db, $clientId);
    if (!$o) {
        return null;
    }
    if ($o['test']) {
        $db->prepare('DELETE FROM site_settings WHERE k = :k')->execute(['k' => 'bday_test_' . $clientId]);
        return $o;
    }
    return pts_add($db, $clientId, $o['points'], 'birthday', $o['ref'], 'Happy birthday!') ? $o : null;
}

function pts_sync_invites(PDO $db, array $r, string $where, int $clientId, string $start): void
{
    $st = $db->prepare("SELECT i.sale_id, i.inviter_id, s.user_id AS friend, s.status, s.dia_orario, s.email,
                               (SELECT c.phone FROM clients c WHERE c.id = s.user_id) AS fphone,
                               (SELECT c.email FROM clients c WHERE c.id = i.inviter_id) AS iemail,
                               (SELECT c.phone FROM clients c WHERE c.id = i.inviter_id) AS iphone,
                               (SELECT TRIM(c.username) FROM clients c WHERE c.id = s.user_id) AS fname,
                               (SELECT TRIM(c.username) FROM clients c WHERE c.id = i.inviter_id) AS iname
                        FROM sale_invites i JOIN sale_receits s ON s.id = i.sale_id
                        WHERE $where AND s.dia_orario >= :s");
    $st->execute(['c' => $clientId, 's' => $start]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $iv) {
        $ref = (string) $iv['sale_id'];
        $inviter = (int) $iv['inviter_id'];
        $friend = (int) $iv['friend'];
        $paid = (int) $iv['status'] === 1;
        if (!$paid) {
            foreach (['invite' => $inviter, 'invited' => $friend] as $k => $who) {
                if (pts_has($db, $k, $ref) && !pts_has($db, $k . '_back', $ref)) {
                    $g = $db->prepare('SELECT points FROM points_ledger WHERE kind = :k AND ref = :r');
                    $g->execute(['k' => $k, 'r' => $ref]);
                    pts_add($db, $who, -(int) $g->fetchColumn(), $k . '_back', $ref, 'Invite order refunded: points taken back');
                }
            }
            continue;
        }
        if (pts_has($db, 'invite', $ref) || pts_has($db, 'invite_none', $ref)) {
            continue;
        }
        // Not yourself, and the friend must be new: no paid account before this order
        $same = $friend === $inviter
            || strtolower(trim((string) $iv['email'])) === strtolower(trim((string) $iv['iemail']))
            || (trim((string) $iv['fphone']) !== '' && preg_replace('/\D/', '', (string) $iv['fphone']) === preg_replace('/\D/', '', (string) $iv['iphone']));
        $before = $db->prepare('SELECT COUNT(*) FROM sale_receits WHERE user_id = :u AND status IN (1, 2) AND package BETWEEN 1 AND 5 AND id < :id');
        $before->execute(['u' => $friend, 'id' => $iv['sale_id']]);
        $year = $db->prepare("SELECT COUNT(*) FROM points_ledger WHERE client_id = :c AND kind = 'invite' AND created_at > NOW() - INTERVAL 12 MONTH");
        $year->execute(['c' => $inviter]);
        if ($same || (int) $before->fetchColumn() > 0 || (int) $year->fetchColumn() >= $r['invite_year_max']) {
            $db->prepare("INSERT IGNORE INTO points_ledger (client_id, points, kind, ref, label, created_at, available_at) VALUES (:c, 0, 'invite_none', :r, '', NOW(), NOW())")
                ->execute(['c' => $inviter, 'r' => $ref]);
            continue;
        }
        $avail = date('Y-m-d H:i:s', strtotime((string) $iv['dia_orario'] . ' +' . $r['invite_hold_days'] . ' days'));
        pts_add($db, $inviter, $r['pts_invite'], 'invite', $ref, 'You invited ' . ($iv['fname'] ?: 'a friend'), null, $avail);
        pts_add($db, $friend, $r['pts_invited'], 'invited', $ref, 'Welcome! Invited by ' . ($iv['iname'] ?: 'a friend'), null, $avail);
    }
}

function pts_balance(PDO $db, int $clientId): int
{
    $st = $db->prepare('SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE client_id = :c AND available_at <= NOW()');
    $st->execute(['c' => $clientId]);
    return (int) $st->fetchColumn();
}

function pts_pending(PDO $db, int $clientId): int
{
    $st = $db->prepare('SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE client_id = :c AND available_at > NOW()');
    $st->execute(['c' => $clientId]);
    return (int) $st->fetchColumn();
}

function pts_earned_12m(PDO $db, int $clientId): int
{
    $st = $db->prepare("SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE client_id = :c AND points > 0
                        AND kind NOT IN ('refund', 'adjust', 'bought') AND created_at > NOW() - INTERVAL 12 MONTH AND available_at <= NOW()");
    $st->execute(['c' => $clientId]);
    return (int) $st->fetchColumn();
}

function pts_history(PDO $db, int $clientId, int $limit = 50): array
{
    $st = $db->prepare("SELECT id, label, points, created_at, available_at, (available_at > NOW()) AS is_pending FROM points_ledger
                        WHERE client_id = :c AND points <> 0 ORDER BY created_at DESC, id DESC LIMIT " . (int) $limit);
    $st->execute(['c' => $clientId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pts_has_valid_account(PDO $db, int $clientId): bool
{
    $st = $db->prepare('SELECT COUNT(*) FROM clients_package WHERE users_id = :c AND expire >= CURDATE()');
    $st->execute(['c' => $clientId]);
    return (int) $st->fetchColumn() > 0;
}

/** Swap points for a reward. Returns ['ok'=>true,'code'=>..] or ['ok'=>false,'why'=>..]. */
function pts_redeem(PDO $db, int $clientId, int $rewardId): array
{
    if (!pts_on($db)) {
        return ['ok' => false, 'why' => 'Points are not open yet.'];
    }
    if (!pts_has_valid_account($db, $clientId)) {
        return ['ok' => false, 'why' => 'You need a valid account to use your points.'];
    }
    $lock = 'djk_pts_' . $clientId;
    $db->query("SELECT GET_LOCK(" . $db->quote($lock) . ", 5)");
    try {
        pts_sync($db, $clientId);
        $st = $db->prepare('SELECT * FROM rewards WHERE id = :i AND active = 1');
        $st->execute(['i' => $rewardId]);
        $rw = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rw) {
            return ['ok' => false, 'why' => 'This reward is not available anymore.'];
        }
        if ($rw['stock'] !== null && (int) $rw['stock'] <= 0) {
            return ['ok' => false, 'why' => 'This reward is sold out.'];
        }
        $per = (int) $rw['per_month'];
        if ($per > 0) {
            $st = $db->prepare('SELECT COUNT(*) FROM reward_claims WHERE client_id = :c AND reward_id = :r AND cancelled_at IS NULL AND created_at > NOW() - INTERVAL 30 DAY');
            $st->execute(['c' => $clientId, 'r' => $rewardId]);
            if ((int) $st->fetchColumn() >= $per) {
                return ['ok' => false, 'why' => 'You already got this reward ' . $per . ' time' . ($per > 1 ? 's' : '') . ' in the last 30 days.'];
            }
        }
        $cost = (int) $rw['points'];
        if ($cost <= 0 || pts_balance($db, $clientId) < $cost) {
            return ['ok' => false, 'why' => 'You do not have enough points yet.'];
        }
        $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alpha[random_int(0, strlen($alpha) - 1)];
            }
            $chk = $db->prepare('SELECT 1 FROM reward_claims WHERE code = :c');
            $chk->execute(['c' => $code]);
        } while ($chk->fetchColumn());
        $db->beginTransaction();
        $db->prepare('INSERT INTO reward_claims (reward_id, client_id, code, points, created_at, expires_at) VALUES (:r, :c, :code, :p, NOW(), NOW() + INTERVAL 30 DAY)')
            ->execute(['r' => $rewardId, 'c' => $clientId, 'code' => $code, 'p' => $cost]);
        $claimId = (int) $db->lastInsertId();
        pts_add($db, $clientId, -$cost, 'redeem', (string) $claimId, 'Reward: ' . $rw['title']);
        if ($rw['stock'] !== null) {
            $db->prepare('UPDATE rewards SET stock = stock - 1 WHERE id = :i')->execute(['i' => $rewardId]);
        }
        $db->commit();
        return ['ok' => true, 'code' => $code];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    } finally {
        $db->query("SELECT RELEASE_LOCK(" . $db->quote($lock) . ")");
    }
}

/** Cancel an unused code (staff): points back. */
function pts_cancel_claim(PDO $db, int $claimId): bool
{
    $st = $db->prepare('SELECT c.*, r.title FROM reward_claims c JOIN rewards r ON r.id = c.reward_id WHERE c.id = :i AND c.used_at IS NULL AND c.cancelled_at IS NULL');
    $st->execute(['i' => $claimId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        return false;
    }
    $db->prepare('UPDATE reward_claims SET cancelled_at = NOW() WHERE id = :i')->execute(['i' => $claimId]);
    $db->prepare('UPDATE rewards SET stock = stock + 1 WHERE id = :i AND stock IS NOT NULL')->execute(['i' => $c['reward_id']]);
    pts_add($db, (int) $c['client_id'], (int) $c['points'], 'refund', (string) $claimId, 'Code cancelled: points back (' . $c['title'] . ')');
    return true;
}

/** A reward code, for checking at the counter. */
function pts_claim_by_code(PDO $db, string $code): ?array
{
    $st = $db->prepare("SELECT c.*, r.title, r.partner_id, TRIM(cl.username) AS first, TRIM(cl.lastName) AS last
                        FROM reward_claims c JOIN rewards r ON r.id = c.reward_id JOIN clients cl ON cl.id = c.client_id
                        WHERE c.code = :c");
    $st->execute(['c' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code))]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pts_claim_state(array $c): string
{
    if ($c['used_at']) {
        return 'used';
    }
    if ($c['cancelled_at']) {
        return 'cancelled';
    }
    if (strtotime((string) $c['expires_at']) < time()) {
        return 'expired';
    }
    return 'open';
}


// ---------------------------------------------------------------------------
// Visits at a partner, and extra visits bought with points
// ---------------------------------------------------------------------------

/** The account used at partners: valid, latest end date. */
function pts_main_account(PDO $db, int $clientId, int $accountId = 0): ?array
{
    if ($accountId > 0) {
        // The account picked in the app (must be this customer's and still valid)
        $st = $db->prepare('SELECT id, package_id, expire FROM clients_package WHERE id = :a AND users_id = :c AND expire >= CURDATE()');
        $st->execute(['a' => $accountId, 'c' => $clientId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            return $r;
        }
    }
    $st = $db->prepare('SELECT id, package_id, expire FROM clients_package WHERE users_id = :c AND expire >= CURDATE() ORDER BY expire DESC LIMIT 1');
    $st->execute(['c' => $clientId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Visits for an account (clients_package row) at a partner: total, extra, used, left. */
function visits_at(PDO $db, int $accountId, int $packageId, int $companyId): array
{
    $st = $db->prepare('SELECT v_amount_total FROM voucher_package_amount WHERE v_company_id = :c AND v_package_id = :p ORDER BY id DESC LIMIT 1');
    $st->execute(['c' => $companyId, 'p' => $packageId]);
    $total = (int) $st->fetchColumn();
    $st = $db->prepare('SELECT COALESCE(SUM(scans), 0) FROM extra_scans WHERE account_id = :a AND company_id = :c');
    $st->execute(['a' => $accountId, 'c' => $companyId]);
    $extra = (int) $st->fetchColumn();
    $st = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM client_package_info WHERE users_package_id = :a AND company_id = :c');
    $st->execute(['a' => $accountId, 'c' => $companyId]);
    $used = (int) $st->fetchColumn();
    return ['total' => $total, 'extra' => $extra, 'used' => $used, 'left' => max(0, $total + $extra - $used)];
}

/** Buy extra visits at a partner with points. Only when all visits there are used. */
function pts_buy_extra_scan(PDO $db, int $clientId, int $companyId, int $accountId = 0): array
{
    if (!pts_on($db)) {
        return ['ok' => false, 'why' => 'Points are not open yet.'];
    }
    $acc = pts_main_account($db, $clientId, $accountId);
    if (!$acc) {
        return ['ok' => false, 'why' => 'You need a valid account for this.'];
    }
    $r = pts_rules($db);
    $cost = max(1, $r['pts_extra_scan']);
    $count = max(1, $r['extra_scan_count']);
    $lock = 'djk_pts_' . $clientId;
    $db->query('SELECT GET_LOCK(' . $db->quote($lock) . ', 5)');
    try {
        $v = visits_at($db, (int) $acc['id'], (int) $acc['package_id'], $companyId);
        if ($v['total'] <= 0) {
            return ['ok' => false, 'why' => 'Your account has no visits at this partner.'];
        }
        if ($v['left'] > 0) {
            return ['ok' => false, 'why' => 'You still have visits left here.'];
        }
        pts_sync($db, $clientId);
        if (pts_balance($db, $clientId) < $cost) {
            return ['ok' => false, 'why' => 'You need ' . $cost . ' points for this.'];
        }
        $db->beginTransaction();
        $db->prepare('INSERT INTO extra_scans (client_id, account_id, company_id, scans, points, created_at) VALUES (:c, :a, :co, :s, :p, NOW())')
            ->execute(['c' => $clientId, 'a' => $acc['id'], 'co' => $companyId, 's' => $count, 'p' => $cost]);
        $xid = (int) $db->lastInsertId();
        $st = $db->prepare('SELECT TRIM(name) FROM voucher_company WHERE id = :i');
        $st->execute(['i' => $companyId]);
        pts_add($db, $clientId, -$cost, 'extra_scan', (string) $xid, ($count > 1 ? $count . ' extra visits: ' : 'Extra visit: ') . ((string) $st->fetchColumn() ?: 'partner'));
        $db->commit();
        return ['ok' => true, 'left' => $count];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    } finally {
        $db->query('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')');
    }
}

/** A points order was paid: add the points once. */
function pts_order_paid(PDO $db, string $token): bool
{
    $st = $db->prepare('SELECT * FROM points_orders WHERE token = :t');
    $st->execute(['t' => $token]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        return false;
    }
    if ((int) $o['status'] !== 1) {
        $db->prepare('UPDATE points_orders SET status = 1, paid_at = NOW() WHERE id = :i AND status = 0')->execute(['i' => $o['id']]);
        pts_add($db, (int) $o['client_id'], (int) $o['points'], 'bought', (string) $o['id'], 'Points bought (' . strtoupper((string) $o['currency']) . ' ' . number_format((float) $o['amount'], 2) . ')');
    }
    return true;
}
