<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$dbHost = getenv('SCHEDULER_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('SCHEDULER_DB_NAME') ?: 'classroom_scheduler';
$dbUser = getenv('SCHEDULER_DB_USER') ?: 'root';
$dbPassword = getenv('SCHEDULER_DB_PASSWORD');
if ($dbPassword === false) {
    $dbPassword = '';
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $exception) {
    http_response_code(500);
    exit(
        'Unable to connect to MySQL. Import classroom_scheduler.sql in MySQL Workbench, '
        . 'start MySQL in XAMPP, and check the database settings in config.php.'
    );
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Your form session expired. Please go back, refresh, and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function redirect(string $location = 'index.php'): void
{
    header('Location: ' . $location);
    exit;
}

function require_admin(): void
{
    if (empty($_SESSION['admin_id'])) {
        redirect('index.php');
    }
}
