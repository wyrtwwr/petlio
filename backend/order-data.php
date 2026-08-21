<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/address-tag-validation.php';
require_once __DIR__ . '/order-photo-storage.php';

const PETLIO_SIZE_PRICES = [
    'small' => ['key' => 'small', 'title' => 'Маленький', 'value' => '3 x 2 см', 'amount' => '1099.00'],
    'medium' => ['key' => 'medium', 'title' => 'Средний', 'value' => '4 x 2,5 см', 'amount' => '1299.00'],
    'large' => ['key' => 'large', 'title' => 'Большой', 'value' => '5 x 3 см', 'amount' => '1399.00'],
];
const PETLIO_DELIVERY_AMOUNT = '200.00';

const PETLIO_ORDER_STATUS_LABELS = [
    'pending' => 'Ожидает оплаты',
    'pending_payment' => 'Ожидает оплаты',
    'paid' => 'Оплачен',
    // Historical internal lifecycle statuses are intentionally shown to
    // customers as one simple paid state until an admin workflow exists.
    'in_production' => 'Оплачен',
    'shipped' => 'Оплачен',
    'completed' => 'Оплачен',
    'cancelled' => 'Отменён',
];

function order_public_status_key(mixed $status): string
{
    $normalized = trim((string) $status);

    if (in_array($normalized, ['pending', 'pending_payment'], true)) {
        return 'pending_payment';
    }

    if ($normalized === 'cancelled') {
        return 'cancelled';
    }

    if (order_status_has_confirmed_payment($normalized)) {
        return 'paid';
    }

    return 'pending_payment';
}

function order_status_label(mixed $status): string
{
    $normalized = order_public_status_key($status);

    return PETLIO_ORDER_STATUS_LABELS[$normalized] ?? 'Статус уточняется';
}

function order_status_accepts_payment(mixed $status): bool
{
    return in_array(trim((string) $status), ['pending', 'pending_payment'], true);
}

function order_status_has_confirmed_payment(mixed $status): bool
{
    return in_array(
        trim((string) $status),
        ['paid', 'in_production', 'shipped', 'completed'],
        true
    );
}

function order_value(array $data, string $section, string $key, int $maxLength = 255): string
{
    return clean_text($data[$section][$key] ?? '', $maxLength);
}

function create_order_uid(): string
{
    return bin2hex(random_bytes(16));
}

function create_order_public_number(int $orderId, ?DateTimeImmutable $createdAt = null): string
{
    if ($orderId < 1) {
        throw new InvalidArgumentException('Order id must be positive.');
    }

    $date = $createdAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return sprintf('PET-%s-%06d', $date->format('Ymd'), $orderId);
}

function order_amount_with_delivery(string $productAmount): string
{
    $productKopecks = (int) round((float) $productAmount * 100);
    $deliveryKopecks = (int) round((float) PETLIO_DELIVERY_AMOUNT * 100);

    return number_format(($productKopecks + $deliveryKopecks) / 100, 2, '.', '');
}

function normalize_order_photo(array $payload): array
{
    $photo = trim((string) ($payload['pet']['photo'] ?? ''));

    if ($photo === '') {
        throw new ApiRequestException(
            'Загрузите фотографию питомца.',
            422,
            ['errors' => ['photo' => PETLIO_ADDRESS_TAG_ERROR_MESSAGES['photo']]]
        );
    }

    if (!preg_match('/^data:image\/(png|jpe?g);base64,([A-Za-z0-9+\/=]+)$/', $photo, $matches)) {
        throw new ApiRequestException(
            'Загрузите фотографию питомца в формате JPG или PNG.',
            422,
            ['errors' => ['photo' => 'Загрузите фотографию в формате JPG или PNG']]
        );
    }

    $binary = base64_decode($matches[2], true);

    if ($binary === false || $binary === '') {
        throw new ApiRequestException(
            'Не удалось прочитать фотографию питомца.',
            422,
            ['errors' => ['photo' => 'Загрузите фотографию ещё раз']]
        );
    }

    if (function_exists('getimagesizefromstring') && getimagesizefromstring($binary) === false) {
        throw new ApiRequestException(
            'Загруженный файл не является изображением.',
            422,
            ['errors' => ['photo' => 'Выберите корректное изображение']]
        );
    }

    if (strlen($binary) > 10 * 1024 * 1024) {
        throw new ApiRequestException(
            'Фотография питомца должна быть не больше 10 МБ.',
            422,
            ['errors' => ['photo' => 'Размер фотографии не должен превышать 10 МБ']]
        );
    }

    return [
        'extension' => str_starts_with($matches[1], 'jp') ? 'jpg' : 'png',
        'binary' => $binary,
    ];
}

