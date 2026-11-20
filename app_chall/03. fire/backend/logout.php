<?php
require __DIR__ . '/lib.php';
$s = fire_current();
if ($s) apcu_delete(fire_state_key($s['user'], 'workshop-used'));
setcookie('sessions', '', ['path' => '/', 'expires' => time() - 3600]);
fire_json(['ok' => true]);
