<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/robokassa.php';

$passed = 0;

function test_case(string $name, callable $callback): void
{
    global $passed;

    try {
        $callback();
        $passed++;
        echo "[OK] {$name}" . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, "[FAIL] {$name}: {$error->getMessage()}" . PHP_EOL);
        exit(1);
    }
}

function assert_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_throws(string $className, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $className) {
            return;
        }

        throw new RuntimeException('Expected ' . $className . ', got ' . get_class($error));
    }

    throw new RuntimeException('Expected exception ' . $className . ' was not thrown.');
}

function fake_config(bool $test = true, array $overrides = []): array
{
    $config = [
        'robokassa' => [
            'merchant_login' => 'Petfolio.ru',
            'password1' => 'prod-password-1',
            'password2' => 'prod-password-2',
            'password3' => 'prod-password-3',
            'test_password1' => 'test-password-1',
            'test_password2' => 'test-password-2',
            'test' => $test,
            'hash_algorithm' => 'md5',
            'receipt' => [
                'enabled' => false,
                'sno' => '',
                'payment_method' => '',
                'payment_object' => '',
                'tax' => '',
            ],
        ],
    ];

    foreach ($overrides as $key => $value) {
        $config['robokassa'][$key] = $value;
    }

    return $config;
}

function parse_payment_url(string $url): array
{
    $query = parse_url($url, PHP_URL_QUERY);
    parse_str((string) $query, $params);

    return $params;
}

test_case('normalizes rubles without kopecks', function (): void {
    assert_same('1099.00', robokassa_normalize_amount('1099'));
});

test_case('normalizes one kopeck digit and leading zeros', function (): void {
    assert_same('1.50', robokassa_normalize_amount('001.5'));
});

test_case('rejects invalid amount format', function (): void {
    assert_throws(InvalidArgumentException::class, fn (): string => robokassa_normalize_amount('10.999'));
});

test_case('converts amount to kopecks', function (): void {
    assert_same(129950, robokassa_amount_to_kopecks('1299.50'));
});

test_case('builds md5 signature', function (): void {
    assert_same(md5('Petfolio.ru:1099.00:1:secret'), robokassa_signature(['Petfolio.ru', '1099.00', '1', 'secret']));
});

test_case('compares signatures case-insensitively', function (): void {
    $signature = robokassa_signature(['OutSum', 'InvId', 'Password2']);
    assert_true(robokassa_signatures_match(strtoupper($signature), $signature));
});

test_case('selects test passwords in test mode', function (): void {
    $config = fake_config(true);
    assert_same('test-password-1', robokassa_password1($config));
    assert_same('test-password-2', robokassa_password2($config));
});

test_case('selects production passwords in production mode', function (): void {
    $config = fake_config(false);
    assert_same('prod-password-1', robokassa_password1($config));
    assert_same('prod-password-2', robokassa_password2($config));
});

test_case('rejects unsupported hash algorithm', function (): void {
    $config = fake_config(true, ['hash_algorithm' => 'sha256']);
    assert_throws(RuntimeException::class, fn (): string => robokassa_hash_algorithm($config));
});

test_case('rejects missing merchant login', function (): void {
    $config = fake_config(true, ['merchant_login' => '']);
    assert_throws(RuntimeException::class, fn () => robokassa_assert_configured($config));
});

test_case('rejects missing active test password', function (): void {
    $config = fake_config(true, ['test_password1' => '']);
    assert_throws(RuntimeException::class, fn (): string => robokassa_password1($config));
});

test_case('builds test payment URL with required parameters', function (): void {
    $config = fake_config(true);
    $url = robokassa_build_payment_url($config, ['amount' => '1099.00'], 25);
    $params = parse_payment_url($url);

    assert_true(str_starts_with($url, ROBOKASSA_PAYMENT_URL . '?'));
    assert_same('Petfolio.ru', $params['MerchantLogin'] ?? null);
    assert_same('1099.00', $params['OutSum'] ?? null);
    assert_same('25', $params['InvId'] ?? null);
    assert_same('1', $params['IsTest'] ?? null);
    assert_same('ru', $params['Culture'] ?? null);
    assert_same('utf-8', $params['Encoding'] ?? null);
    assert_same(
        robokassa_signature(['Petfolio.ru', '1099.00', '25', 'test-password-1']),
        $params['SignatureValue'] ?? null
    );
});

