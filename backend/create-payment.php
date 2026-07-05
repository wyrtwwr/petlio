<?php

declare(strict_types=1);

use YooKassa\Client;

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order-data.php';

try {
    basic_rate_limit('create-payment', 10, 60);
    $payload = require_json_request();
    $config = require __DIR__ . '/config.php';

    if (!class_exists(Client::class)) {
        json_response(['message' => 'YooKassa SDK не установлен. Выполните composer install.'], 500);
    }

    if (empty($config['yookassa']['shop_id']) || empty($config['yookassa']['secret_key'])) {
        json_response(['message' => 'YooKassa не настроена.'], 500);
    }

    $order = sanitize_order_payload($payload);
    $pdo = getDatabaseConnection();
    insert_order($pdo, $order);

    $client = new Client();
    $client->setAuth($config['yookassa']['shop_id'], $config['yookassa']['secret_key']);

    $returnUrl = $config['app_url'] . '/order-success.html?order=' . rawurlencode($order['order_uid']);
    $payment = $client->createPayment([
        'amount' => [
            'value' => $order['amount'],
            'currency' => 'RUB',
        ],
        'capture' => true,
        'confirmation' => [
            'type' => 'redirect',
            'return_url' => $returnUrl,
        ],
        'description' => 'Адресник PETLIO, заказ ' . $order['order_uid'],
        'metadata' => [
            'order_uid' => $order['order_uid'],
        ],
    ], $order['order_uid']);

    $paymentId = $payment->getId();
    $confirmationUrl = $payment->getConfirmation()->getConfirmationUrl();

    update_order_payment_id($pdo, $order['order_uid'], $paymentId);

    json_response([
        'confirmation_url' => $confirmationUrl,
        'order_uid' => $order['order_uid'],
    ]);
} catch (Throwable $error) {
    handle_endpoint_error($error);
}
