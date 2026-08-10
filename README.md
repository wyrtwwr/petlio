# PETLIO

Сайт оформляет заказ на адресник и отправляет владельцу письмо только после серверного подтверждения оплаты от Robokassa.

## Быстрый запуск

### Docker

Для локального запуска приложения вместе с MySQL:

```bash
docker compose up -d --build
```

Сайт будет доступен по адресу `http://localhost:8081`. При первом запуске
Compose автоматически создаёт базу и импортирует `schema.sql`. Данные MySQL и
загруженные фотографии сохраняются в именованных Docker volumes.

Порт и секреты можно переопределить через `.env`; список поддерживаемых
переменных приведён в `.env.example`. Для остановки используйте
`docker compose down`.

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
mysql -u petlio_user -p petlio < migrations/2026_my_orders.sql
mysql -u petlio_user -p petlio < migrations/2026_delivery_price.sql
```

Первая миграция добавляет платёжные поля и email получателя. Вторая добавляет публичный номер заказа, отметку клиентского письма, индексы и таблицу одноразовых magic-link токенов. Она переводит старый статус `pending` в `pending_payment` и переносит email из корректного `raw_payload`, если он там есть. Третья добавляет отдельное поле стоимости доставки; старые заказы намеренно не пересчитываются. Email старых заказов не угадывается: записи без адреса остаются недоступны, пока администратор не укажет его вручную.

## Robots.txt и sitemap.xml

`robots.txt` и `sitemap.xml` лежат в публичном корне проекта. В sitemap включены только индексируемые информационные страницы: главная, конструктор, политика конфиденциальности и информация о cookie. Страницы оформления и результата оплаты, «Мои заказы», magic-link авторизация и backend URL намеренно не добавлены.

Production использует nginx. Если в активном `server {}` есть fallback вида `try_files $uri $uri/ /index.html`, наличие корневых файлов уже позволяет nginx отдать их до fallback. Чтобы одновременно гарантировать отсутствие fallback и точные `Content-Type`, подключите `deploy/nginx/seo-files.conf` внутри HTTPS-блока `server {}`:

```nginx
include /etc/nginx/snippets/petfolio-seo-files.conf;
```

Пример безопасного применения на сервере:

```bash
sudo cp deploy/nginx/seo-files.conf /etc/nginx/snippets/petfolio-seo-files.conf
sudo nginx -t
sudo systemctl reload nginx
```

Фрагмент содержит только exact-match маршруты `/robots.txt` и `/sitemap.xml`; существующие PHP и fallback-маршруты он не изменяет. Для простого добавления файлов frontend-сборка и перезапуск приложения не нужны. Reload nginx требуется только при первом подключении или изменении фрагмента.

Проверка после деплоя:

```bash
curl -I https://petfolio.ru/robots.txt
curl https://petfolio.ru/robots.txt
curl -I https://petfolio.ru/sitemap.xml
curl https://petfolio.ru/sitemap.xml
```

## Переменные окружения

Обязательные значения:

```dotenv
APP_URL=https://petfolio.ru
APP_KEY=replace-with-at-least-32-random-characters
SESSION_COOKIE_SECURE=true
MY_ORDERS_SESSION_LIFETIME=43200
MAGIC_LINK_TTL_SECONDS=1800
MAGIC_LINK_REQUEST_LIMIT=5
MAGIC_LINK_REQUEST_WINDOW_SECONDS=900
PET_PHOTO_STORAGE_PATH=/var/lib/petlio/order-photos
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

