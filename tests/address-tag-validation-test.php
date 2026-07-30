<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/security.php';
require_once __DIR__ . '/../backend/address-tag-validation.php';
require_once __DIR__ . '/../backend/order-data.php';
require_once __DIR__ . '/../backend/robokassa.php';
require_once __DIR__ . '/../backend/send-order-email.php';

$passed = 0;

function validation_test(string $name, callable $callback): void
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

function validation_assert_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function validation_assert_api_error(int $status, callable $callback): void
{
    try {
        $callback();
    } catch (ApiRequestException $error) {
        validation_assert_same($status, $error->status);
        return;
    }

    throw new RuntimeException('Expected ApiRequestException was not thrown.');
}

function valid_address_tag_data(): array
{
    return [
        'photo' => 'data:image/png;base64,iVBORw0KGgo=',
        'size' => 'medium',
        'name' => 'Чиж',
        'birthday' => '15.06.2020',
        'breed' => 'Пудель',
        'address' => 'г. Москва',
        'phone' => '+7 (999) 123-45-67',
    ];
}

function valid_order_payload(): array
{
    return [
        'pet' => [
            'photo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'name' => 'Чиж',
            'birthday' => '15.06.2020',
            'breed' => 'Пудель',
            'address' => 'г. Москва',
            'phone' => '+7 (999) 123-45-67',
        ],
        'size' => ['key' => 'medium'],
        'customer' => [
            'name' => 'Иван Иванов',
            'address' => 'г. Москва, ул. Ленина, 1',
            'email' => 'ivan@example.com',
        ],
        'delivery' => [
            'type' => 'standard',
            'service' => 'СДЭК',
            'pickupAddress' => 'г. Москва, ул. Ленина, 2',
        ],
        'consent' => ['privacyPolicy' => true],
    ];
}

$today = new DateTimeImmutable('2026-07-27');

validation_test('accepts complete address tag data', function () use ($today): void {
    validation_assert_same([], address_tag_validation_errors(valid_address_tag_data(), 'payload', $today));
});

validation_test('rejects all empty fields', function () use ($today): void {
    validation_assert_same(
        ['photo', 'size', 'name', 'birthday', 'breed', 'address', 'phone'],
        array_keys(address_tag_validation_errors([], 'payload', $today))
    );
});

validation_test('treats Не указано and whitespace as missing', function () use ($today): void {
    $data = valid_address_tag_data();
    $data['name'] = ' Не указано ';
    $data['breed'] = ' ';
    $errors = address_tag_validation_errors($data, 'payload', $today);

    validation_assert_same('Укажите имя питомца', $errors['name'] ?? null);
    validation_assert_same('Укажите породу питомца', $errors['breed'] ?? null);
});

validation_test('reports only one missing field', function () use ($today): void {
    $data = valid_address_tag_data();
    $data['address'] = null;

    validation_assert_same(
        ['address'],
        array_keys(address_tag_validation_errors($data, 'payload', $today))
    );
});

validation_test('rejects invalid calendar date', function () use ($today): void {
    $data = valid_address_tag_data();
    $data['birthday'] = '31.02.2020';

    validation_assert_same(
        'Укажите корректную дату рождения',
        address_tag_validation_errors($data, 'payload', $today)['birthday'] ?? null
    );
});

validation_test('rejects future date', function () use ($today): void {
    $data = valid_address_tag_data();
    $data['birthday'] = '28.07.2026';

    validation_assert_same(
        'Дата рождения не может быть в будущем',
        address_tag_validation_errors($data, 'payload', $today)['birthday'] ?? null
    );
});

validation_test('accepts formatted phone', function (): void {
    validation_assert_same(true, address_tag_phone_is_valid('+7 (999) 123-45-67'));
});

validation_test('rejects short phone and letters', function (): void {
    validation_assert_same(false, address_tag_phone_is_valid('+7 (123) 45-67'));
    validation_assert_same(false, address_tag_phone_is_valid('+7 call-me-now'));
});

validation_test('rejects default image path', function () use ($today): void {
    $data = valid_address_tag_data();
    $data['photo'] = '/assets/images/default-pet.png';

    validation_assert_same(
        'Загрузите фотографию питомца',
        address_tag_validation_errors($data, 'payload', $today)['photo'] ?? null
    );
});

validation_test('direct payload without required data is rejected with 422', function (): void {
    validation_assert_api_error(422, function (): void {
        require_valid_address_tag_payload([]);
    });
});

