<?php
declare(strict_types=1);
function oracle_account_banned(int $id): bool {
    $state = json_decode((string)@file_get_contents(oracle_progress_directory() . '/contest-state.json'), true);
    return is_array($state) && in_array($id, $state['banned_accounts'] ?? [], true);
}

function oracle_progress_directory(): string {
    return rtrim((string)(getenv('PORTAL_SESSION_PATH') ?: (getenv('SESSION_PATH') ?: '/var/www/portal-sessions')), '/\\');
}

function oracle_progress_epoch(int $account): string {
    return trim((string)@file_get_contents(oracle_progress_directory() . '/progress-' . $account . '.epoch')) ?: '0';
}

function oracle_progress_suffix(int $account): string {
    $epoch = oracle_progress_epoch($account);
    return $epoch === '0' ? '' : ':' . $epoch;
}

// Readers hold a shared lock until their PHP request ends. A reset waits for
// in-flight writes before clearing data and rotating the account's epoch.
function oracle_progress_guard(int $account): void {
    static $handles = [];
    if (isset($handles[$account])) return;
    $handle = @fopen(oracle_progress_directory() . '/progress-' . $account . '.lock', 'rb');
    if ($handle === false) return; // Accounts launched before this feature.
    if (!flock($handle, LOCK_SH)) throw new RuntimeException('Não foi possível conferir o progresso.');
    $handles[$account] = $handle;
}
