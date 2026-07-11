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

if (!function_exists('env_value')) {
    function env_value(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default = false): bool
    {
        $value = env_value($key);

        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw new RuntimeException(sprintf('%s must be a boolean value.', $key));
        }

        return $parsed;
    }
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

    'robokassa' => [
        'merchant_login' => env_value('ROBOKASSA_MERCHANT_LOGIN', ''),
        'password1' => env_value('ROBOKASSA_PASSWORD1', ''),
        'password2' => env_value('ROBOKASSA_PASSWORD2', ''),
        'password3' => env_value('ROBOKASSA_PASSWORD3', ''),
        'test_password1' => env_value('ROBOKASSA_TEST_PASSWORD1', ''),
        'test_password2' => env_value('ROBOKASSA_TEST_PASSWORD2', ''),
        'test' => env_bool('ROBOKASSA_TEST', true),
        'hash_algorithm' => strtolower(env_value('ROBOKASSA_HASH_ALGORITHM', 'md5') ?? 'md5'),
        'receipt' => [
            'enabled' => env_bool('ROBOKASSA_RECEIPT_ENABLED', false),
            'sno' => env_value('ROBOKASSA_RECEIPT_SNO', ''),
            'payment_method' => env_value('ROBOKASSA_RECEIPT_PAYMENT_METHOD', ''),
            'payment_object' => env_value('ROBOKASSA_RECEIPT_PAYMENT_OBJECT', ''),
            'tax' => env_value('ROBOKASSA_RECEIPT_TAX', ''),
        ],
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
