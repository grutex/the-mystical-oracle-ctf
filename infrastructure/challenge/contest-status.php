<?php
declare(strict_types=1);
require __DIR__ . '/challenge-auth.php';
$directory = getenv('PORTAL_SESSION_PATH') ?: '/var/www/portal-sessions';
$state = json_decode((string)@file_get_contents($directory . '/contest-state.json'), true);
if (!is_array($state)) challenge_json(['error' => 'Relógio aguardando o portal.'], 503);
$authenticated = challenge_identity() !== null;
$identity = challenge_verify($_COOKIE['oracle_challenge'] ?? null, challenge_key());
$progressReset = $identity !== null && !hash_equals(oracle_progress_epoch((int)$identity['uid']), (string)($identity['progress_epoch'] ?? '0'));
if (str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'depth-water.')) {
    require_once dirname(__DIR__) . '/private/water-chain.php';
    $acct = water_entry_acct($_COOKIE['oracle_depth'] ?? null);
    $authenticated = $acct !== null && !challenge_account_paused($acct);
    $progressReset = isset($_COOKIE['oracle_depth']) && $acct === null;
}
$stop = $state['paused_at'] ?: ($state['closed_at'] ?: gmdate('Y-m-d H:i:s'));
$elapsed = $state['started_at'] ? max(0, strtotime($stop . ' UTC') - strtotime($state['started_at'] . ' UTC') - (float)$state['paused_seconds']) : 0;
challenge_json(['contest' => [
    'started_at' => $state['started_at'], 'paused_at' => $state['paused_at'],
    'closed_at' => $state['closed_at'], 'elapsed_seconds' => $elapsed
], 'authenticated' => $authenticated, 'session_lost' => !$authenticated, 'progress_reset' => $progressReset]);
