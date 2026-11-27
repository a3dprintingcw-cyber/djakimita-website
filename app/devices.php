<?php
// One account, one phone (Family: 2). Each phone has its own id (dev_uid, kept on the phone, also after logout)
// and gets a key when it logs in.
// - A phone the account already uses logs in again for free (also after logging out).
// - A new phone, while the account already has all its phones, is a "phone change": the phone used the longest
//   ago is retired. Changes allowed per 12 months: setting phone_changes_year (default 2). After that, refused.
// - A retired phone that comes back counts as a new phone again (so two people can't swap one account).
// Used by api/device.php, app/scan_guard.php and admin/customers.php. Needs only a PDO.

function dev_setting(PDO $db, string $k, string $default): string
{
    try {
        $st = $db->prepare('SELECT v FROM site_settings WHERE k = :k');
        $st->execute(['k' => $k]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null || $v === '') ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function dev_limit(PDO $db, int $clientId): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM clients_package WHERE users_id = :c AND package_id = 4 AND expire >= CURDATE()');
    $st->execute(['c' => $clientId]);
    $family = (int) $st->fetchColumn() > 0;
    $n = (int) dev_setting($db, $family ? 'phones_family' : 'phones_single', $family ? '2' : '1');
    return max(1, min(10, $n));
}

function dev_changes_allowed(PDO $db): int
{
    return max(0, min(50, (int) dev_setting($db, 'phone_changes_year', '2')));
}

/** Phone changes in the last 12 months, oldest first (dates). */
function dev_changes(PDO $db, int $clientId): array
{
    $st = $db->prepare('SELECT created_at FROM client_device_changes WHERE client_id = :c AND created_at > NOW() - INTERVAL 12 MONTH ORDER BY created_at');
    $st->execute(['c' => $clientId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function dev_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Logs this phone in. Returns ['ok' => true, 'token' => ..., 'changed' => bool]
 * or ['ok' => false, 'next' => 'Y-m-d' date when a change is possible again].
 */
function dev_register(PDO $db, int $clientId, string $label, string $uid): array
{
    $uid = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $uid) ? $uid : '';
    $label = mb_substr(trim(preg_replace('/\s+/', ' ', $label)), 0, 80) ?: 'Phone';
    $token = bin2hex(random_bytes(24));
    $limit = dev_limit($db, $clientId);

    // A phone this account already uses (not retired): log in again for free
    if ($uid !== '') {
        $st = $db->prepare('SELECT id FROM client_devices WHERE client_id = :c AND uid = :u AND retired_at IS NULL ORDER BY id DESC LIMIT 1');
        $st->execute(['c' => $clientId, 'u' => $uid]);
        if ($id = $st->fetchColumn()) {
            $db->prepare('UPDATE client_devices SET token_hash = :t, label = :l, active = 1, last_seen = NOW() WHERE id = :i')
                ->execute(['t' => dev_hash($token), 'l' => $label, 'i' => $id]);
            return ['ok' => true, 'token' => $token, 'changed' => false];
        }
    }

    // A new phone. Does the account still have a free place?
    $st = $db->prepare('SELECT id FROM client_devices WHERE client_id = :c AND retired_at IS NULL ORDER BY last_seen DESC, id DESC');
    $st->execute(['c' => $clientId]);
    $phones = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $changed = false;
    if (count($phones) >= $limit) {
        $changes = dev_changes($db, $clientId);
        if (count($changes) >= dev_changes_allowed($db)) {
            $first = $changes[0] ?? date('Y-m-d H:i:s');
            return ['ok' => false, 'next' => date('Y-m-d', strtotime($first . ' +12 months'))];
        }
        $db->prepare('INSERT INTO client_device_changes (client_id, label, created_at) VALUES (:c, :l, NOW())')
            ->execute(['c' => $clientId, 'l' => $label]);
        $retire = array_slice($phones, $limit - 1);
        $db->exec('UPDATE client_devices SET retired_at = NOW(), active = 0, token_hash = NULL WHERE id IN (' . implode(',', $retire) . ')');
        $changed = true;
    }
    $db->prepare('INSERT INTO client_devices (client_id, uid, token_hash, label, created_at, last_seen, active) VALUES (:c, :u, :t, :l, NOW(), NOW(), 1)')
        ->execute(['c' => $clientId, 'u' => $uid !== '' ? $uid : null, 't' => dev_hash($token), 'l' => $label]);
    return ['ok' => true, 'token' => $token, 'changed' => $changed];
}

/** True when this phone is still logged in for this customer. Also notes when it was last used. */
function dev_check(PDO $db, int $clientId, string $token): bool
{
    if ($clientId <= 0 || strlen($token) < 20) {
        return false;
    }
    $st = $db->prepare('SELECT id FROM client_devices WHERE client_id = :c AND token_hash = :t AND active = 1 AND retired_at IS NULL');
    $st->execute(['c' => $clientId, 't' => dev_hash($token)]);
    $id = $st->fetchColumn();
    if (!$id) {
        return false;
    }
    $db->prepare('UPDATE client_devices SET last_seen = NOW() WHERE id = :i AND last_seen < NOW() - INTERVAL 5 MINUTE')
        ->execute(['i' => $id]);
    return true;
}

/** Logout button in the app: the phone stays this account's phone, it can log in again for free. */
function dev_remove(PDO $db, int $clientId, string $token): void
{
    $db->prepare('UPDATE client_devices SET active = 0, token_hash = NULL WHERE client_id = :c AND token_hash = :t')
        ->execute(['c' => $clientId, 't' => dev_hash($token)]);
}

/** The account's phones (not retired). */
function dev_list(PDO $db, int $clientId): array
{
    $st = $db->prepare('SELECT id, label, created_at, last_seen, active FROM client_devices WHERE client_id = :c AND retired_at IS NULL ORDER BY last_seen DESC');
    $st->execute(['c' => $clientId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Staff: log out and forget all phones (lost phone). The next phone to log in is free, no change used. */
function dev_clear(PDO $db, int $clientId): void
{
    $db->prepare('UPDATE client_devices SET retired_at = NOW(), active = 0, token_hash = NULL WHERE client_id = :c AND retired_at IS NULL')
        ->execute(['c' => $clientId]);
}

/** Staff: give back one phone change (removes the latest one in the last 12 months). */
function dev_give_change(PDO $db, int $clientId): bool
{
    $st = $db->prepare('SELECT id FROM client_device_changes WHERE client_id = :c AND created_at > NOW() - INTERVAL 12 MONTH ORDER BY created_at DESC LIMIT 1');
    $st->execute(['c' => $clientId]);
    $id = $st->fetchColumn();
    if (!$id) {
        return false;
    }
    $db->prepare('DELETE FROM client_device_changes WHERE id = :i')->execute(['i' => $id]);
    return true;
}
