<?php
declare(strict_types=1);
require_once __DIR__ . '/challenge-auth.php';
require_once dirname(__DIR__) . '/private/water-chain.php';

function depth_account_id(): int {
    $acct = water_entry_acct($_COOKIE['oracle_depth'] ?? null);
    if (!$acct) challenge_json(['error' => 'A Arquivista exige o ingresso trazido da superfície.'], 401);
    if (challenge_account_paused($acct)) challenge_json(['error' => 'O CTF está pausado.'], 401);
    return $acct;
}

function depth_escape(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function depth_csrf(int $acct): string {
    return challenge_b64url_encode(hash_hmac('sha256', 'water-depth-csrf:' . $acct, challenge_account_secret(), true));
}
