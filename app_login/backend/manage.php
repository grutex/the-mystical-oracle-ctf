<?php
declare(strict_types=1);
// CLI only: no default credentials and no public administrator registration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
if ($argc !== 4 || !in_array($argv[1], ['admin', 'player', 'test', 'logs'], true)) {
    fwrite(STDERR, "Uso: ORACLE_ACCOUNT_PASSWORD=<senha> php manage.php admin|player|test|logs username email\n");
    exit(1);
}
[$script, $role, $username, $email] = $argv;
$username = strtolower(trim($username));
$email = strtolower(trim($email));
$password = getenv('ORACLE_ACCOUNT_PASSWORD') ?: '';
if (!preg_match('/^[a-z0-9_.-]{3,32}$/D', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !validAccountPassword($password)) {
    fwrite(STDERR, "Informe username, e-mail e uma senha com ao menos 9 caracteres, uma letra maiúscula e um símbolo em ORACLE_ACCOUNT_PASSWORD.\n");
    exit(1);
}
try {
    $result = contestTransaction(function ($contest) use ($role, $username, $email, $password) {
        if ($contest['closed_at']) return new ApiError('CTF encerrado: contas preservadas.');
        $existing = query('SELECT acc_id FROM Account WHERE username = ?', [$username])->fetchColumn();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $accountRole = $role === 'test' ? 'player' : $role;
        $ignoreLeaderboard = in_array($role, ['test', 'logs'], true) ? 1 : 0;
        if ($existing) {
            query('UPDATE Account SET email = ?, password_hash = ?, acc_role = ?, ignore_leaderboard = ? WHERE acc_id = ?',
                [$email, $hash, $accountRole, $ignoreLeaderboard, $existing]);
        } else {
            query('INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard) VALUES (?, ?, ?, ?, ?, ?)',
                [$username, $username, $email, $hash, $accountRole, $ignoreLeaderboard]);
            query('INSERT INTO CtfInfo (user_id) VALUES (?)', [(int)db()->lastInsertId()]);
        }
        return ['ok' => true];
    });
    fwrite(STDOUT, "Conta configurada.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Não foi possível configurar a conta: " . $e->getMessage() . "\n");
    exit(1);
}
