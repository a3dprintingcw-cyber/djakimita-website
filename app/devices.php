<?php
// One account, one phone. Each phone that logs in to the app gets its own key.
// When more phones log in than the account allows, the oldest phone is logged out.
// Allowed phones: Family account = setting phones_family (default 2), every other account = phones_single (default 1).
// Used by api/device.php, api/scan.php (scan_guard.php) and admin/customers.php. Needs only a PDO.

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

function dev_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Registers this phone. Returns [token, list of phones that were logged out]. */
function dev_register(PDO $db, int $clientId, string $label): array
{
    $token = bin2hex(random_bytes(24));
    $label = mb_substr(trim(preg_replace('/\s+/', ' ', $label)), 0, 80) ?: 'Phone';
    $db->prepare('INSERT INTO client_devices (client_id, token_hash, label, created_at, last_seen) VALUES (:c, :t, :l, NOW(), NOW())')
        ->execute(['c' => $clientId, 't' => dev_hash($token), 'l' => $label]);

    $limit = dev_limit($db, $clientId);
    $st = $db->prepare('SELECT id FROM client_devices WHERE client_id = :c ORDER BY last_seen DESC, id DESC');
    $st->execute(['c' => $clientId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $out = array_slice($ids, $limit);
    if ($out) {
        $db->exec('DELETE FROM client_devices WHERE id IN (' . implode(',', $out) . ')');
    }
    return [$token, count($out)];
}

/** True when this phone is still logged in for this customer. Also notes when it was last used. */
function dev_check(PDO $db, int $clientId, string $token): bool
{
    if ($clientId <= 0 || strlen($token) < 20) {
        return false;
    }
    $st = $db->prepare('SELECT id FROM client_devices WHERE client_id = :c AND token_hash = :t');
    $st->execute(['c' => $clientId, 't' => dev_hash($token)]);
    $id = $st->fetchColumn();
    if (!$id) {
        return false;
    }
    $db->prepare('UPDATE client_devices SET last_seen = NOW() WHERE id = :i AND last_seen < NOW() - INTERVAL 5 MINUTE')
        ->execute(['i' => $id]);
    return true;
}

function dev_remove(PDO $db, int $clientId, string $token): void
{
    $db->prepare('DELETE FROM client_devices WHERE client_id = :c AND token_hash = :t')
        ->execute(['c' => $clientId, 't' => dev_hash($token)]);
}

function dev_list(PDO $db, int $clientId): array
{
    $st = $db->prepare('SELECT id, label, created_at, last_seen FROM client_devices WHERE client_id = :c ORDER BY last_seen DESC');
    $st->execute(['c' => $clientId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function dev_clear(PDO $db, int $clientId): void
{
    $db->prepare('DELETE FROM client_devices WHERE client_id = :c')->execute(['c' => $clientId]);
}
