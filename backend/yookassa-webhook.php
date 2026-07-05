<?php

declare(strict_types=1);

use YooKassa\Client;

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order-data.php';
require_once __DIR__ . '/send-order-email.php';

function object_value($object, string $getter, string $property)
{
    if (is_object($object) && method_exists($object, $getter)) {
        return $object->{$getter}();
    }

    if (is_array($object) && array_key_exists($property, $object)) {
        return $object[$property];
    }

    return null;
}

function metadata_to_array($metadata): array
{
    if (is_array($metadata)) {
        return $metadata;
    }

    if (is_object($metadata) && method_exists($metadata, 'toArray')) {
        return $metadata->toArray();
    }

    $decoded = json_decode(json_encode($metadata), true);

    return is_array($decoded) ? $decoded : [];
}

try {
    basic_rate_limit('yookassa-webhook', 120, 60);
    $payload = require_json_request();

    if (($payload['event'] ?? '') !== 'payment.succeeded') {
        json_response(['ok' => true]);
    }

    $paymentId = clean_text($payload['object']['id'] ?? '', 128);

    if ($paymentId === '') {
        json_response(['message' => 'Payment id is missing.'], 400);
    }

    $config = require __DIR__ . '/config.php';

    if (!class_exists(Client::class)) {
        json_response(['message' => 'YooKassa SDK is not installed.'], 500);
    }

    if (empty($config['yookassa']['shop_id']) || empty($config['yookassa']['secret_key'])) {
        json_response(['message' => 'YooKassa is not configured.'], 500);
    }

    $client = new Client();
    $client->setAuth($config['yookassa']['shop_id'], $config['yookassa']['secret_key']);
    $payment = $client->getPaymentInfo($paymentId);

    $status = (string) object_value($payment, 'getStatus', 'status');
    $paidValue = object_value($payment, 'getPaid', 'paid');

    if ($paidValue === null && is_object($payment) && method_exists($payment, 'isPaid')) {
        $paidValue = $payment->isPaid();
    }

    $paid = (bool) $paidValue;
    $metadata = metadata_to_array(object_value($payment, 'getMetadata', 'metadata'));
    $orderUid = clean_text($metadata['order_uid'] ?? '', 64);

    if ($status !== 'succeeded' || !$paid || $orderUid === '') {
        json_response(['ok' => true]);
    }

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    try {
        $order = find_order_by_uid($pdo, $orderUid, true);

        if (!$order) {
            $pdo->rollBack();
            json_response(['message' => 'Order not found.'], 404);
        }

        if ((int) $order['email_sent'] === 1) {
            $pdo->commit();
            json_response(['ok' => true]);
        }

        mark_order_paid($pdo, $orderUid, $paymentId);
        $order = find_order_by_uid($pdo, $orderUid, true) ?: $order;
        send_order_email($order);
        mark_order_email_sent($pdo, $orderUid);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    json_response(['ok' => true]);
} catch (Throwable $error) {
    handle_endpoint_error($error);
}
