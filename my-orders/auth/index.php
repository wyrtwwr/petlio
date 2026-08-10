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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Neucha&family=PT+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../../css/order.css">
  <link rel="stylesheet" href="../../css/my-orders.css">
  <link rel="stylesheet" href="../../css/site-header.css">
</head>
<body>
  <header class="site-header">
    <div class="header-container">
      <a class="logo" href="../../index.html" aria-label="PETLIO">
        <img src="../../assets/images/logo.png" alt="PETLIO">
      </a>
      <nav class="main-nav" aria-label="Главное меню">
        <a href="../../index.html#home">Главная</a>
        <a href="../../index.html#sizes">Размеры</a>
        <a href="../../create.html#constructor">Конструктор</a>
        <a href="../../create.html#constructor">Заказать</a>
        <a href="/my-orders/" aria-current="page">Мои заказы</a>
        <a href="../../privacy.html">Политика</a>
      </nav>
    </div>
  </header>
  <main class="order-page my-orders-page">
    <section class="order-card my-orders-invalid-link">
      <h1>Ссылка недействительна</h1>
      <p>Срок действия ссылки истёк или она уже была использована.</p>
      <a class="order-submit" href="/my-orders/"><span>Запросить новую ссылку</span></a>
    </section>
  </main>
</body>
</html>
