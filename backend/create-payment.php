<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order-data.php';
require_once __DIR__ . '/robokassa.php';

function require_payable_order(array $order): void
{
    require_valid_stored_address_tag($order);

    if (($order['payment_provider'] ?? '') !== 'robokassa') {
        throw new ApiRequestException('Для заказа выбран неподдерживаемый способ оплаты.', 409);
    }

    if (!order_status_accepts_payment($order['payment_status'] ?? '')) {
        throw new ApiRequestException('Этот заказ уже был обработан.', 409);
    }

    if ((int) ($order['robokassa_inv_id'] ?? 0) < 1) {
        throw new ApiRequestException('Для заказа не сформирован номер платежа.', 422);
    }
}

function send_payment_response(array $config, array $order, bool $reused): void
{
    require_payable_order($order);

    $orderId = (int) $order['id'];
    $paymentUrl = robokassa_build_payment_url($config, $order, $orderId);

    json_response([
        'success' => true,
        'payment_url' => $paymentUrl,
        'confirmation_url' => $paymentUrl,
        'order_uid' => (string) $order['order_uid'],
        'public_number' => (string) ($order['public_number'] ?? ''),
        'order_id' => $orderId,
        'idempotent_reuse' => $reused,
    ]);
}

try {
    basic_rate_limit('create-payment', 10, 60);
    $payload = require_json_request();
    $order = sanitize_order_payload($payload);
    $config = require __DIR__ . '/config.php';

    robokassa_assert_configured($config);
    $order['payment_provider'] = 'robokassa';

    if (robokassa_is_test($config)) {
        $order['amount'] = '1.00';
    }

    $pdo = getDatabaseConnection();
    $existingOrder = find_order_by_checkout_request_id($pdo, $order['checkout_request_id']);

    if ($existingOrder !== null) {
        // Re-read and validate the stored order immediately before rebuilding its payment link.
        send_payment_response($config, $existingOrder, true);
    }

    $savedPhotoPath = null;
    $pdo->beginTransaction();

    try {
        $order['pet_photo_path'] = save_order_photo($order['order_uid'], $order['_photo']);
        $savedPhotoPath = $order['pet_photo_path'];
        $orderId = insert_order($pdo, $order);

        if ($orderId < 1) {
            throw new RuntimeException('Failed to allocate a numeric order id.');
        }

        assign_order_public_number($pdo, $orderId);
        assign_robokassa_invoice($pdo, $orderId);

        // Payment data must be based only on a freshly loaded, locked database row.
        $storedOrder = find_order_by_id($pdo, $orderId, true);

        if ($storedOrder === null) {
            throw new RuntimeException('Newly created order could not be reloaded.');
        }

        require_payable_order($storedOrder);
        $paymentUrl = robokassa_build_payment_url($config, $storedOrder, $orderId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        delete_order_photo($savedPhotoPath);

        if (
            $error instanceof PDOException
            && in_array((string) $error->getCode(), ['23000', '40001'], true)
        ) {
            $existingOrder = find_order_by_checkout_request_id($pdo, $order['checkout_request_id']);

            if ($existingOrder !== null) {
                send_payment_response($config, $existingOrder, true);
            }
        }

        throw $error;
    }

    json_response([
        'success' => true,
        'payment_url' => $paymentUrl,
        'confirmation_url' => $paymentUrl,
        'order_uid' => (string) $storedOrder['order_uid'],
        'public_number' => (string) $storedOrder['public_number'],
        'order_id' => $orderId,
        'idempotent_reuse' => false,
    ]);
} catch (Throwable $error) {
    handle_endpoint_error($error);
}