`APP_KEY` должен быть случайной строкой не короче 32 символов. Сгенерировать его можно командой `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. `SESSION_COOKIE_SECURE=true` обязателен на HTTPS; только для локальной разработки по HTTP его можно временно отключить. Каталог `PET_PHOTO_STORAGE_PATH` должен быть доступен PHP на запись и находиться вне публичного корня сайта.

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

`backend/create-payment.php` валидирует заказ, считает цену только на сервере, добавляет фиксированную доставку 200 ₽, создает запись `pending_payment`, присваивает публичный номер вида `PET-YYYYMMDD-000001`, повторно читает и проверяет её из БД, использует числовой `id` заказа как внутренний `InvId` и только после этого возвращает `payment_url` плюс совместимое поле `confirmation_url`.

Фото, явно выбранный размер, имя питомца, дата рождения, порода, место жительства и телефон на адреснике обязательны. Значение `Не указано` считается пустым. Общие правила клиента находятся в `js/address-tag-validation.js`, серверные — в `backend/address-tag-validation.php`.

Новые фото сохраняются вне публичного корня в `PET_PHOTO_STORAGE_PATH`, а в заказе хранится ссылка вида `private:<имя>`. Они выдаются только через `my-orders/photo.php` после проверки серверной сессии и совпадения email заказа. Для старых файлов в `backend/uploads/order-photos/` оставлена совместимость; Apache закрывает каталог через `.htaccess`, а в Nginx нужно запретить URL `/backend/uploads/`. `checkout_request_id` и уникальный индекс делают повтор одного запроса идемпотентным: для него не создаётся второй заказ или вторая платёжная ссылка.

В данных получателя хранится `customer_email` вместо номера телефона. Email получателя выводится в текстовой и HTML-версии уведомления о заказе, которое отправляется на `ORDER_EMAIL`.

`backend/robokassa-result.php` принимает только `POST`, проверяет `OutSum`, `InvId`, `SignatureValue` по формуле `OutSum:InvId:Password2`, сверяет сумму и обязательные данные заказа, переводит заказ в `paid` и отвечает строго `OK{InvId}`.

Отправка письма отделена от фиксации оплаты. Если SMTP временно недоступен, оплата остается `paid`, а Result URL возвращает ошибку, чтобы Robokassa повторила уведомление и письмо ушло позже.

`order-success.html` не отмечает заказ оплаченным. Она только показывает ожидание и, если в браузере есть `order_uid`, опрашивает `backend/order-status.php`.

`order.html` показывает сообщение о неуспешной оплате после Fail URL, но не меняет статус заказа.

## «Мои заказы» без пароля

Страница находится по адресу `https://petfolio.ru/my-orders/`. Гость вводит email, а сервер всегда показывает одинаковый ответ независимо от наличия заказов. Если заказы существуют, сервер создаёт криптографический одноразовый токен на 30 минут, сохраняет только его SHA-256 hash и отправляет ссылку на email. После перехода токен атомарно помечается использованным, создаётся защищённая серверная сессия с cookie `HttpOnly`, `Secure`, `SameSite=Lax`.

Пользователь видит только заказы с email текущей сессии, включая ожидающие оплаты. Доступ по числовому `id` не поддерживается. POST-действия защищены CSRF-токеном, запрос ссылки ограничен по IP и email.

После подтверждения ResultURL клиенту отправляется письмо с темой `Заказ №{номер} успешно оплачен`, суммой, статусом и кнопкой перехода в «Мои заказы». `SuccessURL` не меняет платёжный статус и не отправляет письма.

Клиенту показываются только три статуса: `pending_payment` — «Ожидает оплаты», `paid` — «Оплачен», `cancelled` — «Отменён». Исторические внутренние статусы `in_production`, `shipped` и `completed`, если они уже есть в базе, отображаются как «Оплачен». На странице также указан email поддержки и пояснение об отслеживании отправления на сайте выбранной службы доставки.

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
node tests/address-tag-validation-test.js
php -l backend/robokassa.php
php -l backend/create-payment.php
php -l backend/address-tag-validation.php
php -l backend/robokassa-result.php
php -l backend/order-status.php
php -l backend/my-orders-auth.php
php -l my-orders/index.php
php -l my-orders/auth/index.php
php -l my-orders/photo.php
```

После pull на хостинге:

1. Выполнить `composer install --no-dev --optimize-autoloader`.
2. Последовательно применить `migrations/2026_robokassa.sql`, `migrations/2026_my_orders.sql` и `migrations/2026_delivery_price.sql`, если база уже существовала.
3. Создать приватный каталог фото, дать PHP права на запись и указать его в `PET_PHOTO_STORAGE_PATH`.
4. Заполнить `.env` реальными `APP_KEY`, Robokassa и SMTP значениями.
5. В Robokassa проверить Result URL `https://petfolio.ru/backend/robokassa-result.php` (`POST`) и Success URL `https://petfolio.ru/order-success.html` (`GET`).
6. Проверить тестовый платёж с `ROBOKASSA_TEST=true`, письмо покупателю, magic-link вход, список и закрытость фото другого клиента.
7. После успешного теста переключить `ROBOKASSA_TEST=false` и проверить боевой платёж на небольшой сумме.
