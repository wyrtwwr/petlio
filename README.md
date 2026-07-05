# Address Shop

Стартовая структура проекта:

```text
address-shop/
├── index.html
├── css/
│   ├── style.css
│   ├── animations.css
│   └── responsive.css
├── js/
│   ├── main.js
│   ├── animation.js
│   └── slider.js
├── assets/
│   ├── images/
│   │   ├── product-1.webp
│   │   ├── product-2.webp
│   │   └── logo.svg
│   ├── icons/
│   └── fonts/
└── backend/
    ├── order.php
    ├── db.php
    └── config.php
```

## Backend setup

Backend оформляет заказ через YooKassa и отправляет письмо владельцу сайта только после подтвержденной оплаты `payment.succeeded`.

1. Установите PHP-зависимости:

```bash
composer install
```

2. Создайте `.env` из примера:

```bash
cp .env.example .env
```

3. Заполните `.env`:

- `APP_URL` - публичный HTTPS-домен сайта.
- `DB_*` - доступы к MySQL/MariaDB.
- `YOOKASSA_SHOP_ID` и `YOOKASSA_SECRET_KEY` - ключи YooKassa.
- `ORDER_EMAIL` - почта, куда приходят оплаченные заказы.
- `SMTP_*` - SMTP для отправки писем через PHPMailer.

Реальные ключи, пароли и `.env` нельзя коммитить в репозиторий.

4. Импортируйте схему базы:

```bash
mysql -u petlio_user -p petlio < schema.sql
```

5. В кабинете YooKassa настройте webhook:

```text
https://domain.ru/backend/yookassa-webhook.php
```

Событие: `payment.succeeded`.

6. Проверьте сценарий:

- пользователь оформляет заказ;
- backend создает запись `pending`;
- backend создает платеж YooKassa;
- пользователь уходит на страницу оплаты;
- YooKassa вызывает webhook после успешной оплаты;
- backend перепроверяет платеж через API YooKassa;
- заказ становится `paid`;
- письмо с данными заказа отправляется один раз.

Старый endpoint `backend/order.php` отключен, чтобы заказ нельзя было отправить на почту без оплаты.
