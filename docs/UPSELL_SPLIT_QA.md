# Upsell Split QA (menu + cart)

Этот документ покрывает:
- проверку `menu_upsells` (one-tap после add-to-cart),
- проверку `cart_upsells` (блок в корзине),
- проверку независимости слоёв,
- проверку frontend флагов,
- проверку DB (`combo_rules`, `menu_item_upsells`).

## 1) Ручная проверка `menu_upsells`

1. В `Настройки ресторана` включите:
- `Upsell в меню` = ON
- `Лимит` = 1
- `manual_only` = ON
2. Добавьте блюдо в корзину на `qr.php`.
3. Проверьте:
- появляется one-tap блок,
- карточек не больше лимита (1),
- при `manual_only=ON` нет мусорных рекомендаций.

## 2) Ручная проверка `cart_upsells`

1. Включите:
- `Upsell в корзине` = ON
- `sources`: manual/contextual/popular по сценарию.
2. Перейдите в корзину (`view=cart`).
3. Проверьте:
- виден блок `cart_upsells`,
- карточки релевантны,
- при выключенных источниках блок пустой.

## 3) Проверка независимости menu/cart

Сценарий A:
- menu ON, cart OFF -> виден только one-tap, cart-блока нет.

Сценарий B:
- menu OFF, cart ON -> one-tap не показывается, но cart-блок есть.

## 4) Проверка frontend флагов

На странице `qr.php` откройте DevTools Console:
- ищите лог `[upsell split init]` и `[upsell split ajax]`,
- проверяйте значения:
  - `menuUpsellGuestOn`
  - `cartUpsellGuestOn`

## 5) Проверка DB

```sql
SELECT * FROM combo_rules WHERE restaurant_id = ? ORDER BY priority DESC, id DESC;
SELECT * FROM menu_item_upsells WHERE restaurant_id = ? ORDER BY id DESC LIMIT 5;
```

## 6) Автоматизированная проверка

Используйте скрипт:

```bash
./scripts/upsell_split_smoke.sh
```

Опциональные переменные:

```bash
BASE_URL="https://test.qrrest-menu.ru" \
SUBDOMAIN="test" \
./scripts/upsell_split_smoke.sh
```

Если нужно указать env-файл явно:

```bash
ENV_FILE=".env.production" ./scripts/upsell_split_smoke.sh
```
