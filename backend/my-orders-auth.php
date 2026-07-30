<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order-data.php';
require_once __DIR__ . '/send-order-email.php';

const MY_ORDERS_NEUTRAL_MESSAGE = 'Если заказы с такой почтой существуют, мы отправили ссылку для входа';

function normalize_my_orders_email(mixed $value): string
{
    return strtolower(trim((string) ($value ?? '')));
}

function my_orders_email_is_valid(mixed $value): bool
{
    $email = normalize_my_orders_email($value);

    return $email !== ''
        && strlen($email) <= 254
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function my_orders_start_session(?array $config = null): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config ??= require __DIR__ . '/config.php';
    $secure = (bool) ($config['session']['cookie_secure'] ?? true);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('petlio_my_orders');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    no_store_headers();
}

function my_orders_csrf_token(): string
{
    if (empty($_SESSION['my_orders_csrf']) || !is_string($_SESSION['my_orders_csrf'])) {
        $_SESSION['my_orders_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['my_orders_csrf'];
}

function my_orders_csrf_is_valid(mixed $providedToken): bool
{
    $stored = $_SESSION['my_orders_csrf'] ?? '';
    $provided = (string) ($providedToken ?? '');

    return is_string($stored)
        && $stored !== ''
        && $provided !== ''
        && hash_equals($stored, $provided);
}

function my_orders_authenticated_email(?int $now = null): ?string
{
    $email = normalize_my_orders_email($_SESSION['my_orders_email'] ?? '');
    $expiresAt = (int) ($_SESSION['my_orders_expires_at'] ?? 0);
    $currentTime = $now ?? time();

    if (!my_orders_email_is_valid($email) || $expiresAt <= $currentTime) {
        unset(
            $_SESSION['my_orders_email'],
            $_SESSION['my_orders_authenticated_at'],
            $_SESSION['my_orders_expires_at']
        );

        return null;
    }

    return $email;
}

function my_orders_authenticate_session(string $email, array $config): void
{
    my_orders_start_session($config);
    session_regenerate_id(true);
    $now = time();

    $_SESSION['my_orders_email'] = normalize_my_orders_email($email);
    $_SESSION['my_orders_authenticated_at'] = $now;
    $_SESSION['my_orders_expires_at'] = $now + (int) $config['session']['lifetime_seconds'];
    $_SESSION['my_orders_csrf'] = bin2hex(random_bytes(32));
}

function my_orders_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
}

function create_magic_link_token(): array
{
    $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    return [
        'token' => $rawToken,
        'hash' => hash('sha256', $rawToken),
    ];
}

function magic_link_token_hash(mixed $token): ?string
{
    $normalized = trim((string) ($token ?? ''));

    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $normalized) !== 1) {
        return null;
    }

    return hash('sha256', $normalized);
}

function magic_link_token_is_usable(array $tokenRow, ?DateTimeImmutable $now = null): bool
{
    if (!empty($tokenRow['used_at'])) {
        return false;
    }

    try {
        $expiresAt = new DateTimeImmutable((string) ($tokenRow['expires_at'] ?? ''), new DateTimeZone('UTC'));
    } catch (Throwable $error) {
        return false;
    }

    $currentTime = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

    return $expiresAt > $currentTime && my_orders_email_is_valid($tokenRow['email'] ?? '');
}

function customer_has_orders(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM orders
         WHERE customer_email = :email
         LIMIT 1'
    );
    $stmt->execute([':email' => normalize_my_orders_email($email)]);

    return $stmt->fetchColumn() !== false;
}

function magic_link_email_request_is_allowed(
    PDO $pdo,
    string $email,
    int $limit,
    int $windowSeconds
): bool {
    $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM magic_link_tokens
         WHERE email = :email AND created_at >= :cutoff'
    );
    $stmt->execute([
        ':email' => normalize_my_orders_email($email),
        ':cutoff' => $cutoff,
    ]);

    return (int) $stmt->fetchColumn() < $limit;
}

