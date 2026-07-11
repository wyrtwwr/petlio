<?php

declare(strict_types=1);

const ROBOKASSA_PAYMENT_URL = 'https://auth.robokassa.ru/Merchant/Index.aspx';
const ROBOKASSA_ALLOWED_HASH_ALGORITHMS = ['md5'];

function robokassa_is_test(array $config): bool
{
    return (bool) ($config['robokassa']['test'] ?? false);
}

function robokassa_hash_algorithm(array $config): string
{
    $algorithm = strtolower((string) ($config['robokassa']['hash_algorithm'] ?? 'md5'));

    if (!in_array($algorithm, ROBOKASSA_ALLOWED_HASH_ALGORITHMS, true)) {
        throw new RuntimeException('Unsupported Robokassa hash algorithm.');
    }

    return $algorithm;
}

function robokassa_password1(array $config): string
{
    return robokassa_active_password($config, 1);
}

function robokassa_password2(array $config): string
{
    return robokassa_active_password($config, 2);
}

function robokassa_active_password(array $config, int $number): string
{
    if (!in_array($number, [1, 2], true)) {
        throw new InvalidArgumentException('Unsupported Robokassa password number.');
    }

    $key = robokassa_is_test($config) ? 'test_password' . $number : 'password' . $number;
    $password = (string) ($config['robokassa'][$key] ?? '');

    if ($password === '') {
        throw new RuntimeException(sprintf('Active Robokassa password #%d is not configured.', $number));
    }

    return $password;
}

function robokassa_assert_configured(array $config): void
{
    if (trim((string) ($config['robokassa']['merchant_login'] ?? '')) === '') {
        throw new RuntimeException('Robokassa merchant login is not configured.');
    }

    robokassa_hash_algorithm($config);
    robokassa_password1($config);
    robokassa_password2($config);

    $receiptConfig = $config['robokassa']['receipt'] ?? [];

    if (!empty($receiptConfig['enabled']) && trim((string) ($receiptConfig['tax'] ?? '')) === '') {
        throw new RuntimeException('ROBOKASSA_RECEIPT_TAX is required when receipt generation is enabled.');
    }
}

function robokassa_normalize_amount(int|float|string $amount): string
{
    $value = trim((string) $amount);

    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Invalid amount format.');
    }

    [$rubles, $kopecks] = array_pad(explode('.', $value, 2), 2, '');
    $rubles = ltrim($rubles, '0');
    $rubles = $rubles === '' ? '0' : $rubles;
    $kopecks = str_pad($kopecks, 2, '0');

    return $rubles . '.' . $kopecks;
}

function robokassa_amount_to_kopecks(int|float|string $amount): int
{
    [$rubles, $kopecks] = explode('.', robokassa_normalize_amount($amount), 2);

    if ((int) $rubles > intdiv(PHP_INT_MAX - (int) $kopecks, 100)) {
        throw new InvalidArgumentException('Amount is too large.');
    }

    return ((int) $rubles * 100) + (int) $kopecks;
}

function robokassa_signature(array $parts, string $algorithm = 'md5'): string
{
    $algorithm = strtolower($algorithm);

    if (!in_array($algorithm, ROBOKASSA_ALLOWED_HASH_ALGORITHMS, true)) {
        throw new InvalidArgumentException('Unsupported Robokassa hash algorithm.');
    }

    return hash($algorithm, implode(':', $parts));
}

function robokassa_signatures_match(string $provided, string $expected): bool
{
    return hash_equals(strtolower($expected), strtolower(trim($provided)));
}

function robokassa_validate_result_params(array $params, array $config): array
{
    $outSumInput = trim((string) ($params['OutSum'] ?? ''));
    $invIdInput = trim((string) ($params['InvId'] ?? ''));
    $signatureValue = trim((string) ($params['SignatureValue'] ?? ''));

    if ($outSumInput === '' || $invIdInput === '' || $signatureValue === '') {
        throw new InvalidArgumentException('Missing required parameters');
    }

    if (!preg_match('/^[1-9]\d*$/', $invIdInput)) {
        throw new InvalidArgumentException('Invalid InvId');
    }

    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $outSumInput)) {
        throw new InvalidArgumentException('Invalid OutSum');
    }

    $invId = (int) $invIdInput;

    if ($invId < 1 || (string) $invId !== $invIdInput) {
        throw new InvalidArgumentException('Invalid InvId');
    }

    $expectedSignature = robokassa_signature(
        [$outSumInput, $invIdInput, robokassa_password2($config)],
        robokassa_hash_algorithm($config)
    );

    if (!robokassa_signatures_match($signatureValue, $expectedSignature)) {
        throw new UnexpectedValueException('Invalid signature');
    }

    return [
        'out_sum' => $outSumInput,
        'inv_id_input' => $invIdInput,
        'inv_id' => $invId,
        'signature' => $signatureValue,
    ];
}

function robokassa_build_receipt(array $config, array $order): ?array
{
    $receiptConfig = $config['robokassa']['receipt'] ?? [];

    if (empty($receiptConfig['enabled'])) {
        return null;
    }

    $tax = trim((string) ($receiptConfig['tax'] ?? ''));

    if ($tax === '') {
        throw new RuntimeException('ROBOKASSA_RECEIPT_TAX is required when receipt generation is enabled.');
    }

    $item = [
        'name' => 'Адресник Petlio',
        'quantity' => 1,
        'sum' => (float) robokassa_normalize_amount($order['amount']),
        'tax' => $tax,
    ];

    foreach (['payment_method', 'payment_object'] as $key) {
        $value = trim((string) ($receiptConfig[$key] ?? ''));

        if ($value !== '') {
            $item[$key] = $value;
        }
    }

    $receipt = ['items' => [$item]];
    $sno = trim((string) ($receiptConfig['sno'] ?? ''));

    if ($sno !== '') {
        $receipt['sno'] = $sno;
    }

    return $receipt;
}

function robokassa_build_payment_url(array $config, array $order, int $invId): string
{
    if ($invId < 1) {
        throw new InvalidArgumentException('Robokassa InvId must be positive.');
    }

    robokassa_assert_configured($config);

    $merchantLogin = (string) $config['robokassa']['merchant_login'];
    $outSum = robokassa_normalize_amount($order['amount']);
    $signatureParts = [$merchantLogin, $outSum, (string) $invId];
    $params = [
        'MerchantLogin' => $merchantLogin,
        'OutSum' => $outSum,
        'InvId' => (string) $invId,
        'Description' => 'Оплата заказа Petlio №' . $invId,
        'Culture' => 'ru',
        'Encoding' => 'utf-8',
    ];

    $receipt = robokassa_build_receipt($config, $order);

    if ($receipt !== null) {
        $receiptJson = json_encode(
            $receipt,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        $encodedReceipt = urlencode($receiptJson);
        $signatureParts[] = $encodedReceipt;
        $params['Receipt'] = $encodedReceipt;
    }

    $signatureParts[] = robokassa_password1($config);
    $params['SignatureValue'] = robokassa_signature($signatureParts, robokassa_hash_algorithm($config));

    if (robokassa_is_test($config)) {
        $params['IsTest'] = '1';
    }

    return ROBOKASSA_PAYMENT_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
