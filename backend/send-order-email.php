<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function order_field(array $order, string $key): string
{
    $value = trim((string) ($order[$key] ?? ''));

    return $value !== '' ? $value : 'Не указано';
}

function delivery_type_label(string $type): string
{
    return $type === 'avito' ? 'Заказ через Авито' : 'Обычная доставка';
}

function build_order_email_plain(array $order): string
{
    return implode("\n", [
        'Новый оплаченный заказ PETLIO #' . order_field($order, 'order_uid'),
        '',
        'Ваш адресник',
        'Размер: ' . order_field($order, 'size_title') . ', ' . order_field($order, 'size_value'),
        'Цена: ' . order_field($order, 'size_price'),
        'Имя питомца: ' . order_field($order, 'pet_name'),
        'Дата рождения: ' . order_field($order, 'pet_birthday'),
        'Порода: ' . order_field($order, 'pet_breed'),
        'Место жительства: ' . order_field($order, 'pet_address'),
        'Телефон на адреснике: ' . order_field($order, 'pet_phone'),
        '',
        'Данные получателя',
        'ФИО: ' . order_field($order, 'customer_name'),
        'Адрес: ' . order_field($order, 'customer_address'),
        'Телефон: ' . order_field($order, 'customer_phone'),
        '',
        'Способ доставки',
        'Тип: ' . delivery_type_label(order_field($order, 'delivery_type')),
        'Служба доставки: ' . order_field($order, 'delivery_service'),
        'Пункт выдачи / адрес: ' . order_field($order, 'pickup_address'),
        '',
        'Платеж',
        'order_uid: ' . order_field($order, 'order_uid'),
        'payment_id: ' . order_field($order, 'payment_id'),
        'Сумма: ' . order_field($order, 'amount') . ' RUB',
        'Статус: ' . order_field($order, 'payment_status'),
    ]);
}

function build_order_email_html(array $order): string
{
    $rows = [
        'Ваш адресник' => [
            'Размер' => order_field($order, 'size_title') . ', ' . order_field($order, 'size_value'),
            'Цена' => order_field($order, 'size_price'),
            'Имя питомца' => order_field($order, 'pet_name'),
            'Дата рождения' => order_field($order, 'pet_birthday'),
            'Порода' => order_field($order, 'pet_breed'),
            'Место жительства' => order_field($order, 'pet_address'),
            'Телефон на адреснике' => order_field($order, 'pet_phone'),
        ],
        'Данные получателя' => [
            'ФИО' => order_field($order, 'customer_name'),
            'Адрес' => order_field($order, 'customer_address'),
            'Телефон' => order_field($order, 'customer_phone'),
        ],
        'Способ доставки' => [
            'Тип' => delivery_type_label(order_field($order, 'delivery_type')),
            'Служба доставки' => order_field($order, 'delivery_service'),
            'Пункт выдачи / адрес' => order_field($order, 'pickup_address'),
        ],
        'Платеж' => [
            'order_uid' => order_field($order, 'order_uid'),
            'payment_id' => order_field($order, 'payment_id'),
            'Сумма' => order_field($order, 'amount') . ' RUB',
            'Статус' => order_field($order, 'payment_status'),
        ],
    ];

    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1a1a1a;">';
    $html .= '<h1>Новый оплаченный заказ PETLIO #' . e(order_field($order, 'order_uid')) . '</h1>';

    foreach ($rows as $section => $items) {
        $html .= '<h2>' . e($section) . '</h2><table cellpadding="8" cellspacing="0" border="1" style="border-collapse:collapse;border-color:#ddd;">';

        foreach ($items as $label => $value) {
            $html .= '<tr><th align="left">' . e($label) . '</th><td>' . nl2br(e($value)) . '</td></tr>';
        }

        $html .= '</table>';
    }

    $html .= '</body></html>';

    return $html;
}

function send_order_email(array $order): void
{
    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('PHPMailer is not installed. Run composer install.');
    }

    $config = require __DIR__ . '/config.php';
    $smtp = $config['smtp'];

    foreach (['host', 'user', 'pass', 'from'] as $key) {
        if (empty($smtp[$key])) {
            throw new RuntimeException('SMTP is not configured.');
        }
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['user'];
        $mail->Password = $smtp['pass'];
        $mail->Port = (int) $smtp['port'];
        $mail->SMTPSecure = ((int) $smtp['port'] === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($smtp['from'], $smtp['from_name']);
        $mail->addAddress($config['order_email']);
        $mail->Subject = 'Новый оплаченный заказ PETLIO #' . order_field($order, 'order_uid');
        $mail->isHTML(true);
        $mail->Body = build_order_email_html($order);
        $mail->AltBody = build_order_email_plain($order);
        $mail->send();
    } catch (MailException $error) {
        throw new RuntimeException('Failed to send order email: ' . $error->getMessage(), 0, $error);
    }
}
