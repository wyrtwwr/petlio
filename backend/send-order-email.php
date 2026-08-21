<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/order-photo-storage.php';

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

function order_photo_absolute_path(array $order): ?string
{
    return resolve_order_photo_absolute_path($order['pet_photo_path'] ?? null);
}

function order_secondary_photo_absolute_path(array $order): ?string
{
    return resolve_order_photo_absolute_path($order['pet_secondary_photo_path'] ?? null);
}

function order_design_title(array $order): string
{
    $payload = json_decode((string) ($order['raw_payload'] ?? ''), true);
    $designKey = is_array($payload) ? trim((string) ($payload['design']['key'] ?? '')) : '';
    $designTitles = [
        'classic' => 'Паспорт питомца',
        'petfolio' => 'Пэтфолио',
        'pet-id' => 'Идентификация питомца',
    ];

    return $designTitles[$designKey] ?? $designTitles['classic'];
}

function order_pet_gender(array $order): string
{
    $payload = json_decode((string) ($order['raw_payload'] ?? ''), true);
    $gender = is_array($payload) ? trim((string) ($payload['pet']['gender'] ?? '')) : '';

    if ($gender === '') {
        return 'Не указан';
    }

    return function_exists('mb_substr') ? mb_substr($gender, 0, 20) : substr($gender, 0, 40);
}

function order_pet_design_detail(array $order, string $key, int $maxBytes = 80): string
{
    $payload = json_decode((string) ($order['raw_payload'] ?? ''), true);
    $value = is_array($payload) ? trim((string) ($payload['pet'][$key] ?? '')) : '';

    if ($value === '') {
        return 'Не указан';
    }

    return function_exists('mb_strcut') ? mb_strcut($value, 0, $maxBytes) : substr($value, 0, $maxBytes);
}

function build_order_email_plain(array $order): string
{
    return implode("\n", [
        'Новый оплаченный заказ PETLIO #' . order_field($order, 'public_number'),
        '',
        'Ваш адресник',
        'Дизайн: ' . order_design_title($order),
        'Размер: ' . order_field($order, 'size_title') . ', ' . order_field($order, 'size_value'),
        'Цена: ' . order_field($order, 'size_price'),
        'Имя питомца: ' . order_field($order, 'pet_name'),
        'Дата рождения: ' . order_field($order, 'pet_birthday'),
        'Порода: ' . order_field($order, 'pet_breed'),
        'Пол: ' . order_pet_gender($order),
        'Цвет глаз: ' . order_pet_design_detail($order, 'eyeColor'),
        'Цвет шерсти: ' . order_pet_design_detail($order, 'furColor'),
        'Место жительства: ' . order_field($order, 'pet_address'),
        'Телефон на адреснике: ' . order_field($order, 'pet_phone'),
        'Фото питомца: ' . (order_photo_absolute_path($order) ? 'во вложении' : 'не найдено'),
        'Второе фото: ' . (order_secondary_photo_absolute_path($order) ? 'во вложении' : 'не загружено'),
        '',
        'Данные получателя',
        'ФИО: ' . order_field($order, 'customer_name'),
        'Адрес: ' . order_field($order, 'customer_address'),
        'Электронная почта: ' . order_field($order, 'customer_email'),
        '',
        'Способ доставки',
        'Тип: ' . delivery_type_label(order_field($order, 'delivery_type')),
        'Служба доставки: ' . order_field($order, 'delivery_service'),
        'Пункт выдачи / адрес: ' . order_field($order, 'pickup_address'),
        'Стоимость доставки: ' . order_field($order, 'delivery_price') . ' RUB',
        '',
        'Платеж',
        'order_uid: ' . order_field($order, 'order_uid'),
        'payment_provider: ' . order_field($order, 'payment_provider'),
        'robokassa_inv_id: ' . order_field($order, 'robokassa_inv_id'),
        'payment_id: ' . order_field($order, 'payment_id'),
        'Сумма: ' . order_field($order, 'amount') . ' RUB',
        'Статус: ' . order_field($order, 'payment_status'),
    ]);
}