function normalize_optional_order_photo(array $payload, string $key): ?array
{
    $photo = trim((string) ($payload['pet'][$key] ?? ''));

    if ($photo === '') {
        return null;
    }

    $normalizedPayload = $payload;
    $normalizedPayload['pet']['photo'] = $photo;

    return normalize_order_photo($normalizedPayload);
}

function sanitize_order_payload(array $payload): array
{
    require_valid_address_tag_payload($payload);

    $sizeKey = clean_text($payload['size']['key'] ?? '', 32);

    if (!isset(PETLIO_SIZE_PRICES[$sizeKey])) {
        throw new ApiRequestException(
            'Выберите корректный размер адресника.',
            422,
            ['errors' => ['size' => PETLIO_ADDRESS_TAG_ERROR_MESSAGES['size']]]
        );
    }

    $petName = order_value($payload, 'pet', 'name', 100);
    $petBirthday = order_value($payload, 'pet', 'birthday', 50);
    $petBreed = order_value($payload, 'pet', 'breed', 100);
    $petAddress = order_value($payload, 'pet', 'address', 255);
    $petPhone = order_value($payload, 'pet', 'phone', 50);
    $photo = normalize_order_photo($payload);
    $secondaryPhoto = normalize_optional_order_photo($payload, 'secondaryPhoto');

    $customerName = order_value($payload, 'customer', 'name', 150);
    $customerAddress = order_value($payload, 'customer', 'address', 2000);
    $customerEmailInput = address_tag_normalize_text($payload['customer']['email'] ?? '');
    $customerEmail = strtolower(clean_text($customerEmailInput, 254));
    $privacyConsent = !empty($payload['consent']['privacyPolicy']);

    if (
        address_tag_value_is_missing($customerName)
        || address_tag_value_is_missing($customerAddress)
        || address_tag_value_is_missing($customerEmail)
        || !$privacyConsent
    ) {
        throw new ApiRequestException(
            'Заполните данные получателя и подтвердите согласие с политикой и офертой.',
            422
        );
    }

    if (strlen($customerEmailInput) > 254 || filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new ApiRequestException(
            'Укажите корректную электронную почту получателя.',
            422,
            ['errors' => ['customerEmail' => 'Укажите корректную электронную почту']]
        );
    }

    $checkoutRequestId = clean_text(
        $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($payload['checkoutRequestId'] ?? ''),
        64
    );

    if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $checkoutRequestId) !== 1) {
        throw new ApiRequestException(
            'Не удалось идентифицировать попытку оплаты. Обновите страницу и попробуйте ещё раз.',
            422
        );
    }

    $size = PETLIO_SIZE_PRICES[$sizeKey];
    $totalAmount = order_amount_with_delivery($size['amount']);
    $rawPayload = $payload;

    if (isset($rawPayload['pet']['photo'])) {
        $rawPayload['pet']['photo'] = '[photo omitted]';
    }

    if (isset($rawPayload['pet']['secondaryPhoto'])) {
        $rawPayload['pet']['secondaryPhoto'] = '[secondary photo omitted]';
    }

    $rawPayload['pricing'] = [
        'product_amount' => $size['amount'],
        'delivery_amount' => PETLIO_DELIVERY_AMOUNT,
        'total_amount' => $totalAmount,
    ];

    $orderUid = create_order_uid();

    return [
        'order_uid' => $orderUid,
        'checkout_request_id' => $checkoutRequestId,
        'payment_status' => 'pending_payment',
        'size_key' => $size['key'],
        'size_title' => $size['title'],
        'size_value' => $size['value'],
        'size_price' => $size['amount'] . ' ₽',
        'pet_name' => $petName,
        'pet_birthday' => $petBirthday,
        'pet_breed' => $petBreed,
        'pet_address' => $petAddress,
        'pet_phone' => $petPhone,
        'pet_photo_path' => null,
        'pet_secondary_photo_path' => null,
        '_photo' => $photo,
        '_secondary_photo' => $secondaryPhoto,
        'customer_name' => $customerName,
        'customer_address' => $customerAddress,
        'customer_email' => $customerEmail,
        'delivery_type' => order_value($payload, 'delivery', 'type', 50),
        'delivery_service' => order_value($payload, 'delivery', 'service', 100),
        'pickup_address' => order_value($payload, 'delivery', 'pickupAddress', 2000),
        'delivery_price' => PETLIO_DELIVERY_AMOUNT,
        'amount' => $totalAmount,
        'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function insert_order(PDO $pdo, array $order): int
{
    $sql = 'INSERT INTO orders (
        order_uid, checkout_request_id, payment_status, payment_provider, size_key, size_title, size_value, size_price,
        pet_name, pet_birthday, pet_breed, pet_address, pet_phone, pet_photo_path, pet_secondary_photo_path,
        customer_name, customer_address, customer_email,
        delivery_type, delivery_service, pickup_address, delivery_price,
        amount, raw_payload
    ) VALUES (
        :order_uid, :checkout_request_id, :payment_status, :payment_provider, :size_key, :size_title, :size_value, :size_price,
        :pet_name, :pet_birthday, :pet_breed, :pet_address, :pet_phone, :pet_photo_path, :pet_secondary_photo_path,
        :customer_name, :customer_address, :customer_email,
        :delivery_type, :delivery_service, :pickup_address, :delivery_price,
        :amount, :raw_payload
    )';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':order_uid' => $order['order_uid'],
        ':checkout_request_id' => $order['checkout_request_id'],
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
        ':pet_secondary_photo_path' => $order['pet_secondary_photo_path'] ?? null,
        ':customer_name' => $order['customer_name'],
        ':customer_address' => $order['customer_address'],
        ':customer_email' => $order['customer_email'],
        ':delivery_type' => $order['delivery_type'],
        ':delivery_service' => $order['delivery_service'],
        ':pickup_address' => $order['pickup_address'],
        ':delivery_price' => $order['delivery_price'],
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

function assign_order_public_number(PDO $pdo, int $orderId): string
{
    $publicNumber = create_order_public_number($orderId);
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET public_number = :public_number, updated_at = CURRENT_TIMESTAMP
         WHERE id = :order_id AND public_number IS NULL'
    );
    $stmt->execute([
        ':public_number' => $publicNumber,
        ':order_id' => $orderId,
    ]);

    if ($stmt->rowCount() !== 1) {
        $order = find_order_by_id($pdo, $orderId);
        $existing = trim((string) ($order['public_number'] ?? ''));

        if ($existing === '') {
            throw new RuntimeException('Failed to assign public order number.');
        }

        return $existing;
    }

    return $publicNumber;
}

function find_order_by_id(PDO $pdo, int $orderId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM orders WHERE id = :order_id';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch();

    return is_array($order) ? $order : null;
}

function find_order_by_checkout_request_id(
    PDO $pdo,
    string $checkoutRequestId,
    bool $forUpdate = false
): ?array {
    $sql = 'SELECT * FROM orders WHERE checkout_request_id = :checkout_request_id';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':checkout_request_id' => $checkoutRequestId]);
    $order = $stmt->fetch();

    return is_array($order) ? $order : null;
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
         WHERE robokassa_inv_id = :inv_id
           AND payment_status IN (:pending_status, :legacy_pending_status)'
    );
    $stmt->execute([
        ':status' => 'paid',
        ':payment_provider' => 'robokassa',
        ':inv_id' => $invId,
        ':pending_status' => 'pending_payment',
        ':legacy_pending_status' => 'pending',
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Failed to mark Robokassa order as paid.');
    }
}

function mark_customer_payment_email_sent(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare(
        'UPDATE orders
         SET customer_email_sent_at = COALESCE(customer_email_sent_at, CURRENT_TIMESTAMP),
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :order_id'
    );
    $stmt->execute([':order_id' => $orderId]);
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
