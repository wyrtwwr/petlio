<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order-data.php';
require_once __DIR__ . '/robokassa.php';

try {
    basic_rate_limit('create-payment', 10, 60);
    $payload = require_json_request();
    $config = require __DIR__ . '/config.php';
    robokassa_assert_configured($config);

    $order = sanitize_order_payload($payload);
    $order['payment_provider'] = 'robokassa';

    if (robokassa_is_test($config)) {
        $order['amount'] = '1.00';
    }

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    try {
        $orderId = insert_order($pdo, $order);

        if ($orderId < 1) {
            throw new RuntimeException('Failed to allocate a numeric order id.');
        }

        assign_robokassa_invoice($pdo, $orderId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    $paymentUrl = robokassa_build_payment_url($config, $order, $orderId);

    json_response([
        'success' => true,
        'payment_url' => $paymentUrl,
        'confirmation_url' => $paymentUrl,
        'order_uid' => $order['order_uid'],
        'order_id' => $orderId,
    ]);
} catch (Throwable $error) {
    handle_endpoint_error($error);
}
