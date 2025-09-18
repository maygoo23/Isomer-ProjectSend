<?php
declare(strict_types=1);

// Non-interactive installer/upgrader for container runtime

define('IS_INSTALL', true);

define('ABS_PARENT', dirname(__DIR__));

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) {
    if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        return false;
    }
    fwrite(STDERR, sprintf('[install-cli] PHP error %d: %s at %s:%d\n', $severity, $message, $file, $line));
    return false;
});

set_exception_handler(function (\Throwable $exception): void {
    fwrite(STDERR, sprintf('[install-cli] Uncaught %s: %s in %s:%d\n', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
    exit(255);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, sprintf('[install-cli] Fatal error: %s in %s:%d\n', $error['message'], $error['file'], $error['line']));
        exit(255);
    }
});

if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] === '') {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
if (!isset($_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI'] === '') {
    $_SERVER['REQUEST_URI'] = '/';
}
if (!isset($_SERVER['SERVER_NAME']) || $_SERVER['SERVER_NAME'] === '') {
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'];
}

require_once ABS_PARENT . '/bootstrap.php';

function env_value(string $key, string $default): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function env_bool(string $key, bool $default = true): bool
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

$baseUri = rtrim(env_value('PROJECTSEND_BASE_URI', 'http://localhost:8080/'), '/') . '/';
$siteTitle = env_value('PROJECTSEND_SITE_TITLE', 'ProjectSend');
$adminName = env_value('PROJECTSEND_ADMIN_NAME', 'ProjectSend Administrator');
$adminUsername = env_value('PROJECTSEND_ADMIN_USERNAME', 'admin');
$adminEmail = env_value('PROJECTSEND_ADMIN_EMAIL', 'admin@example.com');
$adminPasswordPlain = env_value('PROJECTSEND_ADMIN_PASSWORD', 'projectsend');
$runUpgrades = env_bool('PROJECTSEND_RUN_DB_UPGRADES', true);

if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "[install-cli] Invalid PROJECTSEND_ADMIN_EMAIL value\n");
    exit(1);
}

if (!preg_match('/^[A-Za-z0-9_]+$/', $adminUsername)) {
    fwrite(STDERR, "[install-cli] PROJECTSEND_ADMIN_USERNAME must contain only letters, numbers, and underscores\n");
    exit(1);
}

if (strlen($adminUsername) < MIN_USER_CHARS || strlen($adminUsername) > MAX_USER_CHARS) {
    fwrite(STDERR, sprintf(
        "[install-cli] PROJECTSEND_ADMIN_USERNAME length must be between %d and %d characters\n",
        MIN_USER_CHARS,
        MAX_USER_CHARS
    ));
    exit(1);
}

if (strlen($adminPasswordPlain) < MIN_PASS_CHARS || strlen($adminPasswordPlain) > MAX_PASS_CHARS) {
    fwrite(STDERR, sprintf(
        "[install-cli] PROJECTSEND_ADMIN_PASSWORD length must be between %d and %d characters\n",
        MIN_PASS_CHARS,
        MAX_PASS_CHARS
    ));
    exit(1);
}

$adminPassword = password_hash($adminPasswordPlain, PASSWORD_DEFAULT, ['cost' => HASH_COST_LOG2]);

if (!function_exists('is_projectsend_installed')) {
    fwrite(STDERR, "[install-cli] bootstrap did not load expected helpers\n");
    exit(1);
}

global $dbh;

$initiallyInstalled = is_projectsend_installed();

if (!$initiallyInstalled) {
    require_once ROOT_DIR . '/install/database.php';

    $queries = get_install_base_queries([
        'base_uri' => $baseUri,
        'install_title' => $siteTitle,
        'admin' => [
            'name' => $adminName,
            'username' => $adminUsername,
            'email' => $adminEmail,
            'pass' => $adminPassword,
        ],
    ]);

    if (!try_queries($queries)) {
        fwrite(STDERR, "[install-cli] Failed while executing installer queries\n");
        exit(1);
    }

    $logger = new \ProjectSend\Classes\ActionsLog();
    $logger->addEntry([
        'action' => 0,
        'owner_id' => 1,
        'owner_user' => $adminUsername,
    ]);

    $directories = [
        ROOT_DIR . '/upload',
        ROOT_DIR . '/upload/temp',
        ROOT_DIR . '/upload/files',
        ROOT_DIR . '/upload/thumbnails',
    ];

    foreach ($directories as $directory) {
        if (!file_exists($directory) && !@mkdir($directory, 0755, true)) {
            fwrite(STDERR, "[install-cli] Warning: unable to create directory {$directory}\n");
        }
        if (!@chmod($directory, 0755)) {
            fwrite(STDERR, "[install-cli] Warning: unable to set permissions on {$directory}\n");
        }
    }

    $emailChmod = update_chmod_emails();
    if (is_array($emailChmod) && !empty($emailChmod)) {
        foreach ($emailChmod as $warning) {
            fwrite(STDERR, "[install-cli] Warning: {$warning}\n");
        }
    }

    $fileChmod = chmod_main_files();
    if (is_array($fileChmod) && !empty($fileChmod)) {
        foreach ($fileChmod as $warning) {
            fwrite(STDERR, "[install-cli] Warning: {$warning}\n");
        }
    }
}

if ($runUpgrades) {
    $upgrade = new \ProjectSend\Classes\DatabaseUpgrade();
    $upgrade->upgradeDatabase(false);
}

if (!$initiallyInstalled) {
    fwrite(STDOUT, "[install-cli] ProjectSend initial database created\n");
} else {
    fwrite(STDOUT, "[install-cli] ProjectSend database already present\n");
}
