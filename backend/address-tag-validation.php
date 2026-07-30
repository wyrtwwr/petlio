<?php

declare(strict_types=1);

require_once __DIR__ . '/order-photo-storage.php';

const PETLIO_ADDRESS_TAG_SIZE_KEYS = ['small', 'medium', 'large'];
const PETLIO_ADDRESS_TAG_ERROR_MESSAGES = [
    'photo' => 'Загрузите фотографию питомца',
    'size' => 'Выберите размер адресника',
    'name' => 'Укажите имя питомца',
    'birthday' => 'Укажите дату рождения',
    'birthday_invalid' => 'Укажите корректную дату рождения',
    'birthday_future' => 'Дата рождения не может быть в будущем',
    'breed' => 'Укажите породу питомца',
    'address' => 'Укажите место жительства',
    'phone' => 'Укажите телефон для адресника',
    'phone_invalid' => 'Укажите корректный телефон (не менее 10 цифр)',
];

function address_tag_normalize_text(mixed $value): string
{
    $text = trim((string) ($value ?? ''));
    $text = str_replace("\u{00A0}", ' ', $text);

    return preg_replace('/\s+/u', ' ', $text) ?? $text;
}

function address_tag_value_is_missing(mixed $value): bool
{
    $normalized = address_tag_normalize_text($value);

    return $normalized === '' || preg_match('/^не указано$/iu', $normalized) === 1;
}

function address_tag_birthday_status(mixed $value, ?DateTimeImmutable $today = null): array
{
    $normalized = address_tag_normalize_text($value);
    $date = DateTimeImmutable::createFromFormat('!d.m.Y', $normalized);
    $dateErrors = DateTimeImmutable::getLastErrors();
    $isValid = $date instanceof DateTimeImmutable
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $date->format('d.m.Y') === $normalized
        && (int) $date->format('Y') >= 1;

    if (!$isValid) {
        return ['valid' => false, 'future' => false];
    }

    $currentDate = $today ?? new DateTimeImmutable('today');

    return [
        'valid' => true,
        'future' => $date > $currentDate,
    ];
}

function address_tag_phone_is_valid(mixed $value): bool
{
    $normalized = address_tag_normalize_text($value);

    if (address_tag_value_is_missing($normalized) || preg_match('/^[0-9+\s(),-]+$/u', $normalized) !== 1) {
        return false;
    }

    foreach (explode(',', $normalized) as $phone) {
        $digits = preg_replace('/\D/u', '', $phone) ?? '';
        $digitCount = strlen($digits);

        if ($digitCount < 10 || $digitCount > 15) {
            return false;
        }
    }

    return true;
}

function address_tag_payload_photo_is_valid(mixed $value): bool
{
    $photo = address_tag_normalize_text($value);

    return preg_match(
        '/^data:image\/(?:png|jpe?g);base64,[A-Za-z0-9+\/]+={0,2}$/i',
        $photo
    ) === 1;
}

function address_tag_stored_photo_is_valid(mixed $value): bool
{
    $absolutePath = resolve_order_photo_absolute_path($value);

    if ($absolutePath === null || filesize($absolutePath) < 1) {
        return false;
    }

    return !function_exists('getimagesize') || getimagesize($absolutePath) !== false;
}

function address_tag_validation_errors(
    array $data,
    string $photoSource = 'payload',
    ?DateTimeImmutable $today = null
): array {
    $errors = [];
    $photoIsValid = $photoSource === 'stored'
        ? address_tag_stored_photo_is_valid($data['photo'] ?? null)
        : address_tag_payload_photo_is_valid($data['photo'] ?? null);
    $birthdayStatus = address_tag_birthday_status($data['birthday'] ?? null, $today);

    if (!$photoIsValid) {
        $errors['photo'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['photo'];
    }

    $sizeKey = address_tag_normalize_text($data['size'] ?? null);

    if (!in_array($sizeKey, PETLIO_ADDRESS_TAG_SIZE_KEYS, true)) {
        $errors['size'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['size'];
    }

    if (address_tag_value_is_missing($data['name'] ?? null)) {
        $errors['name'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['name'];
    }

    if (address_tag_value_is_missing($data['birthday'] ?? null)) {
        $errors['birthday'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['birthday'];
    } elseif (!$birthdayStatus['valid']) {
        $errors['birthday'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['birthday_invalid'];
    } elseif ($birthdayStatus['future']) {
        $errors['birthday'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['birthday_future'];
    }

    if (address_tag_value_is_missing($data['breed'] ?? null)) {
        $errors['breed'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['breed'];
    }

    if (address_tag_value_is_missing($data['address'] ?? null)) {
        $errors['address'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['address'];
    }

    if (address_tag_value_is_missing($data['phone'] ?? null)) {
        $errors['phone'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['phone'];
    } elseif (!address_tag_phone_is_valid($data['phone'])) {
        $errors['phone'] = PETLIO_ADDRESS_TAG_ERROR_MESSAGES['phone_invalid'];
    }

    return $errors;
}

function address_tag_data_from_payload(array $payload): array
{
    return [
        'photo' => $payload['pet']['photo'] ?? null,
        'size' => $payload['size']['key'] ?? null,
        'name' => $payload['pet']['name'] ?? null,
        'birthday' => $payload['pet']['birthday'] ?? null,
        'breed' => $payload['pet']['breed'] ?? null,
        'address' => $payload['pet']['address'] ?? null,
        'phone' => $payload['pet']['phone'] ?? null,
    ];
}

function address_tag_data_from_order_row(array $order): array
{
    return [
        'photo' => $order['pet_photo_path'] ?? null,
        'size' => $order['size_key'] ?? null,
        'name' => $order['pet_name'] ?? null,
        'birthday' => $order['pet_birthday'] ?? null,
        'breed' => $order['pet_breed'] ?? null,
        'address' => $order['pet_address'] ?? null,
        'phone' => $order['pet_phone'] ?? null,
    ];
}

function require_valid_address_tag_payload(array $payload): void
{
    $errors = address_tag_validation_errors(address_tag_data_from_payload($payload));

    if ($errors !== []) {
        throw new ApiRequestException(
            'Заполните обязательные данные адресника.',
            422,
            ['errors' => $errors]
        );
    }
}

function require_valid_stored_address_tag(array $order): void
{
    $errors = address_tag_validation_errors(address_tag_data_from_order_row($order), 'stored');

    if ($errors !== []) {
        throw new ApiRequestException(
            'В заказе отсутствуют обязательные данные адресника.',
            422,
            ['errors' => $errors]
        );
    }
}
