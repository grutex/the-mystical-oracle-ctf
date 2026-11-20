<?php
require __DIR__ . '/lib.php';
$s = fire_current();
$portalUser = fire_portal_user();
if (!$s) fire_json(['role' => 'guest', 'portal_user' => $portalUser]);
$data = ['user' => $s['user'] ?? null, 'role' => $s['role'], 'portal_user' => $portalUser];
if ($s['role'] === 'superadmin') {
    $data['users'] = fire_db()->query('SELECT username, role FROM users ORDER BY username COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
}
fire_json($data);
