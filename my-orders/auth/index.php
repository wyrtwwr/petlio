<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/my-orders-auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$config = require __DIR__ . '/../../backend/config.php';
send_security_headers();
no_store_headers();
header('Referrer-Policy: no-referrer');

$email = null;

try {
    basic_rate_limit('magic-link-auth', 20, 300);
    $email = consume_magic_link_token(
        getDatabaseConnection(),
        $_GET['token'] ?? null
    );
} catch (Throwable $error) {
    error_log('Magic link authentication failed: ' . get_class($error));
}

if ($email !== null) {
    my_orders_authenticate_session($email, $config);
    header('Location: /my-orders/', true, 303);
    exit;
}

http_response_code(400);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <title>Ссылка недействительна | PETLIO</title>
  <link rel="stylesheet" href="../../css/order.css">
  <link rel="stylesheet" href="../../css/my-orders.css">
</head>
<body>
  <main class="order-page my-orders-page">
    <section class="order-card my-orders-invalid-link">
      <h1>Ссылка недействительна</h1>
      <p>Срок действия ссылки истёк или она уже была использована.</p>
      <a class="order-submit" href="/my-orders/"><span>Запросить новую ссылку</span></a>
    </section>
  </main>
</body>
</html>
