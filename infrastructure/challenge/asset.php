<?php
declare(strict_types=1);

require __DIR__ . '/challenge-auth.php';

if (!challenge_identity()) {
    http_response_code(401);
    exit('Entre no portal e abra o desafio novamente.');
}

$requested = ltrim(str_replace('\\', '/', (string)($_GET['path'] ?? '')), '/');
$aliases = [
    'veil.css' => '0232a57db92c1c62161124a6ed855f96/veil.css',
    'veil.js' => '0232a57db92c1c62161124a6ed855f96/veil.js',
];
$requested = $aliases[$requested] ?? $requested;

$root = realpath(__DIR__);
$path = realpath(__DIR__ . '/' . $requested);
$allowedExtensions = ['css', 'js', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2'];
$extension = strtolower(pathinfo($requested, PATHINFO_EXTENSION));

if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
    || !is_file($path) || !in_array($extension, $allowedExtensions, true)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$types = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'webp' => 'image/webp',
    'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
];

header('Content-Type: ' . $types[$extension]);
header('Cache-Control: private, max-age=300');
readfile($path);