function build_order_email_html(array $order): string
{
    $rows = [
        'Ваш адресник' => [
            'Дизайн' => order_design_title($order),
            'Размер' => order_field($order, 'size_title') . ', ' . order_field($order, 'size_value'),
            'Цена' => order_field($order, 'size_price'),
            'Имя питомца' => order_field($order, 'pet_name'),
            'Дата рождения' => order_field($order, 'pet_birthday'),
            'Порода' => order_field($order, 'pet_breed'),
            'Пол' => order_pet_gender($order),
            'Цвет глаз' => order_pet_design_detail($order, 'eyeColor'),
            'Цвет шерсти' => order_pet_design_detail($order, 'furColor'),
            'Место жительства' => order_field($order, 'pet_address'),
            'Телефон на адреснике' => order_field($order, 'pet_phone'),
            'Фото питомца' => order_photo_absolute_path($order) ? 'во вложении' : 'не найдено',
            'Второе фото' => order_secondary_photo_absolute_path($order) ? 'во вложении' : 'не загружено',
        ],
        'Данные получателя' => [
            'ФИО' => order_field($order, 'customer_name'),
            'Адрес' => order_field($order, 'customer_address'),
            'Электронная почта' => order_field($order, 'customer_email'),
        ],
        'Способ доставки' => [
            'Тип' => delivery_type_label(order_field($order, 'delivery_type')),
            'Служба доставки' => order_field($order, 'delivery_service'),
            'Пункт выдачи / адрес' => order_field($order, 'pickup_address'),
            'Стоимость доставки' => order_field($order, 'delivery_price') . ' RUB',
        ],
        'Платеж' => [
            'order_uid' => order_field($order, 'order_uid'),
            'payment_provider' => order_field($order, 'payment_provider'),
            'robokassa_inv_id' => order_field($order, 'robokassa_inv_id'),
            'payment_id' => order_field($order, 'payment_id'),
            'Сумма' => order_field($order, 'amount') . ' RUB',
            'Статус' => order_field($order, 'payment_status'),
        ],
    ];

    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1a1a1a;">';
    $html .= '<h1>Новый оплаченный заказ PETLIO #' . e(order_field($order, 'public_number')) . '</h1>';

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

function build_customer_payment_email_plain(array $order, string $myOrdersUrl): string
{
    $lines = [
        'Заказ №' . order_field($order, 'public_number') . ' успешно оплачен',
        '',
        'Номер заказа: ' . order_field($order, 'public_number'),
        'Сумма: ' . order_field($order, 'amount') . ' RUB',
    ];
    $deliveryPrice = trim((string) ($order['delivery_price'] ?? ''));

    if ($deliveryPrice !== '') {
        $lines[] = 'В том числе доставка: ' . $deliveryPrice . ' RUB';
    }

    return implode("\n", array_merge($lines, [
        'Статус: Оплачен',
        '',
        'Посмотреть мои заказы: ' . $myOrdersUrl,
    ]));
}

function build_customer_payment_email_html(array $order, string $myOrdersUrl): string
{
    $deliveryPrice = trim((string) ($order['delivery_price'] ?? ''));
    $deliveryRow = $deliveryPrice === ''
        ? ''
        : '<p><strong>В том числе доставка:</strong> ' . e($deliveryPrice) . ' RUB</p>';

    return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1a1a1a;">'
        . '<h1>Заказ №' . e(order_field($order, 'public_number')) . ' успешно оплачен</h1>'
        . '<p><strong>Номер заказа:</strong> ' . e(order_field($order, 'public_number')) . '</p>'
        . '<p><strong>Сумма:</strong> ' . e(order_field($order, 'amount')) . ' RUB</p>'
        . $deliveryRow
        . '<p><strong>Статус:</strong> Оплачен</p>'
        . '<p><a href="' . e($myOrdersUrl) . '" style="display:inline-block;padding:14px 20px;border-radius:12px;background:#ffc533;color:#1a1a1a;text-decoration:none;font-weight:700;">Посмотреть мои заказы</a></p>'
        . '<p style="color:#666;font-size:13px;">Для доступа запросите одноразовую ссылку на email. Постоянный токен в этом письме не используется.</p>'
        . '</body></html>';
}

function build_magic_link_email_plain(string $magicLinkUrl, int $ttlMinutes): string
{
    return implode("\n", [
        'Вход в раздел «Мои заказы» PETLIO',
        '',
        'Откройте одноразовую ссылку:',
        $magicLinkUrl,
        '',
        'Ссылка действует ' . $ttlMinutes . ' минут и сработает только один раз.',
        'Если вы не запрашивали ссылку, просто проигнорируйте письмо.',
    ]);
}

function build_magic_link_email_html(string $magicLinkUrl, int $ttlMinutes): string
{
    return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1a1a1a;">'
        . '<h1>Вход в раздел «Мои заказы»</h1>'
        . '<p>Ссылка действует ' . e((string) $ttlMinutes) . ' минут и сработает только один раз.</p>'
        . '<p><a href="' . e($magicLinkUrl) . '" style="display:inline-block;padding:14px 20px;border-radius:12px;background:#ffc533;color:#1a1a1a;text-decoration:none;font-weight:700;">Открыть мои заказы</a></p>'
        . '<p style="color:#666;font-size:13px;">Если вы не запрашивали ссылку, просто проигнорируйте письмо.</p>'
        . '</body></html>';
}

function send_petlio_email(
    string $recipient,
    string $subject,
    string $htmlBody,
    string $plainBody,
    ?array $attachment = null
): void {
    if (!class_exists(PHPMailer::class)) {
        throw new RuntimeException('PHPMailer is not installed. Run composer install.');
    }

    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Email recipient is invalid.');
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
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;
        $mail->AltBody = $plainBody;

        $attachments = $attachment === null
            ? []
            : (isset($attachment['path']) ? [$attachment] : $attachment);

        foreach ($attachments as $item) {
            if (!is_array($item) || !is_file((string) ($item['path'] ?? ''))) {
                continue;
            }

            $mail->addAttachment(
                (string) $item['path'],
                (string) ($item['name'] ?? basename((string) $item['path']))
            );
        }

        $mail->send();
    } catch (MailException $error) {
        throw new RuntimeException('Failed to send order email: ' . $error->getMessage(), 0, $error);
    }
}

