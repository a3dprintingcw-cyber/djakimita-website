<?php
// All database reads for the public website. Prepared statements only.

// voucher_packages / website_product_prices ids
const CARD_TYPES = [
    'kids'   => ['package' => 1, 'product' => 1],
    'teen'   => ['package' => 2, 'product' => 2],
    'adult'  => ['package' => 3, 'product' => 3],
    'family' => ['package' => 4, 'product' => 4],
    'senior' => ['package' => 5, 'product' => 5],
];
const ESIM_PRODUCT = 6;

function country_id(): int
{
    return (int) config('country_id', 1);
}

/** Prices per product id: ['eur' => .., 'usd' => .., 'months' => ..] */
function prices(): array
{
    static $prices = null;
    if ($prices === null) {
        $prices = [];
        foreach (db()->query('SELECT product_id, eur, dollars, months_for_expire FROM website_product_prices') as $r) {
            $prices[(int) $r['product_id']] = [
                'eur' => (float) $r['eur'],
                'usd' => (float) $r['dollars'],
                'months' => (int) $r['months_for_expire'],
            ];
        }
    }
    return $prices;
}

function card_price(string $type, string $currency = 'eur'): float
{
    $id = CARD_TYPES[$type]['product'] ?? 0;
    return prices()[$id][$currency] ?? 0.0;
}

function card_months(): int
{
    return prices()[CARD_TYPES['adult']['product']]['months'] ?? 2;
}

function categories(): array
{
    // Only categories that actually have active partners in this program.
    $sql = 'SELECT c.id, TRIM(c.name) AS name, COUNT(DISTINCT v.id) AS n
            FROM category c
            JOIN voucher_company v ON v.active = 1 AND v.country = :country
              AND (v.category_id = c.id OR JSON_CONTAINS(COALESCE(NULLIF(v.additional_category_ids, ""), "[]"), CONCAT(c.id)))
            WHERE c.active = 1 AND c.name NOT LIKE :catchall
            GROUP BY c.id, c.name
            ORDER BY n DESC, name';
    $st = db()->prepare($sql);
    $st->execute(['country' => country_id(), 'catchall' => '%All the%']);
    return $st->fetchAll();
}

/** Partner list with best discount and first photo id. */
function partners(?int $category = null, string $search = '', ?int $limit = null): array
{
    $where = ['v.active = 1', 'v.country = :country'];
    $params = ['country' => country_id()];
    if ($category) {
        $where[] = '(v.category_id = :cat OR JSON_CONTAINS(COALESCE(NULLIF(v.additional_category_ids, ""), "[]"), :cat2))';
        $params['cat'] = $category;
        $params['cat2'] = (string) $category;
    }
    if ($search !== '') {
        $where[] = '(v.name LIKE :q OR v.description LIKE :q2 OR v.location LIKE :q3)';
        $params['q'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
    }
    $sql = 'SELECT v.id, TRIM(v.name) AS name, v.location, v.category_id, TRIM(c.name) AS category,
                   LEFT(v.description, 400) AS description,
                   MAX(d.the_percent) AS best_percent,
                   (SELECT i.id FROM voucher_company_images i WHERE i.company_id = v.id ORDER BY i.sort_order, i.id LIMIT 1) AS image_id,
                   (v.picture IS NOT NULL AND LENGTH(v.picture) > 0) AS has_picture
            FROM voucher_company v
            LEFT JOIN category c ON c.id = v.category_id
            LEFT JOIN voucher_company_info_deals d ON d.company_id = v.id
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY v.id
            ORDER BY best_percent DESC, v.name';
    if ($limit) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function partner(int $id): ?array
{
    $st = db()->prepare('SELECT v.id, TRIM(v.name) AS name, v.description, v.website, v.phone, v.email,
                                v.opening_hours, v.location, TRIM(c.name) AS category,
                                (v.picture IS NOT NULL AND LENGTH(v.picture) > 0) AS has_picture
                         FROM voucher_company v LEFT JOIN category c ON c.id = v.category_id
                         WHERE v.id = :id AND v.active = 1 AND v.country = :country');
    $st->execute(['id' => $id, 'country' => country_id()]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $img = db()->prepare('SELECT id FROM voucher_company_images WHERE company_id = :id ORDER BY sort_order, id');
    $img->execute(['id' => $id]);
    $p['images'] = array_column($img->fetchAll(), 'id');

    $deals = db()->prepare('SELECT voucher_id, was, after, the_percent FROM voucher_company_info_deals
                            WHERE company_id = :id ORDER BY voucher_id');
    $deals->execute(['id' => $id]);
    $p['deals'] = $deals->fetchAll();

    $p['hours'] = json_decode((string) $p['opening_hours'], true) ?: [];
    return $p;
}

function partner_count(): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM voucher_company WHERE active = 1 AND country = :c');
    $st->execute(['c' => country_id()]);
    return (int) $st->fetchColumn();
}

function best_discount(): int
{
    $st = db()->prepare('SELECT MAX(d.the_percent) FROM voucher_company_info_deals d
                         JOIN voucher_company v ON v.id = d.company_id AND v.active = 1 AND v.country = :c');
    $st->execute(['c' => country_id()]);
    return (int) round((float) $st->fetchColumn());
}

function partner_image_url(array $p, string $size = 'm'): string
{
    if (!empty($p['image_id'])) {
        return url('img.php', ['i' => $p['image_id'], 's' => $size]);
    }
    if (!empty($p['images'][0])) {
        return url('img.php', ['i' => $p['images'][0], 's' => $size]);
    }
    if (!empty($p['has_picture'])) {
        return url('img.php', ['c' => $p['id'], 's' => $size]);
    }
    return url('assets/img/placeholder.svg');
}

function discount_label($percent): string
{
    $p = (int) round((float) $percent);
    if ($p <= 0) {
        return '';
    }
    return $p >= 100 ? t('deals.free') : t('deals.upto', ['p' => $p]);
}

function package_label(int $packageId): string
{
    foreach (CARD_TYPES as $key => $ids) {
        if ($ids['package'] === $packageId) {
            return t($key);
        }
    }
    return '';
}