validation_test('sanitizes and maps all required address tag fields', function (): void {
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'address-tag-test-request-0001';
    $order = sanitize_order_payload(valid_order_payload());

    validation_assert_same('medium', $order['size_key']);
    validation_assert_same('Чиж', $order['pet_name']);
    validation_assert_same('15.06.2020', $order['pet_birthday']);
    validation_assert_same('Пудель', $order['pet_breed']);
    validation_assert_same('г. Москва', $order['pet_address']);
    validation_assert_same('+7 (999) 123-45-67', $order['pet_phone']);
    validation_assert_same('ivan@example.com', $order['customer_email']);
    validation_assert_same('200.00', $order['delivery_price']);
    validation_assert_same('1499.00', $order['amount']);
    validation_assert_same(null, $order['pet_photo_path']);
    validation_assert_same('png', $order['_photo']['extension']);

    unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
});

validation_test('adds the fixed delivery price to every product size', function (): void {
    validation_assert_same('1299.00', order_amount_with_delivery('1099.00'));
    validation_assert_same('1499.00', order_amount_with_delivery('1299.00'));
    validation_assert_same('1599.00', order_amount_with_delivery('1399.00'));
});

validation_test('rejects invalid customer email', function (): void {
    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'address-tag-test-invalid-email';
    $payload = valid_order_payload();
    $payload['customer']['email'] = 'invalid-email';

    try {
        validation_assert_api_error(422, function () use ($payload): void {
            sanitize_order_payload($payload);
        });
    } finally {
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    }
});

validation_test('order notification contains customer email', function (): void {
    $plainEmail = build_order_email_plain([
        'order_uid' => 'test-order',
        'customer_email' => 'ivan@example.com',
    ]);
    $htmlEmail = build_order_email_html([
        'order_uid' => 'test-order',
        'customer_email' => 'ivan@example.com',
    ]);

    validation_assert_same(true, str_contains($plainEmail, 'Электронная почта: ivan@example.com'));
    validation_assert_same(true, str_contains($htmlEmail, 'ivan@example.com'));
});

validation_test('unique checkout request prevents duplicate orders', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_uid TEXT NOT NULL UNIQUE,
            checkout_request_id TEXT NOT NULL UNIQUE,
            payment_status TEXT NOT NULL,
            payment_provider TEXT,
            robokassa_inv_id INTEGER,
            size_key TEXT,
            size_title TEXT,
            size_value TEXT,
            size_price TEXT,
            pet_name TEXT,
            pet_birthday TEXT,
            pet_breed TEXT,
            pet_address TEXT,
            pet_phone TEXT,
            pet_photo_path TEXT,
            customer_name TEXT,
            customer_address TEXT,
            customer_email TEXT,
            delivery_type TEXT,
            delivery_service TEXT,
            pickup_address TEXT,
            delivery_price TEXT,
            amount TEXT,
            raw_payload TEXT,
            updated_at TEXT
        )'
    );

    $_SERVER['HTTP_IDEMPOTENCY_KEY'] = 'address-tag-test-request-duplicate';
    $firstOrder = sanitize_order_payload(valid_order_payload());
    $firstOrder['payment_provider'] = 'robokassa';
    $firstOrder['pet_photo_path'] = save_order_photo($firstOrder['order_uid'], $firstOrder['_photo']);

    try {
        $firstOrderId = insert_order($pdo, $firstOrder);
        assign_robokassa_invoice($pdo, $firstOrderId);
        $storedOrder = find_order_by_id($pdo, $firstOrderId);

        require_valid_stored_address_tag($storedOrder);

        $paymentUrl = robokassa_build_payment_url([
            'robokassa' => [
                'merchant_login' => 'Petfolio.ru',
                'password1' => 'prod-password-1',
                'password2' => 'prod-password-2',
                'test_password1' => 'test-password-1',
                'test_password2' => 'test-password-2',
                'test' => true,
                'hash_algorithm' => 'md5',
                'receipt' => ['enabled' => false],
            ],
        ], $storedOrder, $firstOrderId);

        validation_assert_same(true, str_contains($paymentUrl, 'InvId=' . $firstOrderId));

        $secondOrder = sanitize_order_payload(valid_order_payload());
        $secondOrder['payment_provider'] = 'robokassa';
        $secondOrder['pet_photo_path'] = 'uploads/order-photos/' . $secondOrder['order_uid'] . '.png';

        try {
            insert_order($pdo, $secondOrder);
            throw new RuntimeException('Duplicate checkout request was inserted.');
        } catch (PDOException $error) {
            validation_assert_same('23000', (string) $error->getCode());
        }

        validation_assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        validation_assert_same(
            $firstOrderId,
            (int) find_order_by_checkout_request_id(
                $pdo,
                'address-tag-test-request-duplicate'
            )['id']
        );
    } finally {
        delete_order_photo($firstOrder['pet_photo_path']);
        unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    }
});

echo "Server address tag validation tests passed: {$passed}" . PHP_EOL;
