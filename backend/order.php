<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require __DIR__ . '/config.php';
$recipient = $config['order_email'] ?? 'prudneva17@mail.ru';

$rawBody = file_get_contents('php://input') ?: '';
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['message' => 'Некорректные данные заявки.'], JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanValue($value): string
{
    if ($value === null) {
        return 'Не указано';
    }

    $text = trim((string) $value);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

    return $text !== '' ? $text : 'Не указано';
}

function pick(array $data, string ...$path): string
{
    $value = $data;

    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return 'Не указано';
        }

        $value = $value[$key];
    }

    return cleanValue($value);
}

function optionalPick(array $data, string ...$path): string
{
    $value = $data;

    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return '';
        }

        $value = $value[$key];
    }

    $text = cleanValue($value);

    return $text === 'Не указано' ? '' : $text;
}

$customerName = pick($data, 'customer', 'name');
$customerAddress = pick($data, 'customer', 'address');
$customerPhone = pick($data, 'customer', 'phone');
$privacyConsent = !empty($data['consent']['privacyPolicy']) ? 'Да' : 'Нет';

if ($customerName === 'Не указано' || $customerAddress === 'Не указано' || $customerPhone === 'Не указано' || $privacyConsent !== 'Да') {
    http_response_code(422);
    echo json_encode(['message' => 'Заполните обязательные поля и подтвердите согласие с политикой.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$deliveryType = pick($data, 'delivery', 'type');
$deliveryTypeText = $deliveryType === 'avito' ? 'Заказ через Авито' : 'Обычная доставка';
$deliveryService = pick($data, 'delivery', 'service');
$pickupAddress = pick($data, 'delivery', 'pickupAddress');

$submittedAt = cleanValue($data['submittedAt'] ?? date('c'));
$subject = 'Новая заявка PETLIO';
$sizeText = implode(' ', array_filter([
    optionalPick($data, 'size', 'title'),
    optionalPick($data, 'size', 'value'),
    optionalPick($data, 'size', 'price'),
])) ?: 'Не указано';
$mailHost = preg_replace('/[^a-zA-Z0-9.-]/', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]) ?: 'localhost';

$messageLines = [
    'Новая заявка с сайта PETLIO',
    '',
    'Ваш адресник',
    'Размер: ' . $sizeText,
    'Имя питомца: ' . pick($data, 'pet', 'name'),
    'Дата рождения: ' . pick($data, 'pet', 'birthday'),
    'Порода: ' . pick($data, 'pet', 'breed'),
    'Место жительства: ' . pick($data, 'pet', 'address'),
    'Телефон на адреснике: ' . pick($data, 'pet', 'phone'),
    '',
    'Данные получателя',
    'ФИО: ' . $customerName,
    'Точный адрес и индекс: ' . $customerAddress,
    'Мобильный телефон: ' . $customerPhone,
    '',
    'Способ доставки',
    'Тип: ' . $deliveryTypeText,
    'Служба доставки: ' . $deliveryService,
    'Адрес пункта выдачи: ' . $pickupAddress,
    '',
    'Согласие с политикой конфиденциальности: ' . $privacyConsent,
    'Дата отправки: ' . $submittedAt,
];

$message = implode("\n", $messageLines);
$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'From: PETLIO <no-reply@' . $mailHost . '>',
    'Reply-To: ' . $recipient,
];

$sent = mail($recipient, $encodedSubject, $message, implode("\r\n", $headers));

if (!$sent) {
    http_response_code(500);
    echo json_encode(['message' => 'Не удалось отправить заявку. Проверьте настройки почты на хостинге.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['message' => 'Заявка отправлена.'], JSON_UNESCAPED_UNICODE);
