<?php
// Staff backend: customers, their cards, and eSIM requests.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();
$id = (int) ($_GET['id'] ?? 0);
$cardTypes = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior'];

admin_post(function () use ($id, $db, $cardTypes) {
    $action = $_POST['action'] ?? '';
    if ($action === 'free_new') {
        // Make an account for someone: paid at the desk (cash, card, bank transfer) or free (influencer, artist, prize)
        $email = mb_strtolower(trim((string) $_POST['email']));
        $first = trim((string) $_POST['first']);
        $last = trim((string) $_POST['last']);
        $pkg = (int) $_POST['package'];
        $d = (string) $_POST['expire'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $first === '') {
            throw new RuntimeException('Fill in at least a first name and a valid email.');
        }
        if (!isset($cardTypes[$pkg]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            throw new RuntimeException('Pick an account type and an end date.');
        }
        if (!in_array($_POST['how'] ?? '', ['free', 'paid'], true)) {
            throw new RuntimeException('Choose Free or Paid at the desk.');
        }
        $paid = $_POST['how'] === 'paid';
        [$cid, $isNew] = find_or_create_client($email, $first, $last, trim((string) ($_POST['phone'] ?? '')));
        $db->prepare('INSERT INTO clients_package (users_id, package_id, expire) VALUES (:u, :p, :d)')->execute(['u' => $cid, 'p' => $pkg, 'd' => $d]);
        $pkgRow = (int) $db->lastInsertId();
        if ($paid) {
            // Paid at the desk: a paid order row, so it shows as "Paid" and counts in the numbers
            try {
                $db->prepare("INSERT INTO sale_receits (email, user_id, package, dia_orario, status, currency, email_status, token, client_package_id)
                              VALUES (:e, :u, :p, NOW(), 1, :cur, 0, :t, :cp)")
                    ->execute(['e' => $email, 'u' => $cid, 'p' => $pkg, 'cur' => (string) ($_POST['currency'] ?? 'XCG') === 'EUR' ? 'EUR' : 'XCG', 't' => 'desk-' . bin2hex(random_bytes(8)), 'cp' => $pkgRow]);
            } catch (Throwable $e) {
                error_log('desk order: ' . $e->getMessage());
            }
        }
        admin_log($paid ? 'account_sold_desk' : 'card_given', $cardTypes[$pkg] . ' account to customer #' . $cid . ' until ' . $d . ($paid ? ' (paid: ' . trim((string) ($_POST['paidhow'] ?? '')) . ')' : '') . ' · ' . trim((string) ($_POST['why'] ?? '')) . ($isNew ? ' (new customer)' : ''));
        // Password link to copy and send by WhatsApp (works even while email is not set up)
        $_SESSION['pw_link'] = ['id' => $cid, 'link' => rtrim((string) config('base_url'), '/') . '/set-password.php?t=' . create_password_token($cid, 24 * 7)];
        if (!empty($_POST['mail'])) {
            $link = $_SESSION['pw_link']['link']; // same link as the one to copy, so both keep working
            brevo_send($email,  $paid ? "Dj'aki mi ta!: your account is ready" : "Dj'aki mi ta!: you got a free account", dm_mail('Welcome to Dj\'aki mi ta!',
                '<p>Hi ' . e($first) . ',</p><p>You got a' . ($paid ? 'n' : ' free') . ' <strong>' . e($cardTypes[$pkg]) . ' account</strong>, valid until ' . e(date('d-m-Y', strtotime($d))) . '. Enjoy the discounts at our partners on Curaçao!</p>'
                . '<p>Choose your password with the button below (the link works for 7 days). Then log in to the Dj\'aki mi ta! app with ' . e($email) . '.</p>'
                . '<p><a href="' . e($link) . '" style="display:inline-block;background:#187c19;color:#fff;padding:12px 22px;border-radius:999px;text-decoration:none;font-weight:bold">Choose my password</a></p>'));
        }
        flash(($isNew ? 'New customer made' : 'This email was already a customer') . ' and the ' . ($paid ? '' : 'free ') . 'account is added. Now add the people on the account under it (name and birth date' . ($pkg === 4 ? ', for a Family account everyone in the family' : '') . '). Then copy the password link under Login and send it to them, so they can log in to the app.');
        header('Location: /admin/customers.php?id=' . $cid);
        exit;
    }
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
        case 'make_free':
        case 'make_paid':
            $cp = (int) $_POST['cp'];
            $own = $db->prepare('SELECT package_id FROM clients_package WHERE id = :cp AND users_id = :u');
            $own->execute(['cp' => $cp, 'u' => $id]);
            $pk = $own->fetchColumn();
            if ($pk === false) {
                throw new RuntimeException('Account not found.');
            }
            if ($action === 'make_free') {
                // Only a "paid at the desk" mark can be undone here; online payments stay paid.
                $online = $db->prepare("SELECT COUNT(*) FROM sale_receits WHERE client_package_id = :cp AND status = 1 AND (token IS NULL OR token NOT LIKE 'desk-%')");
                $online->execute(['cp' => $cp]);
                if ((int) $online->fetchColumn() > 0) {
                    throw new RuntimeException('This account was paid online, so it stays "Paid". For a refund, see Orders.');
                }
                // Keep the row (the people on the account hang on it), just mark it as given instead of sold
                $db->prepare("UPDATE sale_receits SET status = 2, token = CONCAT('given-', SUBSTRING(token, 6)) WHERE client_package_id = :cp AND token LIKE 'desk-%'")->execute(['cp' => $cp]);
                admin_log('account_made_free', 'account #' . $cp . ' of customer #' . $id);
                flash('Changed to a free account.');
            } else {
                $g = $db->prepare("UPDATE sale_receits SET status = 1, currency = 'XCG', dia_orario = NOW(), token = CONCAT('desk-', SUBSTRING(token, 7)) WHERE client_package_id = :cp AND status = 2 AND token LIKE 'given-%'");
                $g->execute(['cp' => $cp]);
                if (!$g->rowCount()) {
                    $db->prepare("INSERT INTO sale_receits (email, user_id, package, dia_orario, status, currency, email_status, token, client_package_id)
                                  VALUES (:e, :u, :p, NOW(), 1, 'XCG', 0, :t, :cp)")
                        ->execute(['e' => (string) $c['email'], 'u' => $id, 'p' => (int) $pk, 't' => 'desk-' . bin2hex(random_bytes(8)), 'cp' => $cp]);
                }
                admin_log('account_made_paid', 'account #' . $cp . ' of customer #' . $id . ' (paid at the desk)');
                flash('Changed to paid (at the desk).');
            }
            break;
        case 'holder_add':
        case 'holder_save':
        case 'holder_del':
            // People on an account (names and birth dates). For an account without an order (given in the
            // backend) a holder record is made with status 2: it is not an order and not counted as a sale.
            $cp = (int) $_POST['cp'];
            $own = $db->prepare('SELECT package_id FROM clients_package WHERE id = :cp AND users_id = :u');
            $own->execute(['cp' => $cp, 'u' => $id]);
            $pk = $own->fetchColumn();
            if ($pk === false) {
                throw new RuntimeException('Account not found.');
            }
            if ($action === 'holder_del') {
                $db->prepare('DELETE h FROM client_package_customers h JOIN sale_receits s ON s.id = h.order_id
                              WHERE h.id = :h AND s.client_package_id = :cp')->execute(['h' => (int) $_POST['h'], 'cp' => $cp]);
                admin_log('holder_removed', 'account #' . $cp . ' of customer #' . $id);
                flash('Person removed from the account.');
                break;
            }
            $name = mb_substr(trim((string) $_POST['hname']), 0, 120);
            $birth = (string) $_POST['hbirth'];
            if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) {
                throw new RuntimeException('Fill in a name and a birth date.');
            }
            $slug = [1 => 'kids', 2 => 'teen', 3 => 'adult', 4 => 'family', 5 => 'senior'][(int) $pk] ?? '';
            if ($slug !== '' && function_exists('card_age_error')) {
                // Check the ages with everyone on the account (Family: 2 adults and up to 3 children or teens)
                $others = $db->prepare('SELECT h.id, h.name, h.client_birthdate AS birth FROM client_package_customers h
                                        JOIN sale_receits s ON s.id = h.order_id WHERE s.client_package_id = :cp AND s.status IN (1, 2)');
                $others->execute(['cp' => $cp]);
                $all = [];
                foreach ($others->fetchAll() as $o) {
                    if ($action === 'holder_save' && (int) $o['id'] === (int) $_POST['h']) {
                        continue;
                    }
                    if ($o['birth']) {
                        $all[] = ['name' => $o['name'], 'birth' => $o['birth']];
                    }
                }
                $all[] = ['name' => $name, 'birth' => $birth];
                if ($err = card_age_error($slug, $all)) {
                    throw new RuntimeException($err);
                }
            }
            if ($action === 'holder_save') {
                $db->prepare('UPDATE client_package_customers h JOIN sale_receits s ON s.id = h.order_id
                              SET h.name = :n, h.client_birthdate = :b WHERE h.id = :h AND s.client_package_id = :cp')
                    ->execute(['n' => $name, 'b' => $birth, 'h' => (int) $_POST['h'], 'cp' => $cp]);
                admin_log('holder_changed', 'account #' . $cp . ' of customer #' . $id);
                flash('Saved.');
                break;
            }
            $o = $db->prepare('SELECT id FROM sale_receits WHERE client_package_id = :cp AND status IN (1, 2) ORDER BY id LIMIT 1');
            $o->execute(['cp' => $cp]);
            $orderId = (int) $o->fetchColumn();
            if (!$orderId) {
                $db->prepare("INSERT INTO sale_receits (email, user_id, package, dia_orario, status, currency, email_status, token, client_package_id)
                              VALUES (:e, :u, :p, NOW(), 2, '', 1, :t, :cp)")
                    ->execute(['e' => (string) $c['email'], 'u' => $id, 'p' => (int) $pk, 't' => 'given-' . bin2hex(random_bytes(8)), 'cp' => $cp]);
                $orderId = (int) $db->lastInsertId();
            }
            $cnt = $db->prepare('SELECT COUNT(*) FROM client_package_customers WHERE order_id = :o');
            $cnt->execute(['o' => $orderId]);
            $max = (int) $pk === 4 ? 5 : 1;
            if ((int) $cnt->fetchColumn() >= $max) {
                throw new RuntimeException((int) $pk === 4 ? 'A Family account has room for 5 people (2 adults and 3 children or teens).' : 'This account is for one person. Change the name instead.');
            }
            $db->prepare('INSERT INTO client_package_customers (name, client_id, client_birthdate, order_id) VALUES (:n, "", :b, :o)')
                ->execute(['n' => $name, 'b' => $birth, 'o' => $orderId]);
            admin_log('holder_added', 'account #' . $cp . ' of customer #' . $id);
            flash('Person added to the account.');
            break;
        case 'card_date':
            $d = (string) $_POST['expire'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Pick a date.');
            }
            $db->prepare('UPDATE clients_package SET expire = :d WHERE id = :cp AND users_id = :u')->execute(['d' => $d, 'cp' => (int) $_POST['cp'], 'u' => $id]);
            admin_log('card_end_date', 'account #' . (int) $_POST['cp'] . ' of customer #' . $id . ' → ' . $d);
            flash('End date changed.');
            break;
        case 'card_add':
            $pkg = (int) $_POST['package'];
            $d = (string) $_POST['expire'];
            if (!isset($cardTypes[$pkg]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Pick an account type and an end date.');
            }
            $db->prepare('INSERT INTO clients_package (users_id, package_id, expire) VALUES (:u, :p, :d)')->execute(['u' => $id, 'p' => $pkg, 'd' => $d]);
            admin_log('card_given', $cardTypes[$pkg] . ' account to customer #' . $id . ' until ' . $d . ' · ' . trim((string) ($_POST['why'] ?? '')));
            flash('Account added. It shows in the app right away.');
            break;
        case 'phones_clear':
            require_once __DIR__ . '/../../app/devices.php';
            dev_clear($db, $id);
            admin_log('phones_logged_out', '#' . $id);
            flash('All phones are logged out and forgotten. The next phone the customer logs in on is free, it does not count as a phone change.');
            break;
        case 'points_adjust':
            require_once __DIR__ . '/../../app/points.php';
            $n = (int) ($_POST['points'] ?? 0);
            $why = mb_substr(trim((string) ($_POST['why'] ?? '')), 0, 120);
            if ($n === 0 || $why === '') {
                throw new RuntimeException('Fill in the points (use a minus for taking points away) and the reason.');
            }
            pts_add($db, $id, $n, 'adjust', 'a' . bin2hex(random_bytes(8)), $why);
            admin_log('points_adjusted', '#' . $id . ' ' . ($n > 0 ? '+' : '') . $n . ' · ' . $why);
            flash(($n > 0 ? 'Added ' : 'Took away ') . abs($n) . ' points.');
            break;
        case 'phones_give':
            require_once __DIR__ . '/../../app/devices.php';
            $ok = dev_give_change($db, $id);
            admin_log('phone_change_given', '#' . $id);
            flash($ok ? 'One phone change given back.' : 'This customer has not used any phone changes in the last 12 months.');
            break;
        case 'reset_link':
            $token = create_password_token($id, 24);
            $_SESSION['pw_link'] = ['id' => $id, 'link' => rtrim((string) config('base_url'), '/') . '/set-password.php?t=' . $token];
            admin_log('password_link_copied', '#' . $id);
            flash('Password link made. Copy it below and send it to the customer, for example by WhatsApp. It works for 24 hours, one time.');
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
    $cards = $db->prepare('SELECT cp.id, cp.package_id, cp.expire,
                                  (SELECT COUNT(*) FROM sale_receits s WHERE s.client_package_id = cp.id AND s.status = 1) paid
                           FROM clients_package cp WHERE cp.users_id = :u ORDER BY cp.expire DESC');
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
      <h2 style="margin-top:0">Accounts</h2>
      <?php if (!$cards): ?><p class="ad-muted">No accounts yet.</p><?php endif; ?>
      <?php foreach ($cards as $k): $active = $k['expire'] >= date('Y-m-d'); ?>
        <form method="post" class="ad-actions" style="margin-top:6px"><?= csrf_field() ?>
          <input type="hidden" name="cp" value="<?= (int) $k['id'] ?>">
          <strong style="min-width:90px"><?= e(card_name((int) $k['package_id'])) ?></strong>
          <?= $active ? '<span class="ad-tag ok">Active</span>' : '<span class="ad-tag">Ended</span>' ?>
          <?= (int) $k['paid'] ? '<span class="ad-tag">Paid</span>' : '<span class="ad-tag wait">Free account</span>' ?>
          <span class="ad-muted">valid until</span>
          <input type="date" class="ad-in" style="width:170px" name="expire" value="<?= e($k['expire']) ?>">
          <button class="btn btn-sm btn-outline" name="action" value="card_date">Change end date</button>
          <?php if ((int) $k['paid']): ?>
            <button class="btn btn-sm btn-outline" name="action" value="make_free" onclick="return confirm('Change this account to a free account?')">Make it free</button>
          <?php else: ?>
            <button class="btn btn-sm btn-outline" name="action" value="make_paid" onclick="return confirm('Mark this account as paid at the desk?')">Mark as paid</button>
          <?php endif; ?>
        </form>
        <?php
          $hp = $db->prepare('SELECT h.id, h.name, h.client_birthdate AS birth FROM client_package_customers h
                              JOIN sale_receits s ON s.id = h.order_id WHERE s.client_package_id = :cp AND s.status IN (1, 2) ORDER BY h.id');
          $hp->execute(['cp' => (int) $k['id']]);
          $people = $hp->fetchAll();
          $room = (int) $k['package_id'] === 4 ? 5 : 1;
        ?>
        <div class="ad-people" style="margin:6px 0 14px 12px;padding:10px 12px;border-left:3px solid #dfe8da">
          <div class="ad-muted" style="margin-bottom:6px">People on this account<?= (int) $k['package_id'] === 4 ? ' (Family: 2 adults and up to 3 children or teens up to 17)' : '' ?></div>
          <?php foreach ($people as $h): ?>
            <form method="post" class="ad-actions" style="margin:4px 0"><?= csrf_field() ?>
              <input type="hidden" name="cp" value="<?= (int) $k['id'] ?>"><input type="hidden" name="h" value="<?= (int) $h['id'] ?>">
              <input class="ad-in" type="text" name="hname" value="<?= e($h['name']) ?>" style="width:220px" aria-label="Name">
              <input class="ad-in" type="date" name="hbirth" value="<?= e((string) $h['birth']) ?>" style="width:170px" aria-label="Birth date">
              <button class="btn btn-sm btn-outline" name="action" value="holder_save">Save</button>
              <button class="btn btn-sm btn-outline" name="action" value="holder_del" onclick="return confirm('Remove <?= e(addslashes($h['name'])) ?> from this account?')">Remove</button>
            </form>
          <?php endforeach; ?>
          <?php if (count($people) < $room): ?>
            <form method="post" class="ad-actions" style="margin:4px 0"><?= csrf_field() ?>
              <input type="hidden" name="cp" value="<?= (int) $k['id'] ?>">
              <input class="ad-in" type="text" name="hname" placeholder="Full name" style="width:220px" required>
              <input class="ad-in" type="date" name="hbirth" style="width:170px" required aria-label="Birth date">
              <button class="btn btn-sm" name="action" value="holder_add">+ Add person</button>
            </form>
          <?php elseif (!$people): ?><p class="ad-muted">No names yet.</p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <details style="margin-top:14px">
        <summary class="btn btn-sm">Give an account (free, influencer, replacement)</summary>
        <form method="post" class="ad-form" style="margin-top:12px"><?= csrf_field() ?>
          <div><label>Account</label><select name="package"><?php foreach ($cardTypes as $pid => $n): ?><option value="<?= $pid ?>"<?= $pid === 3 ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
          <div><label>Valid until</label><input type="date" name="expire" value="<?= e(date('Y-m-d', strtotime('+1 year'))) ?>" required></div>
          <div><label>Reason (for the log)</label><input type="text" name="why" placeholder="e.g. influencer, prize"></div>
          <div class="full"><button class="btn btn-sm" name="action" value="card_add">Add account</button></div>
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
        <form method="post" class="ad-actions"><?= csrf_field() ?><button class="btn btn-sm btn-outline" name="action" value="reset">Send password link by email</button>
          <button class="btn btn-sm btn-outline" name="action" value="reset_link">Get the link to copy</button></form>
        <?php if (($_SESSION['pw_link']['id'] ?? 0) === $id): $pl = $_SESSION['pw_link']['link']; unset($_SESSION['pw_link']); ?>
          <div style="margin-top:10px"><input type="text" readonly value="<?= e($pl) ?>" onclick="this.select()" style="width:100%">
            <button type="button" class="btn btn-sm" style="margin-top:6px" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(()=>this.textContent='Copied')">Copy link</button>
            <p class="ad-muted" style="margin:6px 0 0">Only shown once. The customer opens it, chooses a password, and can then log in on the website and in the app.</p></div>
        <?php endif; ?>
        <?php require_once __DIR__ . '/../../app/devices.php'; $phones = []; $plim = 1; $chg = []; $callow = 2; try { $phones = dev_list($db, $id); $plim = dev_limit($db, $id); $chg = dev_changes($db, $id); $callow = dev_changes_allowed($db); } catch (Throwable $e) {} ?>
        <?php require_once __DIR__ . '/../../app/points.php'; $pbal = 0; $phist = []; try { pts_sync($db, $id); $pbal = pts_balance($db, $id); $phist = pts_history($db, $id, 8); } catch (Throwable $e) {} ?>
        <h2>Points</h2>
        <p><strong><?= number_format($pbal, 0, ',', '.') ?> points</strong> <span class="ad-muted">· invite code <?= e(invite_code($id)) ?></span></p>
        <?php if ($phist): ?><table class="ad-table"><?php foreach ($phist as $h): ?><tr><td><?= e(date('d-m-Y', strtotime($h['created_at']))) ?></td><td><?= e($h['label']) ?><?= (int) $h['is_pending'] ? ' <span class="ad-muted">(from ' . e(date('d-m-Y', strtotime($h['available_at']))) . ')</span>' : '' ?></td><td style="text-align:right"><?= (int) $h['points'] > 0 ? '+' : '' ?><?= (int) $h['points'] ?></td></tr><?php endforeach; ?></table><?php endif; ?>
        <form method="post" class="ad-actions" style="margin:8px 0 4px"><?= csrf_field() ?>
          <input type="number" name="points" placeholder="+50 or -50" style="max-width:120px">
          <input type="text" name="why" placeholder="Reason, e.g. sorry for the trouble" maxlength="120" style="flex:1;min-width:160px">
          <button class="btn btn-sm btn-outline" name="action" value="points_adjust">Add or take away</button></form>
        <h2>Phones</h2>
        <p class="ad-muted">This account may be used on <?= $plim ?> phone<?= $plim > 1 ? 's' : '' ?>. Moving it to a new phone is allowed <?= $callow ?> times per 12 months; the old phone is then logged out and can no longer scan. Logging out and in again on the same phone is free.</p>
        <p><strong>Phone changes used in the last 12 months: <?= count($chg) ?> of <?= $callow ?></strong><?php if ($chg): ?> <span class="ad-muted">(<?= e(implode(', ', array_map(fn($d) => date('d-m-Y', strtotime($d)), $chg))) ?>)</span><?php endif; ?></p>
        <?php if ($chg): ?><form method="post" class="ad-actions" style="margin-bottom:8px"><?= csrf_field() ?><button class="btn btn-sm btn-outline" name="action" value="phones_give">Give back one phone change</button></form><?php endif; ?>
        <?php if ($phones): ?>
          <table class="ad-table"><tr><th>Phone</th><th>Logged in</th><th>Last used</th></tr>
          <?php foreach ($phones as $ph): ?><tr><td><?= e($ph['label']) ?><?= (int) $ph['active'] ? '' : ' <span class="ad-muted">(logged out)</span>' ?></td><td><?= e(date('d-m-Y H:i', strtotime($ph['created_at']))) ?></td><td><?= e(date('d-m-Y H:i', strtotime($ph['last_seen']))) ?></td></tr><?php endforeach; ?></table>
          <form method="post" class="ad-actions" style="margin-top:8px"><?= csrf_field() ?><button class="btn btn-sm btn-outline" name="action" value="phones_clear" onclick="return confirm('Log out and forget all phones of this account? Use this for a lost or broken phone. The next phone is free.')">Log out all phones (lost phone)</button></form>
        <?php else: ?><p class="ad-muted">Not logged in on a phone with the new app yet.</p><?php endif; ?>
        <?php if ($esims): ?>
          <h2>eSIM</h2>
          <ul><?php foreach ($esims as $e): ?><li><?= e($e['phone']) ?> · <?= (int) $e['confirmed'] === 2 ? 'sent' : ((int) $e['confirmed'] === 1 ? 'paid, to send' : 'not paid') ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>

    <h2>Orders</h2>
    <div class="ad-wrap"><table class="ad-table">
      <thead><tr><th>Order</th><th>Date</th><th>Accounts</th><th>Status</th></tr></thead>
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
  <select name="only"><option value="">Everyone</option><option value="active"<?= $only === 'active' ? ' selected' : '' ?>>With an active account</option></select>
  <button class="btn btn-sm">Search</button>
  <span class="ad-muted"><?= $total ?> customer<?= $total === 1 ? '' : 's' ?></span>
  <a href="/admin/customers.php?esim=1" style="margin-left:auto">eSIM requests<?= $esimWaiting ? ' <span class="ad-tag wait">' . $esimWaiting . ' to send</span>' : '' ?></a>
</form>
<details class="ad-box">
  <summary class="btn btn-sm">+ Make an account for someone</summary>
  <form method="post" class="ad-form" style="margin-top:12px"><?= csrf_field() ?>
    <p class="full ad-muted" style="margin:0">For someone who paid at the desk (cash, card or bank transfer) or who gets it free (influencer, artist, prize). If the email is already a customer, the account is added to them. Afterwards you get a password link to send them, so they can log in to the app.</p>
    <div class="full"><label>How</label>
      <label class="check"><input type="radio" name="how" value="free" required onchange="this.form.querySelector('.paidbox').hidden=true"> <span>Free (influencer, artist, prize, team)</span></label>
      <label class="check"><input type="radio" name="how" value="paid" required onchange="this.form.querySelector('.paidbox').hidden=false"> <span>Paid at the desk (cash, card, bank transfer)</span></label></div>
    <div class="full paidbox" hidden><label>Paid how (for the log)</label><input type="text" name="paidhow" placeholder="e.g. cash XCG 135, bank transfer"> 
      <label style="margin-top:8px">Currency</label><select name="currency"><option>XCG</option><option>EUR</option></select></div>
    <div><label>First name</label><input type="text" name="first" required></div>
    <div><label>Last name</label><input type="text" name="last"></div>
    <div><label>Email (their login)</label><input type="email" name="email" required></div>
    <div><label>Phone (optional)</label><input type="text" name="phone"></div>
    <div><label>Account</label><select name="package"><?php foreach ($cardTypes as $pid => $n): ?><option value="<?= $pid ?>"<?= $pid === 3 ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div><label>Valid until</label><input type="date" name="expire" value="<?= e(date('Y-m-d', strtotime('+1 year'))) ?>" required></div>
    <div class="full"><label>Note (for the log)</label><input type="text" name="why" placeholder="e.g. influencer @name, prize, bought at the market stand"></div>
    <label class="check full"><input type="checkbox" name="mail" value="1" checked> <span>Also email them a welcome with the password link (only works once the email key is set)</span></label>
    <div class="full"><button class="btn btn-sm" name="action" value="free_new">Make the account</button></div>
  </form>
</details>
<div class="ad-wrap"><table class="ad-table">
  <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Account</th></tr></thead>
  <tbody><?php foreach ($list as $c): ?>
    <tr><td><a href="/admin/customers.php?id=<?= (int) $c['id'] ?>"><?= e(trim($c['username'] . ' ' . $c['lastName']) ?: '(no name)') ?></a><?= (int) $c['active'] ? '' : ' <span class="ad-tag bad">Blocked</span>' ?></td>
      <td><?= e($c['email']) ?></td><td><?= e($c['phone']) ?></td>
      <td><?= $c['last_expire'] ? ($c['last_expire'] >= date('Y-m-d') ? '<span class="ad-tag ok">until ' . e(admin_date($c['last_expire'])) . '</span>' : '<span class="ad-tag">ended ' . e(admin_date($c['last_expire'])) . '</span>') : '–' ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="4" class="ad-muted">No customers found.</td></tr><?php endif; ?></tbody>
</table></div>
<?= pager($total, $page, $per, array_filter(['q' => $q, 'only' => $only])) ?>
<?php admin_foot();
