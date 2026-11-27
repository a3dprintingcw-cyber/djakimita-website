<?php
// Categories that are switched on in the backend (Backend → Categories). The app only shows these.
require __DIR__ . '/_db.php';
try {
    $rows = api_db()->query('SELECT id, TRIM(name) AS name FROM category WHERE active = 1 ORDER BY name')->fetchAll();
    api_json(array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $rows));
} catch (Throwable $e) {
    api_json([]);
}
