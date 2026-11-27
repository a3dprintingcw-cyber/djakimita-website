<?php
// Partner list for the app: the same partners as the website (every active partner of the country),
// with the visits for the account picked in the app. ?country=1|2&vid=<clients_package id>
require __DIR__ . '/_db.php';
$country = (int) ($_GET['country'] ?? 1) === 2 ? 2 : 1;
$vid = (int) ($_GET['vid'] ?? 0);
try {
    $db = api_db();
    $pkg = 0;
    if ($vid > 0) {
        $st = $db->prepare('SELECT package_id FROM clients_package WHERE id = :v');
        $st->execute(['v' => $vid]);
        $pkg = (int) $st->fetchColumn();
    }
    $cats = [];
    foreach ($db->query('SELECT id, TRIM(name) AS name FROM category') as $c) {
        $cats[(int) $c['id']] = $c['name'];
    }
    $st = $db->prepare('SELECT v.id, TRIM(v.name) AS name, v.description, v.opening_hours, v.location, v.category_id,
                               v.additional_category_ids, v.point_accumulation, v.country, v.active,
                               (SELECT MAX(d.the_percent) FROM voucher_company_info_deals d WHERE d.company_id = v.id) AS best
                        FROM voucher_company v WHERE v.active = 1 AND v.country = :c ORDER BY best DESC, v.name');
    $st->execute(['c' => $country]);
    $tot = $db->prepare('SELECT v_amount_total FROM voucher_package_amount WHERE v_company_id = :c AND v_package_id = :p ORDER BY id DESC LIMIT 1');
    $used = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM client_package_info WHERE users_package_id = :a AND company_id = :c');
    $out = [];
    foreach ($st->fetchAll() as $v) {
        $extra = json_decode((string) $v['additional_category_ids'], true);
        $add = [];
        foreach (is_array($extra) ? $extra : [] as $cid) {
            if (isset($cats[(int) $cid])) {
                $add[] = ['id' => (int) $cid, 'name' => $cats[(int) $cid]];
            }
        }
        $t = 0;
        $u = 0;
        if ($pkg) {
            $tot->execute(['c' => $v['id'], 'p' => $pkg]);
            $t = (int) $tot->fetchColumn();
            $used->execute(['a' => $vid, 'c' => $v['id']]);
            $u = (int) $used->fetchColumn();
        }
        $out[] = [
            'id' => (string) $v['id'], 'name' => $v['name'], 'description' => (string) $v['description'],
            'opening_hours' => (string) $v['opening_hours'], 'location' => (string) $v['location'],
            'category_id' => (string) $v['category_id'], 'cat_name' => $cats[(int) $v['category_id']] ?? 'General',
            'point_accumulation' => (string) $v['point_accumulation'], 'country' => (string) $v['country'], 'active' => '1',
            'additional_categories' => json_encode($add, JSON_UNESCAPED_UNICODE), 'category_prices' => '',
            'total' => (string) $t, 'used' => (string) $u, 'amount_left' => (string) max(0, $t - $u),
        ];
    }
    api_json($out);
} catch (Throwable $e) {
    error_log('partners_list: ' . $e->getMessage());
    api_json([]);
}
