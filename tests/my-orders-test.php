<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/my-orders-auth.php';

$passed = 0;

function my_orders_test(string $name, callable $callback): void
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

function my_orders_assert_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function my_orders_test_database(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_uid TEXT NOT NULL UNIQUE,
            public_number TEXT,
            payment_status TEXT NOT NULL,
            size_title TEXT,
            size_value TEXT,
            size_price TEXT,
            pet_name TEXT,
            pet_photo_path TEXT,
            customer_name TEXT,
            customer_address TEXT,
            customer_email TEXT,
            delivery_type TEXT,
            delivery_service TEXT,
            pickup_address TEXT,
            delivery_price TEXT,
            amount TEXT,
            created_at TEXT,
            paid_at TEXT
        );
        CREATE TABLE magic_link_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            used_at TEXT,
            request_ip_hash TEXT,
            created_at TEXT NOT NULL
        );'
    );

    return $pdo;
}

function insert_my_orders_fixture(
    PDO $pdo,
    string $uid,
    string $email,
    string $status,
    string $photoPath = 'private:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO orders (
            order_uid, public_number, payment_status, size_title, size_value, size_price,
            pet_name, pet_photo_path, customer_name, customer_address,
            customer_email, delivery_type, delivery_price, amount, created_at
         ) VALUES (
            :uid, :number, :status, :size_title, :size_value, :size_price,
            :pet_name, :photo_path, :customer_name, :customer_address,
            :email, :delivery_type, :delivery_price, :amount, :created_at
         )'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':number' => 'PET-20260727-' . substr($uid, 0, 6),
        ':status' => $status,
        ':size_title' => 'Средний',
        ':size_value' => '4 x 2,5 см',
        ':size_price' => '1299 ₽',
        ':pet_name' => 'Чиж',
        ':photo_path' => $photoPath,
        ':customer_name' => 'Иван',
        ':customer_address' => 'Москва',
        ':email' => $email,
        ':delivery_type' => 'standard',
        ':delivery_price' => '200.00',
        ':amount' => '1499.00',
        ':created_at' => '2026-07-27 12:00:00',
    ]);
}

my_orders_test('magic token is cryptographically random and only its hash is storable', function (): void {
    $first = create_magic_link_token();
    $second = create_magic_link_token();

    my_orders_assert_same(43, strlen($first['token']));
    my_orders_assert_same(64, strlen($first['hash']));
    my_orders_assert_same($first['hash'], magic_link_token_hash($first['token']));
    my_orders_assert_same(false, hash_equals($first['token'], $first['hash']));
    my_orders_assert_same(false, hash_equals($first['token'], $second['token']));
});

my_orders_test('invalid, expired and already used tokens are rejected', function (): void {
    $now = new DateTimeImmutable('2026-07-27 12:00:00', new DateTimeZone('UTC'));

    my_orders_assert_same(null, magic_link_token_hash('short-token'));
    my_orders_assert_same(false, magic_link_token_is_usable([
        'email' => 'owner@example.com',
        'expires_at' => '2026-07-27 11:59:59',
        'used_at' => null,
    ], $now));
    my_orders_assert_same(false, magic_link_token_is_usable([
        'email' => 'owner@example.com',
        'expires_at' => '2026-07-27 12:30:00',
        'used_at' => '2026-07-27 11:50:00',
    ], $now));
});

my_orders_test('magic link can be consumed only once', function (): void {
    $pdo = my_orders_test_database();
    $token = create_magic_link_token();
    $stmt = $pdo->prepare(
        'INSERT INTO magic_link_tokens (
            email, token_hash, expires_at, created_at
         ) VALUES (
            :email, :hash, :expires_at, :created_at
         )'
    );
    $stmt->execute([
        ':email' => 'owner@example.com',
        ':hash' => $token['hash'],
        ':expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
        ':created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    my_orders_assert_same('owner@example.com', consume_magic_link_token($pdo, $token['token']));
    my_orders_assert_same(null, consume_magic_link_token($pdo, $token['token']));
    my_orders_assert_same(
        1,
        (int) $pdo->query('SELECT COUNT(*) FROM magic_link_tokens WHERE used_at IS NOT NULL')->fetchColumn()
    );
});

my_orders_test('an email sees its paid and pending orders only', function (): void {
    $pdo = my_orders_test_database();
    insert_my_orders_fixture($pdo, str_repeat('a', 32), 'owner@example.com', 'paid');
    insert_my_orders_fixture($pdo, str_repeat('b', 32), 'owner@example.com', 'pending_payment');
    insert_my_orders_fixture($pdo, str_repeat('c', 32), 'other@example.com', 'paid');

    $orders = find_orders_by_customer_email($pdo, ' OWNER@example.com ');

    my_orders_assert_same(2, count($orders));
    my_orders_assert_same(
        ['owner@example.com'],
        array_values(array_unique(array_column($orders, 'customer_email')))
    );
    my_orders_assert_same('200.00', $orders[0]['delivery_price']);
    $statuses = array_values(array_unique(array_column($orders, 'payment_status')));
    sort($statuses);
    my_orders_assert_same(['paid', 'pending_payment'], $statuses);
});

my_orders_test('another customer and a numeric id cannot access a photo order', function (): void {
    $pdo = my_orders_test_database();
    $uid = str_repeat('d', 32);
    insert_my_orders_fixture($pdo, $uid, 'owner@example.com', 'paid');

    my_orders_assert_same(null, find_customer_order_by_uid($pdo, 'other@example.com', $uid));
    my_orders_assert_same(null, find_customer_order_by_uid($pdo, 'owner@example.com', '1'));
    my_orders_assert_same($uid, find_customer_order_by_uid($pdo, 'owner@example.com', $uid)['order_uid']);
});

my_orders_test('customers see only three simple Russian statuses', function (): void {
    $expected = [
        'pending_payment' => 'Ожидает оплаты',
        'paid' => 'Оплачен',
        'cancelled' => 'Отменён',
    ];

    foreach ($expected as $status => $label) {
        my_orders_assert_same($label, order_status_label($status));
    }

    foreach (['in_production', 'shipped', 'completed'] as $historicalStatus) {
        my_orders_assert_same('Оплачен', order_status_label($historicalStatus));
        my_orders_assert_same('paid', order_public_status_key($historicalStatus));
    }
});

my_orders_test('customer payment email contains public number, sum and orders button', function (): void {
    $order = [
        'public_number' => 'PET-20260727-000123',
        'delivery_price' => '200.00',
        'amount' => '1499.00',
        'payment_status' => 'paid',
    ];
    $plain = build_customer_payment_email_plain($order, 'https://petfolio.ru/my-orders/');
    $html = build_customer_payment_email_html($order, 'https://petfolio.ru/my-orders/');

    my_orders_assert_same(true, str_contains($plain, 'PET-20260727-000123'));
    my_orders_assert_same(true, str_contains($plain, '1499.00'));
    my_orders_assert_same(true, str_contains($plain, 'доставка: 200.00'));
    my_orders_assert_same(true, str_contains($plain, 'Оплачен'));
    my_orders_assert_same(true, str_contains($html, 'https://petfolio.ru/my-orders/'));
});

my_orders_test('public order number is stable and not a bare numeric id', function (): void {
    $number = create_order_public_number(
        42,
        new DateTimeImmutable('2026-07-27 12:00:00', new DateTimeZone('UTC'))
    );

    my_orders_assert_same('PET-20260727-000042', $number);
});

echo "My orders tests passed: {$passed}" . PHP_EOL;
