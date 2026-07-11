<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order-data.php';

try {
    send_security_headers();

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        json_response(['message' => 'Method not allowed'], 405);
    }

    basic_rate_limit('order-status', 30, 60);
    $orderUid = trim((string) ($_GET['order'] ?? ''));

    if (!preg_match('/^[a-f0-9]{32}$/', $orderUid)) {
        json_response(['message' => 'Invalid order token.'], 400);
    }

    $order = find_order_by_uid(getDatabaseConnection(), $orderUid);

    if ($order === null) {
        json_response(['message' => 'Order not found.'], 404);
    }

    $status = (string) ($order['payment_status'] ?? 'pending');
    json_response([
        'status' => $status === 'paid' ? 'paid' : 'pending',
    ]);
} catch (Throwable $error) {
    handle_endpoint_error($error);
}
