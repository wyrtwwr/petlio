# PETLIO

Сайт оформляет заказ на адресник и отправляет владельцу письмо только после серверного подтверждения оплаты от Robokassa.

## Быстрый запуск

1. Установить зависимости:

```bash
composer install
```

2. Создать `.env` из примера и заполнить реальные значения:

```bash
cp .env.example .env
```

3. Для новой базы импортировать схему:

```bash
mysql -u petlio_user -p petlio < schema.sql
```

4. Для существующей базы применить миграцию:

```bash
mysql -u petlio_user -p petlio < migrations/2026_robokassa.sql
```

Миграция добавляет `payment_provider`, `robokassa_inv_id`, `pet_photo_path`, `email_sent_at`, `updated_at` и индексы. Она не удаляет старые данные.

## Переменные окружения

Обязательные значения:

```dotenv
APP_URL=https://petfolio.ru
ROBOKASSA_MERCHANT_LOGIN=Petfolio.ru
ROBOKASSA_PASSWORD1=
ROBOKASSA_PASSWORD2=
ROBOKASSA_PASSWORD3=
ROBOKASSA_TEST_PASSWORD1=
ROBOKASSA_TEST_PASSWORD2=
ROBOKASSA_TEST=true
ROBOKASSA_HASH_ALGORITHM=md5
```

`ROBOKASSA_TEST` принимает `true/false`, `1/0`, `yes/no`, `on/off`. Реальные пароли не хранить в Git.

При `ROBOKASSA_TEST=true` платежная сумма принудительно становится `1.00 RUB`, чтобы проверять интеграцию на тестовых платежах. При `ROBOKASSA_TEST=false` используются реальные цены из серверного прайса.

SMTP:

```dotenv
ORDER_EMAIL=ppetfoli@mail.ru
SMTP_HOST=smtp.example.ru
SMTP_PORT=465
SMTP_USER=orders@example.ru
SMTP_PASS=
SMTP_FROM=orders@example.ru
SMTP_FROM_NAME=PETLIO
```

## Настройки Robokassa

В кабинете Robokassa:

- `MerchantLogin`: `Petfolio.ru`
- алгоритм подписи: `MD5`
- Result URL: `https://petfolio.ru/backend/robokassa-result.php`
- метод Result URL: `POST`
- Success URL: `https://petfolio.ru/order-success.html`
- метод Success URL: `GET`
- Fail URL: `https://petfolio.ru/order.html`
- метод Fail URL: `GET`

Платежная ссылка создается на `https://auth.robokassa.ru/Merchant/Payment/Index`.

## Как работает оплата

`backend/create-payment.php` валидирует заказ, считает цену только на сервере, создает запись `pending`, использует числовой `id` заказа как `InvId` и возвращает `payment_url` плюс совместимое поле `confirmation_url`.

Все поля адресника и фото питомца обязательны. Фото сохраняется на сервере в `backend/uploads/order-photos/`, не коммитится в Git и прикладывается к письму с оплаченным заказом.

`backend/robokassa-result.php` принимает только `POST`, проверяет `OutSum`, `InvId`, `SignatureValue` по формуле `OutSum:InvId:Password2`, сверяет сумму с заказом, переводит заказ в `paid` и отвечает строго `OK{InvId}`.

Отправка письма отделена от фиксации оплаты. Если SMTP временно недоступен, оплата остается `paid`, а Result URL возвращает ошибку, чтобы Robokassa повторила уведомление и письмо ушло позже.

`order-success.html` не отмечает заказ оплаченным. Она только показывает ожидание и, если в браузере есть `order_uid`, опрашивает `backend/order-status.php`.

`order.html` показывает сообщение о неуспешной оплате после Fail URL, но не меняет статус заказа.

## Фискализация

`Receipt` по умолчанию выключен:

```dotenv
ROBOKASSA_RECEIPT_ENABLED=false
ROBOKASSA_RECEIPT_SNO=
ROBOKASSA_RECEIPT_PAYMENT_METHOD=
ROBOKASSA_RECEIPT_PAYMENT_OBJECT=
ROBOKASSA_RECEIPT_TAX=
```

Включать `ROBOKASSA_RECEIPT_ENABLED=true` нужно только после подтверждения владельцем магазина значений системы налогообложения, ставки НДС, признака способа расчета и предмета расчета в кабинете Robokassa. Код уже умеет включать `Receipt` в подпись платежа, но налоговые значения нельзя выбирать наугад.

Если товар оформляется как предоплата, проверьте в Robokassa и у бухгалтера необходимость второго чека после выполнения заказа.

## Проверки

```bash
composer validate --strict
composer test
php -l backend/robokassa.php
php -l backend/create-payment.php
php -l backend/robokassa-result.php
php -l backend/order-status.php
```

После pull на хостинге:

1. Выполнить `composer install --no-dev --optimize-autoloader`.
2. Применить миграцию, если база уже существовала.
3. Заполнить `.env` реальными Robokassa и SMTP значениями.
4. Проверить тестовый платеж с `ROBOKASSA_TEST=true`.
5. После успешного теста переключить `ROBOKASSA_TEST=false` и проверить боевой платеж на небольшой сумме.
