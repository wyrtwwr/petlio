<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($autoload)) {
    require_once $autoload;
}

if (class_exists(\Dotenv\Dotenv::class) && is_file(dirname(__DIR__) . '/.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
} elseif (is_file(dirname(__DIR__) . '/.env')) {
    foreach (file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

return [
    'app_name' => 'PETLIO',
    'app_url' => rtrim(env_value('APP_URL', 'http://localhost') ?? 'http://localhost', '/'),

    'db' => [
        'host' => env_value('DB_HOST', 'localhost'),
        'name' => env_value('DB_NAME', ''),
        'user' => env_value('DB_USER', ''),
        'pass' => env_value('DB_PASS', ''),
    ],

    'yookassa' => [
        'shop_id' => env_value('YOOKASSA_SHOP_ID', ''),
        'secret_key' => env_value('YOOKASSA_SECRET_KEY', ''),
    ],

    'order_email' => env_value('ORDER_EMAIL', 'ppetfoli@mail.ru'),

    'smtp' => [
        'host' => env_value('SMTP_HOST', ''),
        'port' => (int) (env_value('SMTP_PORT', '465') ?? 465),
        'user' => env_value('SMTP_USER', ''),
        'pass' => env_value('SMTP_PASS', ''),
        'from' => env_value('SMTP_FROM', ''),
        'from_name' => env_value('SMTP_FROM_NAME', 'PETLIO'),
    ],
];