function request_my_orders_magic_link(PDO $pdo, string $email, array $config): bool
{
    $normalizedEmail = normalize_my_orders_email($email);

    if (!my_orders_email_is_valid($normalizedEmail) || !customer_has_orders($pdo, $normalizedEmail)) {
        // Keep the cheap path from becoming a trivial account-enumeration timing oracle.
        hash('sha256', random_bytes(32));
        return false;
    }

    $magicConfig = $config['magic_link'];

    if (!magic_link_email_request_is_allowed(
        $pdo,
        $normalizedEmail,
        (int) $magicConfig['request_limit'],
        (int) $magicConfig['request_window_seconds']
    )) {
        return false;
    }

    $appKey = (string) ($config['app_key'] ?? '');

    if (strlen($appKey) < 32) {
        throw new RuntimeException('APP_KEY must contain at least 32 characters.');
    }

    $token = create_magic_link_token();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $now->modify('+' . (int) $magicConfig['ttl_seconds'] . ' seconds');
    $ipHash = hash_hmac('sha256', get_client_ip(), $appKey);

    $pdo->beginTransaction();

    try {
        $invalidate = $pdo->prepare(
            'UPDATE magic_link_tokens
             SET used_at = COALESCE(used_at, :used_at)
             WHERE email = :email AND used_at IS NULL'
        );
        $invalidate->execute([
            ':used_at' => $now->format('Y-m-d H:i:s'),
            ':email' => $normalizedEmail,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO magic_link_tokens (
                email, token_hash, expires_at, request_ip_hash, created_at
             ) VALUES (
                :email, :token_hash, :expires_at, :request_ip_hash, :created_at
             )'
        );
        $insert->execute([
            ':email' => $normalizedEmail,
            ':token_hash' => $token['hash'],
            ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            ':request_ip_hash' => $ipHash,
            ':created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    $magicLinkUrl = rtrim((string) $config['app_url'], '/')
        . '/my-orders/auth/?token=' . rawurlencode($token['token']);

    try {
        send_magic_link_email(
            $normalizedEmail,
            $magicLinkUrl,
            (int) $magicConfig['ttl_seconds']
        );
    } catch (Throwable $error) {
        $delete = $pdo->prepare('DELETE FROM magic_link_tokens WHERE token_hash = :token_hash');
        $delete->execute([':token_hash' => $token['hash']]);
        error_log('Magic link delivery failed: ' . get_class($error));

        return false;
    }

    return true;
}

function consume_magic_link_token(PDO $pdo, mixed $rawToken): ?string
{
    $tokenHash = magic_link_token_hash($rawToken);

    if ($tokenHash === null) {
        return null;
    }

    $pdo->beginTransaction();

    try {
        $lockingClause = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' FOR UPDATE'
            : '';
        $stmt = $pdo->prepare(
            'SELECT id, email, expires_at, used_at
             FROM magic_link_tokens
             WHERE token_hash = :token_hash
             LIMIT 1' . $lockingClause
        );
        $stmt->execute([':token_hash' => $tokenHash]);
        $tokenRow = $stmt->fetch();

        if (!is_array($tokenRow) || !magic_link_token_is_usable($tokenRow)) {
            $pdo->rollBack();
            return null;
        }

        $update = $pdo->prepare(
            'UPDATE magic_link_tokens
             SET used_at = :used_at
             WHERE id = :id AND used_at IS NULL'
        );
        $update->execute([
            ':used_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $tokenRow['id'],
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }

        $pdo->commit();

        return normalize_my_orders_email($tokenRow['email']);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function find_orders_by_customer_email(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare(
        'SELECT
            order_uid, public_number, payment_status,
            size_title, size_value, size_price, pet_name, pet_photo_path,
            customer_name, customer_address, customer_email,
            delivery_type, delivery_service, pickup_address, delivery_price,
            amount, created_at, paid_at
         FROM orders
         WHERE customer_email = :email
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':email' => normalize_my_orders_email($email)]);

    return $stmt->fetchAll();
}

function find_customer_order_by_uid(PDO $pdo, string $email, string $orderUid): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $orderUid) !== 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT order_uid, customer_email, pet_photo_path
         FROM orders
         WHERE order_uid = :order_uid AND customer_email = :email
         LIMIT 1'
    );
    $stmt->execute([
        ':order_uid' => $orderUid,
        ':email' => normalize_my_orders_email($email),
    ]);
    $order = $stmt->fetch();

    return is_array($order) ? $order : null;
}
