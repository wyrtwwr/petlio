<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

const PETLIO_SIZE_PRICES = [
    'small' => ['key' => 'small', 'title' => 'Маленький', 'value' => '3 x 2 см', 'amount' => '1099.00'],
    'medium' => ['key' => 'medium', 'title' => 'Средний', 'value' => '4 x 2,5 см', 'amount' => '1299.00'],
    'large' => ['key' => 'large', 'title' => 'Большой', 'value' => '5 x 3 см', 'amount' => '1399.00'],
];

function order_value(array $data, string $section, string $key, int $maxLength = 255): string
{
    return clean_text($data[$section][$key] ?? '', $maxLength);
}

function create_order_uid(): string
{
    return bin2hex(random_bytes(16));
}

function normalize_order_photo(array $payload): array
{
    $photo = trim((string) ($payload['pet']['photo'] ?? ''));

    if ($photo === '') {
        json_response(['message' => 'Загрузите фото питомца для адресника.'], 422);
    }

    if (!preg_match('/^data:image\/(png|jpe?g);base64,([A-Za-z0-9+\/=]+)$/', $photo, $matches)) {
        json_response(['message' => 'Загрузите фото питомца в формате JPG или PNG.'], 422);
    }

    $binary = base64_decode($matches[2], true);

    if ($binary === false || $binary === '') {
        json_response(['message' => 'Не удалось прочитать фото питомца. Загрузите файл еще раз.'], 422);
    }

    if (function_exists('getimagesizefromstring') && getimagesizefromstring($binary) === false) {
        json_response(['message' => 'Загруженный файл не похож на изображение.'], 422);
    }

    if (strlen($binary) > 10 * 1024 * 1024) {
        json_response(['message' => 'Фото питомца должно быть не больше 10 МБ.'], 422);
    }

    return [
        'extension' => str_starts_with($matches[1], 'jp') ? 'jpg' : 'png',
        'binary' => $binary,
    ];
}

function save_order_photo(string $orderUid, array $photo): string
{
    $directory = __DIR__ . '/uploads/order-photos';

    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Failed to create order photo directory.');
    }

    $fileName = $orderUid . '.' . $photo['extension'];
    $absolutePath = $directory . '/' . $fileName;

    if (file_put_contents($absolutePath, $photo['binary'], LOCK_EX) === false) {
        throw new RuntimeException('Failed to save order photo.');
    }

    return 'uploads/order-photos/' . $fileName;
}