function send_order_email(array $order): void
{
    $config = require __DIR__ . '/config.php';
    $photoPath = order_photo_absolute_path($order);
    $secondaryPhotoPath = order_secondary_photo_absolute_path($order);
    $attachments = [];

    if ($photoPath !== null) {
        $attachments[] = [
            'path' => $photoPath,
            'name' => 'pet-photo-order-' . order_field($order, 'public_number') . '.' . pathinfo($photoPath, PATHINFO_EXTENSION),
        ];
    }

    if ($secondaryPhotoPath !== null) {
        $attachments[] = [
            'path' => $secondaryPhotoPath,
            'name' => 'pet-photo-secondary-order-' . order_field($order, 'public_number') . '.' . pathinfo($secondaryPhotoPath, PATHINFO_EXTENSION),
        ];
    }

    send_petlio_email(
        (string) $config['order_email'],
        'Новый оплаченный заказ PETLIO #' . order_field($order, 'public_number'),
        build_order_email_html($order),
        build_order_email_plain($order),
        $attachments
    );
}

function send_customer_payment_email(array $order): void
{
    $config = require __DIR__ . '/config.php';
    $myOrdersUrl = rtrim((string) $config['app_url'], '/') . '/my-orders/';

    send_petlio_email(
        order_field($order, 'customer_email'),
        'Заказ №' . order_field($order, 'public_number') . ' успешно оплачен',
        build_customer_payment_email_html($order, $myOrdersUrl),
        build_customer_payment_email_plain($order, $myOrdersUrl)
    );
}

function send_magic_link_email(string $email, string $magicLinkUrl, int $ttlSeconds): void
{
    $ttlMinutes = max(1, (int) ceil($ttlSeconds / 60));

    send_petlio_email(
        $email,
        'Вход в раздел «Мои заказы» PETLIO',
        build_magic_link_email_html($magicLinkUrl, $ttlMinutes),
        build_magic_link_email_plain($magicLinkUrl, $ttlMinutes)
    );
}