test_case('builds production payment URL without IsTest', function (): void {
    $params = parse_payment_url(robokassa_build_payment_url(fake_config(false), ['amount' => '1399.00'], 26));
    assert_true(!isset($params['IsTest']));
    assert_same(
        robokassa_signature(['Petfolio.ru', '1399.00', '26', 'prod-password-1']),
        $params['SignatureValue'] ?? null
    );
});

test_case('rejects non-positive InvId for payment URL', function (): void {
    assert_throws(InvalidArgumentException::class, fn (): string => robokassa_build_payment_url(fake_config(), ['amount' => '1099.00'], 0));
});

test_case('requires tax when receipt is enabled', function (): void {
    $config = fake_config(true, [
        'receipt' => [
            'enabled' => true,
            'sno' => '',
            'payment_method' => '',
            'payment_object' => '',
            'tax' => '',
        ],
    ]);
    assert_throws(RuntimeException::class, fn (): string => robokassa_build_payment_url($config, ['amount' => '1099.00'], 27));
});

test_case('adds receipt to signature when enabled', function (): void {
    $config = fake_config(true, [
        'receipt' => [
            'enabled' => true,
            'sno' => 'osn',
            'payment_method' => 'full_payment',
            'payment_object' => 'commodity',
            'tax' => 'none',
        ],
    ]);
    $params = parse_payment_url(robokassa_build_payment_url($config, ['amount' => '1099.00'], 28));
    $receipt = json_decode(urldecode((string) $params['Receipt']), true, 512, JSON_THROW_ON_ERROR);

    assert_same('none', $receipt['items'][0]['tax'] ?? null);
    assert_same(
        robokassa_signature(['Petfolio.ru', '1099.00', '28', $params['Receipt'], 'test-password-1']),
        $params['SignatureValue'] ?? null
    );
});

test_case('validates result callback params', function (): void {
    $signature = robokassa_signature(['1099.00', '25', 'test-password-2']);
    $result = robokassa_validate_result_params([
        'OutSum' => '1099.00',
        'InvId' => '25',
        'SignatureValue' => $signature,
    ], fake_config(true));

    assert_same(25, $result['inv_id']);
    assert_same('1099.00', $result['out_sum']);
});

test_case('accepts uppercase callback signature', function (): void {
    $signature = strtoupper(robokassa_signature(['1099.00', '25', 'test-password-2']));
    $result = robokassa_validate_result_params([
        'OutSum' => '1099.00',
        'InvId' => '25',
        'SignatureValue' => $signature,
    ], fake_config(true));

    assert_same(25, $result['inv_id']);
});

test_case('rejects missing callback params', function (): void {
    assert_throws(InvalidArgumentException::class, fn (): array => robokassa_validate_result_params([], fake_config(true)));
});

test_case('rejects invalid callback InvId', function (): void {
    assert_throws(InvalidArgumentException::class, fn (): array => robokassa_validate_result_params([
        'OutSum' => '1099.00',
        'InvId' => '0',
        'SignatureValue' => 'x',
    ], fake_config(true)));
});

test_case('rejects invalid callback OutSum', function (): void {
    assert_throws(InvalidArgumentException::class, fn (): array => robokassa_validate_result_params([
        'OutSum' => '1099.999',
        'InvId' => '25',
        'SignatureValue' => 'x',
    ], fake_config(true)));
});

test_case('rejects invalid callback signature', function (): void {
    assert_throws(UnexpectedValueException::class, fn (): array => robokassa_validate_result_params([
        'OutSum' => '1099.00',
        'InvId' => '25',
        'SignatureValue' => 'bad',
    ], fake_config(true)));
});

echo "Robokassa helper tests passed: {$passed}" . PHP_EOL;