function sanitize_order_payload(array $payload): array
{
    $sizeKey = clean_text($payload['size']['key'] ?? '', 32);

    if (!isset(PETLIO_SIZE_PRICES[$sizeKey])) {
        json_response(['message' => 'Выберите корректный размер адресника.'], 422);
    }

    $petName = order_value($payload, 'pet', 'name', 100);
    $petBirthday = order_value($payload, 'pet', 'birthday', 50);
    $petBreed = order_value($payload, 'pet', 'breed', 100);
    $petAddress = order_value($payload, 'pet', 'address', 255);
    $petPhone = order_value($payload, 'pet', 'phone', 50);
    $photo = normalize_order_photo($payload);

    if ($petName === '' || $petBirthday === '' || $petBreed === '' || $petAddress === '' || $petPhone === '') {
        json_response(['message' => 'Заполните все поля адресника и загрузите фото питомца.'], 422);
    }

    $customerName = order_value($payload, 'customer', 'name', 150);
    $customerAddress = order_value($payload, 'customer', 'address', 2000);
    $customerPhone = order_value($payload, 'customer', 'phone', 50);
    $privacyConsent = !empty($payload['consent']['privacyPolicy']);

    if ($customerName === '' || $customerAddress === '' || $customerPhone === '' || !$privacyConsent) {
        json_response(['message' => 'Заполните данные получателя и подтвердите согласие с политикой и офертой.'], 422);
    }

    $size = PETLIO_SIZE_PRICES[$sizeKey];
    $rawPayload = $payload;

    if (isset($rawPayload['pet']['photo'])) {
        $rawPayload['pet']['photo'] = '[photo omitted]';
    }

    $orderUid = create_order_uid();

    return [
        'order_uid' => $orderUid,
        'payment_status' => 'pending',
        'size_key' => $size['key'],
        'size_title' => $size['title'],
        'size_value' => $size['value'],
        'size_price' => $size['amount'] . ' ₽',
        'pet_name' => $petName,
        'pet_birthday' => $petBirthday,
        'pet_breed' => $petBreed,
        'pet_address' => $petAddress,
        'pet_phone' => $petPhone,
        'pet_photo_path' => save_order_photo($orderUid, $photo),
        'customer_name' => $customerName,
        'customer_address' => $customerAddress,
        'customer_phone' => $customerPhone,
        'delivery_type' => order_value($payload, 'delivery', 'type', 50),
        'delivery_service' => order_value($payload, 'delivery', 'service', 100),
        'pickup_address' => order_value($payload, 'delivery', 'pickupAddress', 2000),
        'amount' => $size['amount'],
        'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function insert_order(PDO $pdo, array $order): int
{
    $sql = 'INSERT INTO orders (
        order_uid, payment_status, payment_provider, size_key, size_title, size_value, size_price,
        pet_name, pet_birthday, pet_breed, pet_address, pet_phone, pet_photo_path,
        customer_name, customer_address, customer_phone,
        delivery_type, delivery_service, pickup_address,
        amount, raw_payload
    ) VALUES (
        :order_uid, :payment_status, :payment_provider, :size_key, :size_title, :size_value, :size_price,
        :pet_name, :pet_birthday, :pet_breed, :pet_address, :pet_phone, :pet_photo_path,
        :customer_name, :customer_address, :customer_phone,
        :delivery_type, :delivery_service, :pickup_address,
        :amount, :raw_payload
    )';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':order_uid' => $order['order_uid'],
        ':payment_status' => $order['payment_status'],
        ':payment_provider' => $order['payment_provider'] ?? 'robokassa',
        ':size_key' => $order['size_key'],
        ':size_title' => $order['size_title'],
        ':size_value' => $order['size_value'],
        ':size_price' => $order['size_price'],
        ':pet_name' => $order['pet_name'],
        ':pet_birthday' => $order['pet_birthday'],
        ':pet_breed' => $order['pet_breed'],
        ':pet_address' => $order['pet_address'],
        ':pet_phone' => $order['pet_phone'],
        ':pet_photo_path' => $order['pet_photo_path'],
        ':customer_name' => $order['customer_name'],
        ':customer_address' => $order['customer_address'],
        ':customer_phone' => $order['customer_phone'],
        ':delivery_type' => $order['delivery_type'],
        ':delivery_service' => $order['delivery_service'],
        ':pickup_address' => $order['pickup_address'],
        ':amount' => $order['amount'],
        ':raw_payload' => $order['raw_payload'],
    ]);

    return (int) $pdo->lastInsertId();
}

function assign_robokassa_invoice(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET robokassa_inv_id = :inv_id, updated_at = CURRENT_TIMESTAMP
         WHERE id = :order_id AND payment_provider = :payment_provider'
    );
    $stmt->execute([
        ':inv_id' => $orderId,
        ':order_id' => $orderId,
        ':payment_provider' => 'robokassa',
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Failed to assign Robokassa InvId.');
    }
}

function find_order_by_uid(PDO $pdo, string $orderUid, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM orders WHERE order_uid = :order_uid';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':order_uid' => $orderUid]);
    $order = $stmt->fetch();

    return is_array($order) ? $order : null;
}

function find_order_by_robokassa_inv_id(PDO $pdo, int $invId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM orders WHERE robokassa_inv_id = :inv_id';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':inv_id' => $invId]);
    $order = $stmt->fetch();

    return is_array($order) ? $order : null;
}

function mark_robokassa_order_paid(PDO $pdo, int $invId): void
{
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET payment_status = :status,
             payment_provider = :payment_provider,
             paid_at = COALESCE(paid_at, CURRENT_TIMESTAMP),
             updated_at = CURRENT_TIMESTAMP
         WHERE robokassa_inv_id = :inv_id AND payment_status = :pending_status'
    );
    $stmt->execute([
        ':status' => 'paid',
        ':payment_provider' => 'robokassa',
        ':inv_id' => $invId,
        ':pending_status' => 'pending',
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Failed to mark Robokassa order as paid.');
    }
}

function mark_order_email_sent(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET email_sent = 1,
             email_sent_at = COALESCE(email_sent_at, CURRENT_TIMESTAMP),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :order_id'
    );
    $stmt->execute([':order_id' => $orderId]);
}
