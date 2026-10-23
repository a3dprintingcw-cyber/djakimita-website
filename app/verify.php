<?php
// Born-in-Curaçao check, done ONCE per person by Dj'aki mi ta!, so shops never have to.
//   Customer: uploads a selfie + a photo of the passport page (shows place of birth).
//   Djakimita: approves in /admin. The passport photo is deleted right after review.
//   Shop: sees the selfie, name and "verified" on the green screen. Nothing else to check.

const VERIFY_PENDING = 0;
const VERIFY_APPROVED = 1;
const VERIFY_REJECTED = 2;

function verify_dir(): string
{
    $dir = __DIR__ . '/storage/verify';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function name_key(string $name): string
{
    return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
}

function find_verification(int $clientId, string $name, string $birth): ?array
{
    $st = db()->prepare('SELECT * FROM holder_verifications WHERE client_id = :c AND name_key = :n AND birthdate = :b');
    $st->execute(['c' => $clientId, 'n' => name_key($name), 'b' => $birth]);
    return $st->fetch() ?: null;
}

/** Card holders of an order, each with its verification row (or null). */
function order_holders(int $mainId): array
{
    $st = db()->prepare('SELECT h.id, h.name, h.client_birthdate AS birth, s.user_id AS client_id, s.package
                         FROM client_package_customers h JOIN sale_receits s ON s.id = h.order_id
                         WHERE h.order_id = :o ORDER BY h.id');
    $st->execute(['o' => $mainId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['v'] = find_verification((int) $r['client_id'], $r['name'], $r['birth']);
    }
    return $rows;
}

function is_family_package(int $package): bool
{
    return $package === CARD_TYPES['family']['package'];
}

/**
 * Checks an uploaded photo, re-encodes it as a clean JPEG (this also strips
 * hidden data like GPS location) and stores it outside the website folder.
 * Returns the stored file name.
 */
function save_photo(array $file, int $maxSide): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 15 * 1024 * 1024) {
        throw new InvalidArgumentException('upload');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('type');
    }
    $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$src) {
        throw new InvalidArgumentException('type');
    }
    // Phone photos are often stored sideways with a "rotate me" tag.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $turn = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($turn) {
            $src = imagerotate($src, $turn, 0);
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $name = bin2hex(random_bytes(16)) . '.jpg';
    imagejpeg($dst, verify_dir() . '/' . $name, 82);
    imagedestroy($src);
    imagedestroy($dst);
    return $name;
}

function delete_photo(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.jpg$/', $name)) {
        @unlink(verify_dir() . '/' . $name);
    }
}

/** Store (or replace) a person's submission; it goes back to "waiting for review". */
function submit_verification(array $holder, int $orderId, string $face, ?string $idPhoto): void
{
    $old = $holder['v'];
    if ($old) {
        delete_photo($old['face_file']);
        delete_photo($old['id_file']);
        db()->prepare('UPDATE holder_verifications SET order_id = :o, status = 0, face_file = :f, id_file = :i, note = NULL,
                       submitted_at = NOW(), reviewed_at = NULL, reviewed_by = NULL WHERE id = :id')
            ->execute(['o' => $orderId, 'f' => $face, 'i' => $idPhoto, 'id' => $old['id']]);
        return;
    }
    db()->prepare('INSERT INTO holder_verifications (client_id, order_id, name, name_key, birthdate, status, face_file, id_file, submitted_at)
                   VALUES (:c, :o, :n, :k, :b, 0, :f, :i, NOW())')
        ->execute(['c' => $holder['client_id'], 'o' => $orderId, 'n' => $holder['name'], 'k' => name_key($holder['name']),
                   'b' => $holder['birth'], 'f' => $face, 'i' => $idPhoto]);
}

/** Approve or reject. The passport photo is deleted either way: we only keep the result. */
function review_verification(int $id, bool $approve, string $by, string $note = ''): ?array
{
    $st = db()->prepare('SELECT * FROM holder_verifications WHERE id = :id');
    $st->execute(['id' => $id]);
    $v = $st->fetch();
    if (!$v) {
        return null;
    }
    delete_photo($v['id_file']);
    if (!$approve) {
        delete_photo($v['face_file']);
    }
    db()->prepare('UPDATE holder_verifications SET status = :s, id_file = NULL, face_file = :f, note = :n,
                   reviewed_at = NOW(), reviewed_by = :by WHERE id = :id')
        ->execute(['s' => $approve ? VERIFY_APPROVED : VERIFY_REJECTED, 'f' => $approve ? $v['face_file'] : null,
                   'n' => $approve ? null : mb_substr($note, 0, 255), 'by' => $by, 'id' => $id]);
    return $v;
}

function verify_link(int $orderId): string
{
    return rtrim((string) config('base_url'), '/') . '/verify.php?' . http_build_query(['o' => $orderId, 's' => order_sig($orderId)]);
}

// ---------------------------------------------------------------------------
// Admin login (Dj'aki mi ta! staff). Users + bcrypt hashes live in config.local.php.
// ---------------------------------------------------------------------------
function admin_user(): ?string
{
    return $_SESSION['admin'] ?? null;
}

function admin_login(string $user, string $pass): bool
{
    $users = (array) config('admin_users', []);
    $hash = $users[$user] ?? null;
    if ($hash && password_verify($pass, $hash)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = $user;
        return true;
    }
    usleep(800000); // slow down password guessing
    return false;
}

function require_admin(): string
{
    $u = admin_user();
    if (!$u) {
        header('Location: /admin/');
        exit;
    }
    return $u;
}
