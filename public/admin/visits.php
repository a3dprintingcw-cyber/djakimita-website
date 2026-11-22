<?php
// Staff backend: partner visits. Staff go to each partner, check the questions (QR code in view, QR code works,
// staff know Dj'aki mi ta!), take photos and write notes. Inspector accounts only see this page.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$isAdmin = admin_role() === 'admin';
$today = date('Y-m-d');

function visit_default_questions(): array
{
    $q = json_decode((string) setting('visit_questions', ''), true);
    return is_array($q) && $q ? array_values(array_filter(array_map('strval', $q))) : [
        'E QR code ta pone kaminda hende por mire',
        'E QR code ta traha',
        "E personal sa kiko ta Dj'aki mi ta",
    ];
}

function visit_questions(array $v): array
{
    $q = json_decode((string) $v['questions'], true);
    return is_array($q) ? $q : [];
}

function visit_score(array $v): string
{
    $q = visit_questions($v);
    return count(array_filter($q, fn($x) => !empty($x['ok']))) . '/' . count($q);
}

function valid_day(string $d): ?string
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) ? $d : null;
}

// ---------------------------------------------------------------------------
// Visit photo (staff only)
// ---------------------------------------------------------------------------
if (isset($_GET['photo'])) {
    $st = $db->prepare('SELECT data, type FROM partner_visit_photos WHERE id = :id');
    $st->execute(['id' => (int) $_GET['photo']]);
    $ph = $st->fetch();
    if (!$ph) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . ($ph['type'] === 'image/png' ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=3600');
    echo $ph['data'];
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$back = (string) ($_POST['back'] ?? '');
if (!preg_match('~^/admin/visits\.php(\?[A-Za-z0-9=&_%.-]*)?$~', $back)) {
    $back = '/admin/visits.php' . ($id ? '?id=' . $id : '');
}

admin_post(function () use ($id, $db, $me, $isAdmin, $today) {
    $action = (string) ($_POST['action'] ?? '');

    // Start a visit (or plan one for a date) for a partner
    if ($action === 'start' || $action === 'plan') {
        $company = (int) ($_POST['company'] ?? 0);
        $name = $db->prepare('SELECT TRIM(name) FROM voucher_company WHERE id = :id');
        $name->execute(['id' => $company]);
        $name = (string) $name->fetchColumn();
        if ($name === '') {
            throw new RuntimeException('Partner not found.');
        }
        $day = valid_day((string) ($_POST['planned_on'] ?? '')) ?? ($action === 'start' ? $today : null);
        if (!$day) {
            throw new RuntimeException('Pick a date to plan the visit.');
        }
        $open = $db->prepare('SELECT id FROM partner_visits WHERE company_id = :c AND status = 0 ORDER BY id DESC LIMIT 1');
        $open->execute(['c' => $company]);
        $vid = (int) $open->fetchColumn();
        if ($vid) {
            $db->prepare('UPDATE partner_visits SET planned_on = :d, updated_at = NOW() WHERE id = :id')->execute(['d' => $day, 'id' => $vid]);
        } else {
            $q = array_map(fn($t) => ['q' => $t, 'ok' => 0], visit_default_questions());
            $db->prepare('INSERT INTO partner_visits (company_id, planned_on, status, questions, notes, created_at, created_by, updated_at)
                          VALUES (:c, :d, 0, :q, :n, NOW(), :u, NOW())')
                ->execute(['c' => $company, 'd' => $day, 'q' => json_encode($q, JSON_UNESCAPED_UNICODE), 'n' => '', 'u' => $me]);
            $vid = (int) $db->lastInsertId();
        }
        admin_log($action === 'start' ? 'visit_started' : 'visit_planned', $name . ' on ' . $day);
        if ($action === 'start') {
            header('Location: /admin/visits.php?id=' . $vid);
            exit;
        }
        flash('Visit to ' . $name . ' planned for ' . admin_date($day) . '.');
        return;
    }

    if ($action === 'defaults') {
        if (!$isAdmin) {
            throw new RuntimeException('Only admins can change the standard questions.');
        }
        $lines = array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 200), preg_split('/\R/', (string) $_POST['lines']))));
        if (!$lines) {
            throw new RuntimeException('Keep at least one question.');
        }
        set_setting('visit_questions', json_encode($lines, JSON_UNESCAPED_UNICODE));
        admin_log('visit_questions_saved', count($lines) . ' questions');
        flash('Standard questions saved. New visits start with these.');
        return;
    }

    // Everything below works on one visit
    $v = $db->prepare('SELECT v.*, TRIM(c.name) name FROM partner_visits v LEFT JOIN voucher_company c ON c.id = v.company_id WHERE v.id = :id');
    $v->execute(['id' => $id]);
    $v = $v->fetch();
    if (!$v) {
        throw new RuntimeException('Visit not found.');
    }

    if ($action === 'delete') {
        if (!$isAdmin) {
            throw new RuntimeException('Only admins can delete a visit.');
        }
        $db->prepare('DELETE FROM partner_visit_photos WHERE visit_id = :id')->execute(['id' => $id]);
        $db->prepare('DELETE FROM partner_visits WHERE id = :id')->execute(['id' => $id]);
        admin_log('visit_deleted', (string) $v['name']);
        flash('Visit deleted.');
        header('Location: /admin/visits.php');
        exit;
    }

    // Save the checklist: questions, ticks, notes, date, photos
    $questions = [];
    $del = isset($_POST['del']) ? (int) $_POST['del'] : -1;
    foreach ((array) ($_POST['q'] ?? []) as $i => $text) {
        $text = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $text)), 0, 200);
        if ($text === '' || (int) $i === $del) {
            continue;
        }
        $questions[] = ['q' => $text, 'ok' => empty($_POST['ok'][$i]) ? 0 : 1];
    }
    $newQ = mb_substr(trim((string) ($_POST['new_q'] ?? '')), 0, 200);
    if ($newQ !== '') {
        $questions[] = ['q' => $newQ, 'ok' => 0];
    }
    $status = (int) $v['status'];
    if ($action === 'done') {
        $status = 1;
    } elseif ($action === 'reopen') {
        $status = 0;
    }
    $db->prepare('UPDATE partner_visits SET questions = :q, notes = :n, planned_on = :d, status = :s,
                  done_at = ' . ($action === 'done' ? 'NOW()' : ($action === 'reopen' ? 'NULL' : 'done_at')) . ',
                  done_by = :by, updated_at = NOW() WHERE id = :id')->execute([
        'q' => json_encode($questions, JSON_UNESCAPED_UNICODE),
        'n' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 5000),
        'd' => valid_day((string) ($_POST['planned_on'] ?? '')) ?? $v['planned_on'],
        's' => $status,
        'by' => $action === 'done' ? $me : ($action === 'reopen' ? '' : (string) $v['done_by']),
        'id' => $id,
    ]);

    if (isset($_POST['del_photo'])) {
        $db->prepare('DELETE FROM partner_visit_photos WHERE id = :p AND visit_id = :v')->execute(['p' => (int) $_POST['del_photo'], 'v' => $id]);
    }
    $added = 0;
    $files = $_FILES['photos'] ?? null;
    if (is_array($files['name'] ?? null)) {
        $have = (int) $db->query('SELECT COUNT(*) FROM partner_visit_photos WHERE visit_id = ' . $id)->fetchColumn();
        $ins = $db->prepare('INSERT INTO partner_visit_photos (visit_id, data, type, created_at) VALUES (:v, :d, :t, NOW())');
        foreach ($files['name'] as $i => $n) {
            if ($files['error'][$i] === UPLOAD_ERR_NO_FILE || $have + $added >= 12) {
                continue;
            }
            [$bytes, $mime] = admin_image_upload(['error' => $files['error'][$i], 'tmp_name' => $files['tmp_name'][$i]], 1600);
            $ins->bindValue('v', $id);
            $ins->bindValue('d', $bytes, PDO::PARAM_LOB);
            $ins->bindValue('t', $mime);
            $ins->execute();
            $added++;
        }
    }

    $label = (string) $v['name'];
    if ($action === 'done') {
        admin_log('visit_done', $label . ' ' . count(array_filter($questions, fn($x) => $x['ok'])) . '/' . count($questions));
        flash('Saved. ' . $label . ' is marked as visited.');
        header('Location: /admin/visits.php');
        exit;
    }
    admin_log($action === 'reopen' ? 'visit_reopened' : 'visit_saved', $label);
    flash('Saved.' . ($added ? ' ' . $added . ' photo' . ($added === 1 ? '' : 's') . ' added.' : ''));
}, $back);

