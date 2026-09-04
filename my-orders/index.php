<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/my-orders-auth.php';

$config = require __DIR__ . '/../backend/config.php';
my_orders_start_session($config);
send_security_headers();
header('Referrer-Policy: no-referrer');

$neutralMessage = null;
$pageError = null;
$supportEmail = trim((string) ($config['support_email'] ?? 'Ppetfolio@mail.ru'));
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

if ($requestMethod === 'POST') {
    if (!my_orders_csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $pageError = 'Сессия формы устарела. Обновите страницу и попробуйте ещё раз.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'logout') {
            my_orders_logout();
            header('Location: /my-orders/', true, 303);
            exit;
        }

        if ($action === 'request_link') {
            $neutralMessage = MY_ORDERS_NEUTRAL_MESSAGE;
            $allowed = rate_limit_allows(
                'magic-link-request',
                (int) $config['magic_link']['request_limit'],
                (int) $config['magic_link']['request_window_seconds']
            );

            if ($allowed) {
                try {
                    request_my_orders_magic_link(
                        getDatabaseConnection(),
                        normalize_my_orders_email($_POST['email'] ?? ''),
                        $config
                    );
                } catch (Throwable $error) {
                    // Keep the response neutral and never log an email or token.
                    error_log('Magic link request failed: ' . get_class($error));
                }
            }
        }
    }
}

$authenticatedEmail = my_orders_authenticated_email();
$orders = [];

if ($authenticatedEmail !== null) {
    try {
        $orders = find_orders_by_customer_email(getDatabaseConnection(), $authenticatedEmail);
    } catch (Throwable $error) {
        error_log('My orders list failed: ' . get_class($error));
        $pageError = 'Не удалось загрузить заказы. Попробуйте обновить страницу позже.';
    }
}

$csrfToken = my_orders_csrf_token();

function my_orders_h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function my_orders_date(mixed $value): string
{
    $normalized = trim((string) ($value ?? ''));

    if ($normalized === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($normalized))->format('d.m.Y H:i');
    } catch (Throwable $error) {
        return '—';
    }
}

function my_orders_money(mixed $value): string
{
    $original = trim((string) ($value ?? ''));
    $normalized = preg_replace('/[^\d,.\-]/u', '', $original) ?? '';
    $normalized = str_replace(',', '.', $normalized);

    if ($normalized === '' || !is_numeric($normalized)) {
        return $original !== '' ? $original : '—';
    }

    $amount = (float) $normalized;
    $decimals = abs($amount - round($amount)) < 0.00001 ? 0 : 2;

    return number_format($amount, $decimals, ',', ' ') . ' ₽';
}

