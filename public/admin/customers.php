<?php
// Staff backend: customers, their cards, and eSIM requests.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$id = (int) ($_GET['id'] ?? 0);
$cardTypes = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior'];

admin_post(function () use ($id, $db, $cardTypes) {
    $action = $_POST['action'] ?? '';
    if ($action === 'esim_done') {
        $db->prepare('UPDATE esim SET confirmed = 2 WHERE id = :id')->execute(['id' => (int) $_POST['esim_id']]);
        admin_log('esim_sent', '#' . (int) $_POST['esim_id']);
        flash('eSIM marked as sent.');
        return;
    }
    $c = $db->prepare('SELECT * FROM clients WHERE id = :id');
    $c->execute(['id' => $id]);
    $c = $c->fetch();
    if (!$c) {
        throw new RuntimeException('Customer not found');
    }
    switch ($action) {
        case 'save':
            $email = strtolower(trim((string) $_POST['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('That email address is not valid.');
            }
            $dup = $db->prepare('SELECT id FROM clients WHERE LOWER(email) = :e AND id <> :id');
            $dup->execute(['e' => $email, 'id' => $id]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('Another customer already uses that email.');
            }
            $db->prepare('UPDATE clients SET username = :f, lastName = :l, email = :e, phone = :p, active = :a WHERE id = :id')->execute([
                'f' => mb_substr(trim((string) $_POST['first']), 0, 80), 'l' => mb_substr(trim((string) $_POST['last']), 0, 80),
                'e' => $email, 'p' => mb_substr(trim((string) $_POST['phone']), 0, 30), 'a' => empty($_POST['active']) ? 0 : 1, 'id' => $id,
            ]);
            admin_log('customer_saved', '#' . $id . ' ' . $email);
            flash('Saved.');
            break;
        case 'card_date':
            $d = (string) $_POST['expire'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Pick a date.');
            }
            $db->prepare('UPDATE clients_package SET expire = :d WHERE id = :cp AND users_id = :u')->execute(['d' => $d, 'cp' => (int) $_POST['cp'], 'u' => $id]);
            admin_log('card_end_date', 'card #' . (int) $_POST['cp'] . ' of customer #' . $id . ' → ' . $d);
            flash('End date changed.');
            break;
        case 'card_add':
            $pkg = (int) $_POST['package'];
            $d = (string) $_POST['expire'];
            if (!isset($cardTypes[$pkg]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Pick a card type and an end date.');
            }
            $db->prepare('INSERT INTO clients_package (users_id, package_id, expire) VALUES (:u, :p, :d)')->execute(['u' => $id, 'p' => $pkg, 'd' => $d]);
            admin_log('card_given', $cardTypes[$pkg] . ' card to customer #' . $id . ' until ' . $d . ' · ' . trim((string) ($_POST['why'] ?? '')));
            flash('Card added. It shows in the app right away.');
            break;
        case 'reset':
            send_reset_email($id, (string) $c['email']);
            admin_log('password_link_sent', '#' . $id);
            flash('Password link sent to ' . $c['email'] . '.');
            break;
    }
}, '/admin/customers.php' . ($id ? '?id=' . $id : (isset($_GET['esim']) ? '?esim=1' : '')));

// ---------------------------------------------------------------------------
// eSIM requests
// ---------------------------------------------------------------------------
if (isset($_GET['esim'])) {
    $rows = $db->query('SELECT e.*, c.username, c.lastName FROM esim e LEFT JOIN clients c ON c.id = e.user_id ORDER BY e.confirmed = 1 DESC, e.id DESC LIMIT 200')->fetchAll();
    admin_head('eSIM requests', 'customers');
    ?>
    <p><a href="/admin/customers.php">← Customers</a></p>
    <h1>eSIM requests</h1>
    <p class="ad-muted">"Paid" means the customer paid for the eSIM and it still has to be sent. Mark it as sent when it's done.</p>
    <div class="ad-wrap"><table class="ad-table">
      <thead><tr><th>#</th><th>Customer</th><th>Email</th><th>Phone</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr><td><?= (int) $r['id'] ?></td>
          <td><?= $r['user_id'] ? '<a href="/admin/customers.php?id=' . (int) $r['user_id'] . '">' . e(trim($r['username'] . ' ' . $r['lastName'])) . '</a>' : '–' ?></td>
          <td><?= e($r['email']) ?></td><td><?= e($r['phone']) ?></td>
          <td><?= (int) $r['confirmed'] === 1 ? '<span class="ad-tag wait">Paid, to send</span>' : ((int) $r['confirmed'] === 2 ? '<span class="ad-tag ok">Sent</span>' : '<span class="ad-tag">Not paid</span>') ?></td>
          <td><?php if ((int) $r['confirmed'] === 1): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="esim_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline" name="action" value="esim_done">Mark as sent</button></form><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------------------
// One customer
// ---------------------------------------------------------------------------
if ($id) {
    $c = $db->prepare('SELECT * FROM clients WHERE id = :id');
    $c->execute(['id' => $id]);
    $c = $c->fetch();
    if (!$c) {
        http_response_code(404);
        admin_head('Customer not found', 'customers');
        echo '<h1>Customer not found</h1><p><a href="/admin/customers.php">← Customers</a></p>';
        admin_foot();
        exit;
    }
    $cards = $db->prepare('SELECT id, package_id, expire FROM clients_package WHERE users_id = :u ORDER BY expire DESC');
    $cards->execute(['u' => $id]);
    $cards = $cards->fetchAll();
    $orders = $db->prepare('SELECT MIN(id) id, MIN(dia_orario) d, MAX(status) status, MIN(currency) currency, GROUP_CONCAT(package) packages, token
                            FROM sale_receits WHERE user_id = :u AND token <> "" GROUP BY token ORDER BY MIN(id) DESC');
    $orders->execute(['u' => $id]);
    $orders = $orders->fetchAll();
    $esims = $db->prepare('SELECT * FROM esim WHERE user_id = :u ORDER BY id DESC');
    $esims->execute(['u' => $id]);
    $esims = $esims->fetchAll();

    admin_head(trim($c['username'] . ' ' . $c['lastName']) ?: 'Customer', 'customers');
    ?>
    <p><a href="/admin/customers.php">← Customers</a></p>
    <h1><?= e(trim($c['username'] . ' ' . $c['lastName']) ?: $c['email']) ?> <?= (int) $c['active'] ? '' : '<span class="ad-tag bad">Blocked</span>' ?></h1>

    <div class="ad-box">
      <h2 style="margin-top:0">Cards</h2>
      <?php if (!$cards): ?><p class="ad-muted">No cards yet.</p><?php endif; ?>
      <?php foreach ($cards as $k): $active = $k['expire'] >= date('Y-m-d'); ?>
        <form method="post" class="ad-actions" style="margin-top:6px"><?= csrf_field() ?>
          <input type="hidden" name="cp" value="<?= (int) $k['id'] ?>">
          <strong style="min-width:90px"><?= e(card_name((int) $k['package_id'])) ?></strong>
          <?= $active ? '<span class="ad-tag ok">Active</span>' : '<span class="ad-tag">Ended</span>' ?>
          <span class="ad-muted">valid until</span>
          <input type="date" class="ad-in" style="width:170px" name="expire" value="<?= e($k['expire']) ?>">
          <button class="btn btn-sm btn-outline" name="action" value="card_date">Change end date</button>
        </form>
      <?php endforeach; ?>
      <details style="margin-top:14px">
        <summary class="btn btn-sm">Give a card (free, influencer, replacement)</summary>
        <form method="post" class="ad-form" style="margin-top:12px"><?= csrf_field() ?>
          <div><label>Card</label><select name="package"><?php foreach ($cardTypes as $pid => $n): ?><option value="<?= $pid ?>"<?= $pid === 3 ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
          <div><label>Valid until</label><input type="date" name="expire" value="<?= e(date('Y-m-d', strtotime('+1 year'))) ?>" required></div>
          <div><label>Reason (for the log)</label><input type="text" name="why" placeholder="e.g. influencer, prize"></div>
          <div class="full"><button class="btn btn-sm" name="action" value="card_add">Add card</button></div>
        </form>
      </details>
    </div>

    <div class="ad-two">
      <form method="post" class="ad-box ad-form"><?= csrf_field() ?>
        <h2 class="full" style="margin-top:0">Details</h2>
        <div><label>First name</label><input type="text" name="first" value="<?= e($c['username']) ?>"></div>
        <div><label>Last name</label><input type="text" name="last" value="<?= e($c['lastName']) ?>"></div>
        <div><label>Email (also their login)</label><input type="email" name="email" value="<?= e($c['email']) ?>" required></div>
        <div><label>Phone</label><input type="text" name="phone" value="<?= e($c['phone']) ?>"></div>
        <label class="check full"><input type="checkbox" name="active" value="1"<?= (int) $c['active'] ? ' checked' : '' ?>> <span>Account active (untick to block logging in)</span></label>
        <div class="full ad-actions"><button class="btn btn-sm" name="action" value="save">Save</button></div>
      </form>
      <div class="ad-box">
        <h2 style="margin-top:0">Login</h2>
        <p class="ad-muted">Send them a link to choose a new password. It works for 3 days. Staff never see or set passwords.</p>
        <form method="post"><?= csrf_field() ?><button class="btn btn-sm btn-outline" name="action" value="reset">Send password link</button></form>
        <?php if ($esims): ?>
          <h2>eSIM</h2>
          <ul><?php foreach ($esims as $e): ?><li><?= e($e['phone']) ?> · <?= (int) $e['confirmed'] === 2 ? 'sent' : ((int) $e['confirmed'] === 1 ? 'paid, to send' : 'not paid') ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>

    <h2>Orders</h2>
    <div class="ad-wrap"><table class="ad-table">
      <thead><tr><th>Order</th><th>Date</th><th>Cards</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($orders as $o): ?>
        <tr><td><a href="/admin/orders.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td><td><?= e(admin_date($o['d'], true)) ?></td>
          <td><?= e(implode(', ', array_map(fn($p) => card_name((int) $p), explode(',', (string) $o['packages'])))) ?></td>
          <td><?= (int) $o['status'] === 1 ? '<span class="ad-tag ok">Paid</span>' : '<span class="ad-tag wait">Not paid</span>' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$orders): ?><tr><td colspan="4" class="ad-muted">No orders.</td></tr><?php endif; ?></tbody>
    </table></div>
    <?php
    admin_foot();
    exit;
}

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$only = (string) ($_GET['only'] ?? '');
$page = max(1, (int) ($_GET['p'] ?? 1));
$per = 50;
$where = ['1 = 1'];
$params = [];
if ($q !== '') {
    $where[] = '(c.email LIKE :q OR CONCAT(c.username, " ", c.lastName) LIKE :q2 OR c.phone LIKE :q3)';
    $params['q'] = $params['q2'] = $params['q3'] = '%' . $q . '%';
}
if ($only === 'active') {
    $where[] = 'EXISTS (SELECT 1 FROM clients_package p WHERE p.users_id = c.id AND p.expire >= CURDATE())';
}
$w = implode(' AND ', $where);
$cnt = $db->prepare("SELECT COUNT(*) FROM clients c WHERE $w");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$st = $db->prepare("SELECT c.id, c.username, c.lastName, c.email, c.phone, c.active,
        (SELECT MAX(p.expire) FROM clients_package p WHERE p.users_id = c.id) last_expire
    FROM clients c WHERE $w ORDER BY c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($params);
$list = $st->fetchAll();
$esimWaiting = (int) $db->query('SELECT COUNT(*) FROM esim WHERE confirmed = 1')->fetchColumn();

admin_head('Customers', 'customers');
?>
<h1>Customers</h1>
<form class="ad-filter" method="get">
  <input name="q" value="<?= e($q) ?>" placeholder="Name, email or phone" style="min-width:260px">
  <select name="only"><option value="">Everyone</option><option value="active"<?= $only === 'active' ? ' selected' : '' ?>>With an active card</option></select>
  <button class="btn btn-sm">Search</button>
  <span class="ad-muted"><?= $total ?> customer<?= $total === 1 ? '' : 's' ?></span>
  <a href="/admin/customers.php?esim=1" style="margin-left:auto">eSIM requests<?= $esimWaiting ? ' <span class="ad-tag wait">' . $esimWaiting . ' to send</span>' : '' ?></a>
</form>
<div class="ad-wrap"><table class="ad-table">
  <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Card</th></tr></thead>
  <tbody><?php foreach ($list as $c): ?>
    <tr><td><a href="/admin/customers.php?id=<?= (int) $c['id'] ?>"><?= e(trim($c['username'] . ' ' . $c['lastName']) ?: '(no name)') ?></a><?= (int) $c['active'] ? '' : ' <span class="ad-tag bad">Blocked</span>' ?></td>
      <td><?= e($c['email']) ?></td><td><?= e($c['phone']) ?></td>
      <td><?= $c['last_expire'] ? ($c['last_expire'] >= date('Y-m-d') ? '<span class="ad-tag ok">until ' . e(admin_date($c['last_expire'])) . '</span>' : '<span class="ad-tag">ended ' . e(admin_date($c['last_expire'])) . '</span>') : '–' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="4" class="ad-muted">No customers found.</td></tr><?php endif; ?></tbody>
</table></div>
<?= pager($total, $page, $per, array_filter(['q' => $q, 'only' => $only])) ?>
<?php admin_foot();
