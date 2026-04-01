# Stripe Billing Setup — QR Restaurant SaaS

## 1. Как получить ключи Stripe

1. Зарегистрируйтесь на [dashboard.stripe.com](https://dashboard.stripe.com).
2. Включите тестовый режим (Test mode) для разработки.
3. **API Keys**: Developers → API keys. Скопируйте:
   - **Secret key** (sk_test_... или sk_live_...) → `STRIPE_SECRET_KEY`
   - **Publishable key** (pk_test_... или pk_live_...) → `STRIPE_PUBLISHABLE_KEY`
4. **Products & Prices**: создайте продукты и цены (Prices) для тарифов (например Starter, Pro). Скопируйте **Price ID** (price_...) → `STRIPE_PRICE_ID_BASIC`, `STRIPE_PRICE_ID_PRO`.
5. **Webhooks**: Developers → Webhooks → Add endpoint. URL: `https://your-domain.com/stripe_webhook.php`. События: `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, `invoice.payment_failed`. Скопируйте **Signing secret** (whsec_...) → `STRIPE_WEBHOOK_SECRET`.

## 2. Env переменные

| Переменная | Обязательность | Описание |
|------------|----------------|----------|
| STRIPE_ENABLED | да (1 для включения) | 0 или 1 |
| STRIPE_SECRET_KEY | да (если Stripe включён) | sk_test_... / sk_live_... |
| STRIPE_PUBLISHABLE_KEY | нет (для будущего JS) | pk_test_... / pk_live_... |
| STRIPE_WEBHOOK_SECRET | да (для webhook) | whsec_... |
| STRIPE_PRICE_ID_BASIC | для тарифа starter/basic | price_... |
| STRIPE_PRICE_ID_PRO | для тарифа pro | price_... |
| STRIPE_SUCCESS_URL | нет (есть fallback) | Куда редирект после успешной оплаты |
| STRIPE_CANCEL_URL | нет (есть fallback) | Куда редирект при отмене |

Если переменные не заданы — Stripe отключён, работает только ручная активация тарифа (choose_plan).

## 3. Как задать Price IDs

В Stripe Dashboard создайте Product (например «Старт») и добавьте к нему Price (рекуррентный, ежемесячно). Код плана в приложении (plans.code): `starter`, `pro`. В config маппинг: `STRIPE_PRICE_ID_BASIC` → starter/basic, `STRIPE_PRICE_ID_PRO` → pro.

## 4. Как поднять локально

1. Задайте env (например в `.env` или в docker-compose):
   ```
   STRIPE_ENABLED=1
   STRIPE_SECRET_KEY=sk_test_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   STRIPE_PRICE_ID_BASIC=price_...
   STRIPE_PRICE_ID_PRO=price_...
   STRIPE_SUCCESS_URL=http://lvh.me/restaurant/dashboard.php
   STRIPE_CANCEL_URL=http://lvh.me/restaurant/activate.php
   ```
2. Запустите приложение как обычно (docker compose up).
3. На странице «Активировать подписку» появятся кнопки «Оплатить через Stripe» и «Выбрать (без оплаты)».

## 5. Как пробросить webhook локально (Stripe CLI)

Stripe не может отправить webhook на localhost. Используйте Stripe CLI:

```bash
stripe listen --forward-to http://lvh.me/stripe_webhook.php
```

В выводе будет временный **webhook signing secret** (whsec_...). Подставьте его в `STRIPE_WEBHOOK_SECRET` для локального теста.

Для теста с ngrok:

```bash
ngrok http 80
# затем в Stripe Dashboard добавьте endpoint https://xxx.ngrok.io/stripe_webhook.php
```

## 6. Пример команды stripe listen

```bash
stripe listen --forward-to http://lvh.me/stripe_webhook.php
```

После успешной оплаты в Checkout Stripe пришлёт события на этот URL.

## 7. Как проверить checkout

1. Войдите в панель ресторана (owner).
2. Откройте `/restaurant/activate.php`.
3. Нажмите «Оплатить через Stripe» у нужного тарифа.
4. Вас перенаправит на Stripe Checkout. Используйте тестовую карту: 4242 4242 4242 4242.
5. После оплаты редирект на STRIPE_SUCCESS_URL (например dashboard). Webhook обновит подписку в БД.

## 8. Как проверить invoice.paid webhook

1. После успешной оплаты в Stripe Dashboard → Developers → Webhooks → ваш endpoint → события.
2. Должно быть событие `invoice.paid` (и возможно `checkout.session.completed`, `customer.subscription.created`).
3. В приложении: таблицы `subscriptions`, `invoices`, `payments` обновляются (provider = stripe, provider_ref = stripe:sub:..., stripe:pi:...).
4. Логи: при ошибке в webhook смотрите error_log (STRIPE_WEBHOOK_ERROR, STABILITY_ERROR).