$css = <<<CSS
.vs-top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:14px}
.vs-top .ad-filter{margin:0}
.vs-sec{margin:22px 0 8px;display:flex;justify-content:space-between;align-items:baseline;gap:10px}
.vs-sec h2{margin:0}
.vs-list{background:#fff;border:1px solid #e1e8dd;border-radius:14px;overflow:hidden}
.vs-row{display:flex;gap:12px;align-items:center;padding:12px 14px;border-top:1px solid #edf1ea;flex-wrap:wrap}
.vs-row:first-child{border-top:0}
.vs-row[hidden]{display:none}
.vs-row .nm{flex:1 1 180px;min-width:0}
.vs-row .nm a,.vs-row .nm strong{font-weight:700;color:inherit;text-decoration:none}
.vs-row .nm small{display:block;color:#6b786d}
.vs-row form{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0}
.vs-row input[type=date]{padding:6px 8px;border:1px solid #cfd9ca;border-radius:9px;font:inherit}
.vs-empty{padding:16px;color:#6b786d;text-align:center}
.vs-q{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #e1e8dd;border-radius:12px;padding:12px 14px;margin-bottom:8px}
.vs-q input[type=checkbox]{width:24px;height:24px;flex:none;accent-color:#3d8b40}
.vs-q input[type=text],.vs-q textarea{flex:1;border:0;font:inherit;background:transparent;min-width:0;padding:4px 0;resize:none;field-sizing:content;min-height:0}
.vs-q button{background:none;border:0;cursor:pointer;font-size:1.1rem;padding:4px 8px;border-radius:8px;color:#8a2216}
.vs-q button:hover{background:#fde7e4}
.vs-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:8px;margin-bottom:10px}
.vs-photos figure{margin:0;position:relative}
.vs-photos img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:10px;display:block}
.vs-photos button{position:absolute;top:4px;right:4px;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:28px;height:28px;cursor:pointer}
.vs-add{display:block;border:2px dashed #cfd9ca;border-radius:12px;padding:16px;text-align:center;color:#5b6a5d;cursor:pointer;background:#fff}
.vs-add input{display:block;margin:8px auto 0}
.vs-bar{position:sticky;bottom:0;background:#f4f6f2;padding:12px 0;display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid #e1e8dd;margin-top:16px}
.vs-bar .btn{flex:1 1 200px}
.vs-cal{display:grid;grid-template-columns:repeat(7,1fr);gap:4px;background:#fff;border:1px solid #e1e8dd;border-radius:14px;padding:10px;max-width:560px}
.vs-cal span{text-align:center;font-size:.8rem;color:#6b786d;padding:4px 0}
.vs-cal a{display:flex;flex-direction:column;align-items:center;justify-content:center;aspect-ratio:1;border-radius:10px;color:inherit;text-decoration:none;font-weight:600;position:relative}
.vs-cal a:hover{background:#eef3eb}
.vs-cal a.on{background:#3d8b40;color:#fff}
.vs-cal a.today{outline:2px solid #3d8b40}
.vs-cal a i{font-style:normal;font-size:.7rem;background:#e8b84a;color:#0f3d14;border-radius:999px;padding:0 6px;margin-top:2px}
.vs-cal a i.d{background:#cfe9cb}
.vs-calnav{display:flex;align-items:center;gap:14px;margin-bottom:10px;max-width:560px;justify-content:space-between}
.vs-calnav a{font-size:1.4rem;text-decoration:none;padding:2px 12px}
CSS;

// ---------------------------------------------------------------------------
// One visit: the checklist
// ---------------------------------------------------------------------------
if ($id) {
    $v = $db->prepare('SELECT v.*, TRIM(c.name) name, c.location, c.active FROM partner_visits v LEFT JOIN voucher_company c ON c.id = v.company_id WHERE v.id = :id');
    $v->execute(['id' => $id]);
    $v = $v->fetch();
    if (!$v) {
        http_response_code(404);
        admin_head('Visit not found', 'visits');
        echo '<h1>Visit not found</h1><p><a href="/admin/visits.php">← Partner visits</a></p>';
        admin_foot();
        exit;
    }
    $questions = visit_questions($v);
    $photos = $db->prepare('SELECT id FROM partner_visit_photos WHERE visit_id = :v ORDER BY id');
    $photos->execute(['v' => $id]);
    $photos = array_column($photos->fetchAll(), 'id');
    $history = $db->prepare('SELECT id, status, planned_on, done_at, done_by, questions FROM partner_visits WHERE company_id = :c AND id <> :id ORDER BY id DESC LIMIT 10');
    $history->execute(['c' => $v['company_id'], 'id' => $id]);
    $history = $history->fetchAll();
    $done = (int) $v['status'] === 1;

    admin_head('Visit: ' . $v['name'], 'visits');
    echo '<style>' . $css . '</style>';
    ?>
    <p><a href="/admin/visits.php">← Partner visits</a><?php if ($isAdmin): ?> · <a href="/admin/partners.php?id=<?= (int) $v['company_id'] ?>">Partner details</a><?php endif; ?></p>
    <h1><?= e($v['name'] ?: 'Partner #' . $v['company_id']) ?>
      <?= $done ? '<span class="ad-tag ok">Visited ' . e(admin_date($v['done_at'])) . '</span>' : '<span class="ad-tag wait">Open</span>' ?></h1>
    <?php if ($v['location']): ?><p class="ad-muted">📍 <?= e($v['location']) ?></p><?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="vf"><?= csrf_field() ?>
      <input type="hidden" name="back" value="/admin/visits.php?id=<?= $id ?>">
      <button name="action" value="save" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">Save</button>
      <div class="ad-box ad-form">
        <div><label>Planned for</label><input type="date" name="planned_on" value="<?= e((string) $v['planned_on']) ?>"></div>
        <?php if ($done): ?><div><label>Checked by</label><p style="margin:8px 0"><?= e($v['done_by']) ?>, <?= e(admin_date($v['done_at'], true)) ?></p></div><?php endif; ?>
      </div>

      <h2>Questions <span class="ad-muted"><?= e(visit_score($v)) ?> OK</span></h2>
      <?php foreach ($questions as $i => $q): ?>
        <label class="vs-q">
          <input type="checkbox" name="ok[<?= $i ?>]" value="1"<?= !empty($q['ok']) ? ' checked' : '' ?>>
          <textarea name="q[<?= $i ?>]" rows="1" aria-label="Question"><?= e($q['q']) ?></textarea>
          <button type="submit" name="del" value="<?= $i ?>" title="Remove this question" onclick="return confirm('Remove this question?')">🗑</button>
        </label>
      <?php endforeach; ?>
      <div class="vs-q"><span style="font-size:1.2rem;padding:0 4px">+</span><input type="text" name="new_q" placeholder="Add a question (saved with the button below)"></div>

      <h2>Photos</h2>
      <?php if ($photos): ?>
        <div class="vs-photos">
          <?php foreach ($photos as $ph): ?>
            <figure><a href="?photo=<?= (int) $ph ?>" target="_blank"><img src="?photo=<?= (int) $ph ?>" alt="" loading="lazy"></a>
              <button type="submit" name="del_photo" value="<?= (int) $ph ?>" title="Delete photo" onclick="return confirm('Delete this photo?')">✕</button></figure>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (count($photos) < 12): ?>
        <label class="vs-add">📷 Add photos (QR code, entrance, staff)<input type="file" name="photos[]" accept="image/*" multiple></label>
      <?php endif; ?>

      <h2>Notes</h2>
      <div class="ad-form"><div class="full"><textarea name="notes" rows="5" placeholder="Extra remarks or things to fix"><?= e((string) $v['notes']) ?></textarea></div></div>

      <div class="vs-bar">
        <button class="btn btn-outline" name="action" value="save">Save</button>
        <?php if ($done): ?>
          <button class="btn btn-outline" name="action" value="reopen">Mark as not visited</button>
        <?php else: ?>
          <button class="btn" name="action" value="done">✓ Save and mark as visited</button>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($history): ?>
      <h2>Earlier visits</h2>
      <div class="vs-list">
        <?php foreach ($history as $h): ?>
          <div class="vs-row"><div class="nm"><a href="?id=<?= (int) $h['id'] ?>"><?= (int) $h['status'] === 1 ? 'Visited ' . e(admin_date($h['done_at'])) : 'Open, planned ' . e(admin_date($h['planned_on'])) ?></a>
            <small><?= e($h['done_by']) ?></small></div><span class="ad-tag"><?= e(visit_score($h)) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
      <form method="post" data-confirm="Delete this visit with its photos?" style="margin-top:24px"><?= csrf_field() ?>
        <input type="hidden" name="back" value="/admin/visits.php?id=<?= $id ?>">
        <button class="btn btn-sm btn-outline" name="action" value="delete">Delete this visit</button></form>
    <?php endif; ?>
    <script>
    // Ask before leaving with unsaved changes
    (function () {
      var f = document.getElementById('vf'), dirty = false;
      f.addEventListener('input', function () { dirty = true; });
      f.addEventListener('change', function () { dirty = true; });
      f.addEventListener('submit', function () { dirty = false; });
      window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    })();
    </script>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------------------
// Data for the overview and the calendar
// ---------------------------------------------------------------------------
$partners = $db->query('SELECT id, TRIM(name) name, active FROM voucher_company WHERE country = 1 ORDER BY active DESC, name')->fetchAll();
$visits = $db->query('SELECT v.id, v.company_id, v.planned_on, v.status, v.done_at, v.done_by, v.questions, TRIM(c.name) name, c.active
    FROM partner_visits v JOIN voucher_company c ON c.id = v.company_id ORDER BY v.id DESC')->fetchAll();
$open = [];
$lastDone = [];
foreach ($visits as $v) {
    if ((int) $v['status'] === 0) {
        $open[(int) $v['company_id']] ??= $v;
    } else {
        $lastDone[(int) $v['company_id']] ??= $v;
    }
}
$monday = date('Y-m-d', strtotime('monday this week'));
$doneWeek = count(array_filter($visits, fn($v) => (int) $v['status'] === 1 && substr((string) $v['done_at'], 0, 10) >= $monday));

$row = function (array $v, string $sub) use ($today): string {
    ob_start(); ?>
    <div class="vs-row" data-name="<?= e(mb_strtolower((string) $v['name'])) ?>">
      <div class="nm"><a href="?id=<?= (int) $v['id'] ?>"><?= e($v['name']) ?></a><?= (int) $v['active'] ? '' : ' <span class="ad-tag">Off</span>' ?><small><?= e($sub) ?></small></div>
      <span class="ad-tag<?= (int) $v['status'] === 1 ? ' ok' : ((string) $v['planned_on'] !== '' && $v['planned_on'] < $today ? ' bad' : ' wait') ?>"><?= e(visit_score($v)) ?></span>
      <a class="btn btn-sm btn-outline" href="?id=<?= (int) $v['id'] ?>"><?= (int) $v['status'] === 1 ? 'View' : 'Open checklist' ?></a>
    </div>
    <?php return (string) ob_get_clean();
};

// ---------------------------------------------------------------------------
// Calendar
// ---------------------------------------------------------------------------
if (($_GET['view'] ?? '') === 'cal') {
    $m = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? (string) $_GET['m'] : date('Y-m');
    $day = valid_day((string) ($_GET['day'] ?? '')) ?? ($m === date('Y-m') ? $today : $m . '-01');
    $first = strtotime($m . '-01');
    $perDay = [];
    foreach ($visits as $v) {
        $d = (int) $v['status'] === 1 ? substr((string) $v['done_at'], 0, 10) : (string) $v['planned_on'];
        if ($d !== '') {
            $perDay[$d][] = $v;
        }
    }
    admin_head('Partner visits calendar', 'visits');
    echo '<style>' . $css . '</style>';
    ?>
    <p><a href="/admin/visits.php">← Partner visits</a></p>
    <h1>Calendar</h1>
    <div class="vs-calnav">
      <a href="?view=cal&amp;m=<?= date('Y-m', strtotime('-1 month', $first)) ?>" aria-label="Previous month">‹</a>
      <strong><?= e(date('F Y', $first)) ?></strong>
      <a href="?view=cal&amp;m=<?= date('Y-m', strtotime('+1 month', $first)) ?>" aria-label="Next month">›</a>
    </div>
    <div class="vs-cal">
      <?php foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $dn): ?><span><?= $dn ?></span><?php endforeach; ?>
      <?php for ($i = 1; $i < (int) date('N', $first); $i++): ?><span></span><?php endfor; ?>
      <?php for ($dnum = 1; $dnum <= (int) date('t', $first); $dnum++):
          $d = $m . '-' . str_pad((string) $dnum, 2, '0', STR_PAD_LEFT);
          $list = $perDay[$d] ?? [];
          $nDone = count(array_filter($list, fn($x) => (int) $x['status'] === 1)); ?>
        <a href="?view=cal&amp;m=<?= $m ?>&amp;day=<?= $d ?>" class="<?= $d === $day ? 'on' : '' ?><?= $d === $today ? ' today' : '' ?>"><?= $dnum ?>
          <?php if (count($list) - $nDone): ?><i><?= count($list) - $nDone ?></i><?php elseif ($nDone): ?><i class="d"><?= $nDone ?></i><?php endif; ?></a>
      <?php endfor; ?>
    </div>
    <div class="vs-sec"><h2><?= e(date('l j F', strtotime($day))) ?></h2><span class="ad-muted"><?= count($perDay[$day] ?? []) ?> partners</span></div>
    <div class="vs-list">
      <?php foreach ($perDay[$day] ?? [] as $v): ?>
        <?= $row($v, (int) $v['status'] === 1 ? 'Visited by ' . $v['done_by'] : 'Planned') ?>
      <?php endforeach; ?>
      <?php if (empty($perDay[$day])): ?><p class="vs-empty">No partners planned on this day. Plan visits from the list on the Partner visits page.</p><?php endif; ?>
    </div>
    <p class="ad-muted">Yellow number: planned visits. Green number: visits done that day.</p>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------------------
// Overview
// ---------------------------------------------------------------------------
$todayList = array_filter($open, fn($v) => $v['planned_on'] === $today);
$late = array_filter($open, fn($v) => (string) $v['planned_on'] === '' || $v['planned_on'] < $today);
$coming = array_filter($open, fn($v) => (string) $v['planned_on'] !== '' && $v['planned_on'] > $today);
uasort($coming, fn($a, $b) => strcmp($a['planned_on'], $b['planned_on']));
uasort($late, fn($a, $b) => strcmp((string) $a['planned_on'], (string) $b['planned_on']));
$toPlan = array_filter($partners, fn($p) => !isset($open[(int) $p['id']]) && !isset($lastDone[(int) $p['id']]));
$visited = array_filter($lastDone, fn($v) => !isset($open[(int) $v['company_id']]));
uasort($visited, fn($a, $b) => strcmp((string) $b['done_at'], (string) $a['done_at']));
$activeCount = count(array_filter($partners, fn($p) => (int) $p['active']));
$activeVisited = count(array_filter($partners, fn($p) => (int) $p['active'] && isset($lastDone[(int) $p['id']])));

admin_head('Partner visits', 'visits');
echo '<style>' . $css . '</style>';
?>
<h1>Partner visits</h1>
<div class="ad-cards">
  <div class="ad-card"><b><?= count($todayList) ?></b><span>Planned today</span></div>
  <div class="ad-card"><b><?= $doneWeek ?></b><span>Visited this week</span></div>
  <div class="ad-card"><b><?= $activeVisited ?> / <?= $activeCount ?></b><span>Active partners visited</span></div>
</div>
<div class="vs-top">
  <a class="btn btn-sm" href="?view=cal">📅 Calendar</a>
  <input class="ad-in" type="search" id="vs-search" placeholder="Search partner" style="max-width:280px">
</div>

<?php
$section = function (string $title, array $items, string $empty, callable $sub) use ($row) {
    ?>
    <div class="vs-sec"><h2><?= e($title) ?></h2><span class="ad-muted"><?= count($items) ?> partners</span></div>
    <div class="vs-list">
      <?php foreach ($items as $v): ?><?= $row($v, $sub($v)) ?><?php endforeach; ?>
      <?php if (!$items): ?><p class="vs-empty"><?= e($empty) ?></p><?php endif; ?>
    </div>
    <?php
};
$section('Planned today', $todayList, 'Nothing planned for today.', fn($v) => 'Today');
if ($late) {
    $section('Started or overdue', $late, '', fn($v) => $v['planned_on'] ? 'Was planned ' . admin_date($v['planned_on']) : 'No date yet');
}
$section('Coming up', $coming, 'No visits planned yet.', fn($v) => 'Planned ' . date('D j M', strtotime($v['planned_on'])));
?>

<div class="vs-sec"><h2>Still to plan</h2><span class="ad-muted"><?= count($toPlan) ?> partners</span></div>
<div class="vs-list">
  <?php foreach ($toPlan as $p): ?>
    <div class="vs-row" data-name="<?= e(mb_strtolower($p['name'])) ?>">
      <div class="nm"><strong><?= e($p['name']) ?></strong><?= (int) $p['active'] ? '' : ' <span class="ad-tag">Off</span>' ?><small>Never visited</small></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/visits.php"><input type="hidden" name="company" value="<?= (int) $p['id'] ?>">
        <input type="date" name="planned_on" min="<?= $today ?>" aria-label="Date">
        <button class="btn btn-sm btn-outline" name="action" value="plan">Plan</button>
        <button class="btn btn-sm" name="action" value="start">Start now</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$toPlan): ?><p class="vs-empty">Every partner has a visit. 🎉</p><?php endif; ?>
</div>

<div class="vs-sec"><h2>Visited</h2><span class="ad-muted"><?= count($visited) ?> partners</span></div>
<div class="vs-list">
  <?php foreach ($visited as $v): ?>
    <div class="vs-row" data-name="<?= e(mb_strtolower((string) $v['name'])) ?>">
      <div class="nm"><a href="?id=<?= (int) $v['id'] ?>"><?= e($v['name']) ?></a><?= (int) $v['active'] ? '' : ' <span class="ad-tag">Off</span>' ?><small><?= e(admin_date($v['done_at'])) ?> · <?= e($v['done_by']) ?></small></div>
      <?php $s = explode('/', visit_score($v)); ?>
      <span class="ad-tag <?= $s[0] === $s[1] ? 'ok' : 'bad' ?>"><?= e(visit_score($v)) ?></span>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/visits.php"><input type="hidden" name="company" value="<?= (int) $v['company_id'] ?>">
        <input type="date" name="planned_on" min="<?= $today ?>" aria-label="Date">
        <button class="btn btn-sm btn-outline" name="action" value="plan">Plan again</button>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if (!$visited): ?><p class="vs-empty">No visits done yet.</p><?php endif; ?>
</div>

<?php if ($isAdmin): ?>
  <details class="ad-box" style="margin-top:24px"><summary class="btn btn-sm btn-outline">Standard questions</summary>
    <form method="post" class="ad-form" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="back" value="/admin/visits.php">
      <div class="full"><label>One question per line. New visits start with these; you can still add or remove questions per visit.</label>
        <textarea name="lines" rows="6"><?= e(implode("\n", visit_default_questions())) ?></textarea></div>
      <div class="full"><button class="btn btn-sm" name="action" value="defaults">Save questions</button></div>
    </form>
  </details>
<?php endif; ?>
<script>
document.getElementById('vs-search').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('.vs-row[data-name]').forEach(function (r) { r.hidden = q !== '' && r.dataset.name.indexOf(q) === -1; });
});
</script>
<?php admin_foot();