function my_orders_public_number(array $order): string
{
    $publicNumber = trim((string) ($order['public_number'] ?? ''));

    if ($publicNumber !== '') {
        return $publicNumber;
    }

    return 'PET-' . strtoupper(substr((string) ($order['order_uid'] ?? ''), 0, 8));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <title>Мои заказы | PETLIO</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Neucha&family=PT+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/order.css">
  <link rel="stylesheet" href="../css/my-orders.css">
  <link rel="stylesheet" href="../css/site-header.css">
  <link rel="stylesheet" href="../css/site-footer.css">
</head>
<body class="<?= $authenticatedEmail === null ? 'my-orders-guest' : 'my-orders-authenticated' ?>">
  <header class="site-header">
    <div class="header-container">
      <a class="logo" href="../index.html" aria-label="PETLIO">
        <img src="../assets/images/logo.png" alt="PETLIO">
      </a>
      <nav class="main-nav" aria-label="Главное меню">
        <a href="../index.html#home">Главная</a>
        <a href="../index.html#sizes">Размеры</a>
        <a href="../create.html#constructor">Конструктор</a>
        <a href="/my-orders/" aria-current="page">Мои заказы</a>
        <a href="../privacy.html">Политика</a>
      </nav>
    </div>
  </header>

  <main class="order-page my-orders-page">
    <section class="order-hero my-orders-hero" aria-labelledby="my-orders-title">
      <div>
        <?php if ($authenticatedEmail !== null): ?>
          <p class="order-eyebrow">Без регистрации и пароля</p>
        <?php endif; ?>
        <h1 id="my-orders-title">Мои заказы</h1>
        <?php if ($authenticatedEmail !== null): ?>
          <p class="order-copy">Доступ защищён одноразовой ссылкой, которую мы отправляем на email из заказа.</p>
        <?php endif; ?>
      </div>
      <?php if ($authenticatedEmail !== null): ?>
        <form method="post" action="/my-orders/" class="my-orders-logout">
          <input type="hidden" name="csrf_token" value="<?= my_orders_h($csrfToken) ?>">
          <input type="hidden" name="action" value="logout">
          <button class="order-edit" type="submit">Выйти</button>
        </form>
      <?php endif; ?>
    </section>

    <?php if ($pageError !== null): ?>
      <p class="order-notice order-notice-error" role="alert"><?= my_orders_h($pageError) ?></p>
    <?php endif; ?>

    <?php if ($authenticatedEmail === null): ?>
      <section class="order-card my-orders-login" aria-labelledby="magic-link-title">
        <h2 id="magic-link-title">Получить ссылку для входа</h2>

        <?php if ($neutralMessage !== null): ?>
          <p class="order-notice my-orders-neutral" role="status"><?= my_orders_h($neutralMessage) ?></p>
        <?php endif; ?>

        <form method="post" action="/my-orders/" class="my-orders-login-form">
          <input type="hidden" name="csrf_token" value="<?= my_orders_h($csrfToken) ?>">
          <input type="hidden" name="action" value="request_link">
          <label class="order-field">
            <span class="my-orders-visually-hidden">Электронная почта</span>
            <input name="email" type="email" maxlength="254" autocomplete="email" placeholder="E-MAIL" required>
            <small>Укажите адрес электронной почты, использованный при оформлении заказа.</small>
          </label>
          <button class="order-submit" type="submit">
            <span>Отправить ссылку</span>
            <img src="../assets/icons/paw.svg" alt="">
          </button>
        </form>
      </section>
      <p class="my-orders-explanation">
        Доступ к информации о заказах предоставляется без регистрации и использования пароля. Для обеспечения
        безопасности вход осуществляется по защищённой одноразовой ссылке, отправляемой на адрес электронной
        почты, указанный при оформлении заказа.
      </p>
    <?php else: ?>
      <section class="my-orders-list" aria-label="Список заказов">
        <?php if ($orders === [] && $pageError === null): ?>
          <div class="order-card my-orders-empty">
            <h2>Заказов пока нет</h2>
            <p>Для этого email не найдено заказов.</p>
          </div>
        <?php endif; ?>

        <?php foreach ($orders as $order): ?>
          <?php
            $status = trim((string) ($order['payment_status'] ?? 'pending_payment'));
            $statusClass = order_public_status_key($status);
            $orderUid = (string) ($order['order_uid'] ?? '');
            $hasOrderPhoto = resolve_order_photo_absolute_path($order['pet_photo_path'] ?? null) !== null;
          ?>
          <article class="order-card my-order-card">
            <header class="my-order-card__header">
              <div>
                <p class="my-order-card__date"><?= my_orders_h(my_orders_date($order['created_at'] ?? null)) ?></p>
                <h2>Заказ №<?= my_orders_h(my_orders_public_number($order)) ?></h2>
              </div>
              <span class="order-status order-status--<?= my_orders_h($statusClass) ?>">
                <?= my_orders_h(order_status_label($status)) ?>
              </span>
            </header>

            <div class="my-order-card__body">
              <div class="my-order-photo">
                <?php if ($hasOrderPhoto): ?>
                  <img
                    src="/my-orders/photo.php?order=<?= rawurlencode($orderUid) ?>"
                    alt="Фото питомца для заказа <?= my_orders_h(my_orders_public_number($order)) ?>"
                    loading="lazy"
                  >
                <?php else: ?>
                  <span class="my-order-photo__empty">Фото недоступно</span>
                <?php endif; ?>
              </div>

              <dl class="my-order-details">
                <div>
                  <dt>Товар</dt>
                  <dd>Адресник для <?= my_orders_h($order['pet_name'] ?? 'питомца') ?></dd>
                </div>
                <div>
                  <dt>Размер</dt>
                  <dd><?= my_orders_h(trim((string) ($order['size_title'] ?? '')) . ', ' . trim((string) ($order['size_value'] ?? ''))) ?></dd>
                </div>
                <?php if (!empty($order['size_price'])): ?>
                  <div>
                    <dt>Стоимость адресника</dt>
                    <dd><?= my_orders_h(my_orders_money($order['size_price'])) ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($order['delivery_price'])): ?>
                  <div>
                    <dt>Стоимость доставки</dt>
                    <dd><?= my_orders_h(my_orders_money($order['delivery_price'])) ?></dd>
                  </div>
                <?php endif; ?>
                <div>
                  <dt>Итого</dt>
                  <dd><?= my_orders_h(my_orders_money($order['amount'] ?? '')) ?></dd>
                </div>
                <div>
                  <dt>Дата оплаты</dt>
                  <dd><?= my_orders_h(my_orders_date($order['paid_at'] ?? null)) ?></dd>
                </div>
                <div>
                  <dt>Доставка</dt>
                  <dd>
                    <?= my_orders_h(delivery_type_label((string) ($order['delivery_type'] ?? ''))) ?>
                    <?php if (!empty($order['delivery_service'])): ?>
                      — <?= my_orders_h($order['delivery_service']) ?>
                    <?php endif; ?>
                  </dd>
                </div>
                <?php if (!empty($order['pickup_address'])): ?>
                  <div>
                    <dt>Адрес / пункт выдачи</dt>
                    <dd><?= my_orders_h($order['pickup_address']) ?></dd>
                  </div>
                <?php endif; ?>
              </dl>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <aside class="my-orders-support" aria-label="Поддержка">
      Если при входе или просмотре заказов возникли сложности, напишите нам на
      <a href="mailto:<?= my_orders_h($supportEmail) ?>"><?= my_orders_h($supportEmail) ?></a>
      — мы обязательно поможем разобраться.
    </aside>

    <?php if ($authenticatedEmail !== null): ?>
    <section class="order-card my-orders-help" aria-labelledby="my-orders-help-title">
      <h2 id="my-orders-help-title">Отслеживание и помощь</h2>
      <p>
        После передачи заказа в доставку статус отправления можно отслеживать
        на сайте выбранной службы доставки, если она предоставила трек-номер.
      </p>
    </section>
    <?php endif; ?>
  </main>

  <footer class="footer">
    <div class="footer__box">
      <img src="../assets/images/footer_dog.png" alt="Собака" class="footer__dog">

      <div class="footer__cta">
        <h2>
          ПОДАРИ СВОЕМУ ПИТОМЦУ<br>
          ЗАЩИТУ УЖЕ СЕГОДНЯ
          <svg class="footer__heart" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 20.4 4.7 13.7a4.9 4.9 0 0 1 6.92-6.92L12 7.15l.38-.37a4.9 4.9 0 0 1 6.92 6.92Z"></path>
          </svg>
        </h2>

        <div class="footer__action">
          <a class="footer__button" href="../create.html">
            <span>Создать адресник</span>
            <img src="../assets/icons/paw.svg" alt="">
          </a>
          <img class="footer__arrow" src="../assets/images/arrow1.png" alt="">
        </div>
      </div>

      <div class="footer__features">
        <div class="footer__feature">
          <span class="footer__icon"><img src="../assets/icons/f1.png" alt=""></span>
          <p>Доставка<br>по всей России</p>
        </div>
        <div class="footer__feature">
          <span class="footer__icon"><img src="../assets/icons/f2.png" alt=""></span>
          <p>Индивидуальный<br>дизайн</p>
        </div>
        <div class="footer__feature">
          <span class="footer__icon"><img src="../assets/icons/f3.png" alt=""></span>
          <p>Гарантия<br>качества</p>
        </div>
      </div>
    </div>

    <div class="footer__copyright">
      <span>© 2026 Petfolio. Все права защищены.</span>
      <a href="mailto:<?= my_orders_h($supportEmail) ?>">Обратная связь: <?= my_orders_h($supportEmail) ?></a>
      <a href="../privacy.html">Политика конфиденциальности</a>
      <a href="../assets/oferta_166112894573%202.docx" download>Оферта</a>
      <img src="../assets/icons/paw.svg" alt="">
    </div>
  </footer>
</body>
</html>
