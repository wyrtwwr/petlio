<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/my-orders-auth.php';
require_once __DIR__ . '/../backend/order-photo-storage.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$config = require __DIR__ . '/../backend/config.php';
my_orders_start_session($config);
send_security_headers();
no_store_headers();
header('Referrer-Policy: no-referrer');

$email = my_orders_authenticated_email();
$orderUid = trim((string) ($_GET['order'] ?? ''));

if ($email === null || preg_match('/^[a-f0-9]{32}$/', $orderUid) !== 1) {
    http_response_code(404);
    exit;
}

try {
    $order = find_customer_order_by_uid(getDatabaseConnection(), $email, $orderUid);
    $absolutePath = $order === null
        ? null
        : resolve_order_photo_absolute_path($order['pet_photo_path'] ?? null);

    if ($absolutePath === null) {
        http_response_code(404);
        exit;
    }

    $imageInfo = getimagesize($absolutePath);
    $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';

    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Content-Disposition: inline; filename="pet-photo.' . ($mimeType === 'image/png' ? 'png' : 'jpg') . '"');
    readfile($absolutePath);
} catch (Throwable $error) {
    error_log('Protected order photo failed: ' . get_class($error));
    http_response_code(404);
}
