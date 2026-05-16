<?php
/**
 * Demo mode: subdomain "demo" shows simulated data. No DB writes. Isolated and safe.
 * Единый сценарий: «Гастропаб Север» — реалистичное меню, CRM, допродажи, кухня.
 */

function is_demo_mode(): bool
{
    if (!function_exists('get_subdomain_slug')) {
        return false;
    }
    return get_subdomain_slug() === 'demo';
}

/** Demo restaurant record (no DB row). id = 0 to avoid accidental writes. */
function demo_get_restaurant(): array
{
    $config = require __DIR__ . '/config.php';
    return [
        'id'         => 0,
        'name'       => 'Гастропаб «Север»',
        'subdomain'  => 'demo',
        'status'     => 'active',
        'currency'   => 'RUB',
        'allow_payment_card_later' => 1,
        'allow_payment_cash'       => 1,
        'loyalty_enabled'          => 0,
        'qr_theme'   => 'dark_glass',
    ];
}

/** Dashboard: выручка за сегодня, заказы */
function demo_dashboard_today_stats(): array
{
    return [
        'total_revenue' => 184500,
        'orders_count'  => 42,
    ];
}

/** Demo: гости сегодня (KPI) */
function demo_dashboard_guests_today(): int
{
    return 36;
}

/** Demo: вчера для сравнения */
function demo_dashboard_yesterday(): array
{
    return ['revenue' => 172800, 'orders_count' => 38];
}

/** Demo: выручка по дням (7 дней) для графика */
function demo_dashboard_revenue_trend_7(): array
{
    return [
        date('Y-m-d', strtotime('-6 days')) => 118000,
        date('Y-m-d', strtotime('-5 days')) => 132000,
        date('Y-m-d', strtotime('-4 days')) => 125400,
        date('Y-m-d', strtotime('-3 days')) => 151200,
        date('Y-m-d', strtotime('-2 days')) => 168900,
        date('Y-m-d', strtotime('-1 day'))  => 191000,
        date('Y-m-d')                         => 184500,
    ];
}

/** Demo: CRM на дашборде */
function demo_dashboard_crm_summary(): array
{
    return ['returning_count' => 14, 'pending_messages' => 2, 'has_campaigns' => true];
}

/** Demo: допродажи на дашборде */
function demo_dashboard_upsell_summary(): array
{
    return ['shown' => 380, 'add_clicks' => 52, 'accepted' => 28, 'conversion_pct' => 7.4];
}

function demo_dashboard_status_counts(): array
{
    return ['new' => 3, 'accepted' => 4, 'cooking' => 5, 'ready' => 4];
}

function demo_dashboard_orders(): array
{
    return [
        ['id' => 1001, 'table_id' => 3, 'table_name' => 'Терраса · стол 3', 'total_price' => 3240, 'order_status' => 'ready', 'payment_status' => 'paid', 'payment_type' => 'card', 'created_at' => date('Y-m-d H:i:s', strtotime('-12 min'))],
        ['id' => 1002, 'table_id' => 1, 'table_name' => 'Зал · стол 1', 'total_price' => 2180, 'order_status' => 'cooking', 'payment_status' => 'paid', 'payment_type' => 'cash', 'created_at' => date('Y-m-d H:i:s', strtotime('-25 min'))],
        ['id' => 1003, 'table_id' => 5, 'table_name' => 'Бар · стол 5', 'total_price' => 1560, 'order_status' => 'accepted', 'payment_status' => 'paid', 'payment_type' => 'card', 'created_at' => date('Y-m-d H:i:s', strtotime('-38 min'))],
        ['id' => 1004, 'table_id' => 2, 'table_name' => 'Зал · стол 2', 'total_price' => 2890, 'order_status' => 'delivered', 'payment_status' => 'paid', 'payment_type' => 'cash', 'created_at' => date('Y-m-d H:i:s', strtotime('-55 min'))],
        ['id' => 1005, 'table_id' => 4, 'table_name' => 'Терраса · стол 4', 'total_price' => 4120, 'order_status' => 'new', 'payment_status' => 'paid', 'payment_type' => 'card', 'created_at' => date('Y-m-d H:i:s', strtotime('-70 min'))],
    ];
}

function demo_dashboard_top_items(): array
{
    return [
        ['id' => 5, 'name' => 'Стейк рибай 250 г', 'total_qty' => 38],
        ['id' => 7, 'name' => 'Паста карбонара', 'total_qty' => 34],
        ['id' => 9, 'name' => 'Бургер «Домашний»', 'total_qty' => 29],
        ['id' => 1, 'name' => 'Тартар из лосося', 'total_qty' => 24],
        ['id' => 13, 'name' => 'Лимонад домашний 0,5 л', 'total_qty' => 62],
    ];
}

/** Revenue stats (аналитика) */
function demo_revenue_stats(): array
{
    $days = 30;
    $ordersPerDay = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $ordersPerDay[$d] = 0;
    }
    $last7 = [118000, 132000, 125400, 151200, 168900, 191000, 184500];
    $keys = array_slice(array_keys($ordersPerDay), -7, 7, true);
    foreach (array_values($keys) as $idx => $key) {
        $ordersPerDay[$key] = $last7[$idx] ?? 0;
    }
    return [
        'total_revenue'      => 4120000,
        'total_orders'       => 186,
        'avg_check'          => 1680,
        'upsell_revenue'     => 284000,
        'crm_return_visits'  => 94,
        'orders_per_day'     => $ordersPerDay,
    ];
}

/** Категории меню */
function demo_menu_categories(): array
{
    return [
        ['id' => 1, 'name' => 'Завтраки', 'sort_order' => 1],
        ['id' => 2, 'name' => 'Закуски', 'sort_order' => 2],
        ['id' => 3, 'name' => 'Салаты', 'sort_order' => 3],
        ['id' => 4, 'name' => 'Супы', 'sort_order' => 4],
        ['id' => 5, 'name' => 'Основные блюда', 'sort_order' => 5],
        ['id' => 6, 'name' => 'Гарниры', 'sort_order' => 6],
        ['id' => 7, 'name' => 'Десерты', 'sort_order' => 7],
        ['id' => 8, 'name' => 'Напитки', 'sort_order' => 8],
        ['id' => 9, 'name' => 'Кофе и чай', 'sort_order' => 9],
    ];
}

function demo_menu_items(): array
{
    $u = 'https://images.unsplash.com';
    return [
        ['id' => 1, 'name' => 'Тартар из лосося', 'price' => 890, 'category_id' => 2, 'category_name' => 'Закуски', 'category_sort' => 2, 'available' => 1, 'description' => 'Свежий лосось, авокадо, каперсы, лайм, ржаные гренки.', 'image_path' => null, 'image_url' => $u . '/photo-1546069901-ba9599a7e63c?w=800&q=80&auto=format&fit=crop'],
        ['id' => 2, 'name' => 'Брускетта с рикоттой', 'price' => 420, 'category_id' => 2, 'category_name' => 'Закуски', 'category_sort' => 2, 'available' => 1, 'description' => 'Запечённые томаты, рикотта, базилик, оливковое масло.', 'image_path' => null, 'image_url' => $u . '/photo-1572695157366-5e7ab693e6e8?w=800&q=80&auto=format&fit=crop'],
        ['id' => 3, 'name' => 'Салат с креветками и авокадо', 'price' => 650, 'category_id' => 3, 'category_name' => 'Салаты', 'category_sort' => 3, 'available' => 1, 'description' => 'Микс салата, тигровые креветки, авокадо, соус песто.', 'image_path' => null, 'image_url' => $u . '/photo-1512621776951-a57141f2eefd?w=800&q=80&auto=format&fit=crop'],
        ['id' => 4, 'name' => 'Суп дня (борщ)', 'price' => 380, 'category_id' => 4, 'category_name' => 'Супы', 'category_sort' => 4, 'available' => 1, 'description' => 'Классический борщ со сметаной и пампушками.', 'image_path' => null, 'image_url' => $u . '/photo-1547592166-23ac45744acd?w=800&q=80&auto=format&fit=crop'],
        ['id' => 5, 'name' => 'Стейк рибай 250 г', 'price' => 1890, 'category_id' => 5, 'category_name' => 'Основные блюда', 'category_sort' => 5, 'available' => 1, 'description' => 'Мраморная говядина, соус из перечного соуса, овощи гриль.', 'image_path' => null, 'image_url' => $u . '/photo-1600891964092-4316c288032e?w=800&q=80&auto=format&fit=crop'],
        ['id' => 6, 'name' => 'Филе лосося на гриле', 'price' => 720, 'category_id' => 5, 'category_name' => 'Основные блюда', 'category_sort' => 5, 'available' => 1, 'description' => 'Лосось, пюре из цветной капусты, лимонный соус.', 'image_path' => null, 'image_url' => $u . '/photo-1467003909585-2f8a72700288?w=800&q=80&auto=format&fit=crop'],
        ['id' => 7, 'name' => 'Паста карбонара', 'price' => 590, 'category_id' => 5, 'category_name' => 'Основные блюда', 'category_sort' => 5, 'available' => 1, 'description' => 'Спагетти, гуанчиале, яичный желток, пармезан.', 'image_path' => null, 'image_url' => $u . '/photo-1612874742237-6526221588e3?w=800&q=80&auto=format&fit=crop'],
        ['id' => 8, 'name' => 'Ризотто с белыми грибами', 'price' => 640, 'category_id' => 5, 'category_name' => 'Основные блюда', 'category_sort' => 5, 'available' => 1, 'description' => 'Карнароли, белые грибы, трюфельное масло.', 'image_path' => null, 'image_url' => $u . '/photo-1476124369491-e7adc6d71e91?w=800&q=80&auto=format&fit=crop'],
        ['id' => 9, 'name' => 'Бургер «Домашний»', 'price' => 550, 'category_id' => 5, 'category_name' => 'Основные блюда', 'category_sort' => 5, 'available' => 1, 'description' => 'Говяжья котлета, чеддер, бекон, маринованные огурцы.', 'image_path' => null, 'image_url' => $u . '/photo-1568901346375-23c9450c58cd?w=800&q=80&auto=format&fit=crop'],
        ['id' => 10, 'name' => 'Куриные крылья BBQ', 'price' => 480, 'category_id' => 2, 'category_name' => 'Закуски', 'category_sort' => 2, 'available' => 1, 'description' => 'Копчёные крылья, соус BBQ, сельдерей, блю-чиз.', 'image_path' => null, 'image_url' => $u . '/photo-1527477396000-e27163b481c2?w=800&q=80&auto=format&fit=crop'],
        ['id' => 11, 'name' => 'Чизкейк «Нью-Йорк»', 'price' => 420, 'category_id' => 7, 'category_name' => 'Десерты', 'category_sort' => 7, 'available' => 1, 'description' => 'Классический с ягодным кули.', 'image_path' => null, 'image_url' => $u . '/photo-1533134242442-b4ad571cfe99?w=800&q=80&auto=format&fit=crop'],
        ['id' => 12, 'name' => 'Тирамису', 'price' => 390, 'category_id' => 7, 'category_name' => 'Десерты', 'category_sort' => 7, 'available' => 1, 'description' => 'Маскарпоне, эспрессо, какао.', 'image_path' => null, 'image_url' => $u . '/photo-1571877227200-dffae904c940?w=800&q=80&auto=format&fit=crop'],
        ['id' => 13, 'name' => 'Лимонад домашний 0,5 л', 'price' => 220, 'category_id' => 8, 'category_name' => 'Напитки', 'category_sort' => 8, 'available' => 1, 'description' => 'Мята, лайм, газированная вода.', 'image_path' => null, 'image_url' => $u . '/photo-1523677011780-384d38900d0a?w=800&q=80&auto=format&fit=crop'],
        ['id' => 14, 'name' => 'Эспрессо', 'price' => 180, 'category_id' => 9, 'category_name' => 'Кофе и чай', 'category_sort' => 9, 'available' => 1, 'description' => 'Двойной шот, зёрна из Центральной Америки.', 'image_path' => null, 'image_url' => $u . '/photo-1510591508098-6fa5d0a5bc8f?w=800&q=80&auto=format&fit=crop'],
        ['id' => 15, 'name' => 'Капучино', 'price' => 250, 'category_id' => 9, 'category_name' => 'Кофе и чай', 'category_sort' => 9, 'available' => 1, 'description' => 'Молочная пенка, какао по желанию.', 'image_path' => null, 'image_url' => $u . '/photo-1572442388796-11668a67e53d?w=800&q=80&auto=format&fit=crop'],
        ['id' => 16, 'name' => 'Сырники со сметаной', 'price' => 420, 'category_id' => 1, 'category_name' => 'Завтраки', 'category_sort' => 1, 'available' => 1, 'description' => 'Домашние сырники, сметана и ягодный соус.', 'image_path' => null, 'image_url' => $u . '/photo-1484723091739-30a097e8f929?w=800&q=80&auto=format&fit=crop'],
        ['id' => 17, 'name' => 'Омлет с томатами и зеленью', 'price' => 360, 'category_id' => 1, 'category_name' => 'Завтраки', 'category_sort' => 1, 'available' => 1, 'description' => 'Нежный омлет из трёх яиц с томатами и свежей зеленью.', 'image_path' => null, 'image_url' => $u . '/photo-1510693206972-df098062cb71?w=800&q=80&auto=format&fit=crop'],
        ['id' => 18, 'name' => 'Салат «Цезарь» с курицей', 'price' => 520, 'category_id' => 3, 'category_name' => 'Салаты', 'category_sort' => 3, 'available' => 1, 'description' => 'Романо, куриное филе, пармезан, сухарики, фирменный соус.', 'image_path' => null, 'image_url' => $u . '/photo-1550304943-4f24f54ddde9?w=800&q=80&auto=format&fit=crop'],
        ['id' => 19, 'name' => 'Куриный суп с лапшой', 'price' => 340, 'category_id' => 4, 'category_name' => 'Супы', 'category_sort' => 4, 'available' => 1, 'description' => 'Прозрачный бульон, куриное филе, домашняя лапша и зелень.', 'image_path' => null, 'image_url' => $u . '/photo-1603105037880-880cd4edfb0d?w=800&q=80&auto=format&fit=crop'],
        ['id' => 20, 'name' => 'Картофель по-деревенски', 'price' => 250, 'category_id' => 6, 'category_name' => 'Гарниры', 'category_sort' => 6, 'available' => 1, 'description' => 'Запечённые дольки картофеля с розмарином и чесноком.', 'image_path' => null, 'image_url' => $u . '/photo-1518013431117-eb1465fa5752?w=800&q=80&auto=format&fit=crop'],
        ['id' => 21, 'name' => 'Овощи гриль', 'price' => 290, 'category_id' => 6, 'category_name' => 'Гарниры', 'category_sort' => 6, 'available' => 1, 'description' => 'Цукини, баклажан, сладкий перец и томаты на гриле.', 'image_path' => null, 'image_url' => $u . '/photo-1543332164-6e82f355bad5?w=800&q=80&auto=format&fit=crop'],
        ['id' => 22, 'name' => 'Морс клюквенный 0,3 л', 'price' => 190, 'category_id' => 8, 'category_name' => 'Напитки', 'category_sort' => 8, 'available' => 1, 'description' => 'Освежающий морс из клюквы с лёгкой кислинкой.', 'image_path' => null, 'image_url' => $u . '/photo-1544145945-f90425340c7e?w=800&q=80&auto=format&fit=crop'],
        ['id' => 23, 'name' => 'Чай зелёный жасмин', 'price' => 220, 'category_id' => 9, 'category_name' => 'Кофе и чай', 'category_sort' => 9, 'available' => 1, 'description' => 'Классический листовой чай с ароматом жасмина.', 'image_path' => null, 'image_url' => $u . '/photo-1597481499750-3e6b22637e12?w=800&q=80&auto=format&fit=crop'],
    ];
}

/** Правила допродаж: основное → напиток / гарнир */
function demo_upsell_rules(): array
{
    return [
        ['id' => 1, 'base_item_id' => 5, 'upsell_item_id' => 15, 'base_item_name' => 'Стейк рибай 250 г', 'upsell_item_name' => 'Капучино', 'weight' => 100, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')],
        ['id' => 2, 'base_item_id' => 7, 'upsell_item_id' => 13, 'base_item_name' => 'Паста карбонара', 'upsell_item_name' => 'Лимонад домашний 0,5 л', 'weight' => 90, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')],
        ['id' => 3, 'base_item_id' => 9, 'upsell_item_id' => 10, 'base_item_name' => 'Бургер «Домашний»', 'upsell_item_name' => 'Куриные крылья BBQ', 'weight' => 75, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')],
        ['id' => 4, 'base_item_id' => 3, 'upsell_item_id' => 11, 'base_item_name' => 'Салат с креветками и авокадо', 'upsell_item_name' => 'Чизкейк «Нью-Йорк»', 'weight' => 55, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')],
    ];
}

/** CRM: активные гости */
function demo_crm_guests(): array
{
    return [
        ['id' => 1, 'name' => 'Ирина Волкова',   'phone' => '+7 916 100-42-18', 'visits' => 6,  'last_visit' => date('Y-m-d', strtotime('-2 days')),  'total_spent' => 18420],
        ['id' => 2, 'name' => 'Артём Козлов',    'phone' => '+7 903 221-55-90', 'visits' => 9,  'last_visit' => date('Y-m-d', strtotime('-5 days')),  'total_spent' => 31200],
        ['id' => 3, 'name' => 'Мария Соколова', 'phone' => '+7 926 008-77-31', 'visits' => 3,  'last_visit' => date('Y-m-d', strtotime('-1 day')),   'total_spent' => 6240],
        ['id' => 4, 'name' => 'Дмитрий Орлов',   'phone' => '+7 981 440-12-66', 'visits' => 12, 'last_visit' => date('Y-m-d'),                         'total_spent' => 45800],
        ['id' => 5, 'name' => 'Елена Никифорова', 'phone' => '+7 912 555-01-22', 'visits' => 2,  'last_visit' => date('Y-m-d', strtotime('-4 days')),  'total_spent' => 4180],
    ];
}

/** CRM: исходящие сообщения (демо) */
function demo_crm_outbox(): array
{
    return [
        ['id' => 1, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+1 day')), 'phone' => '+7 916 100-42-18', 'template' => 'return_visit', 'status' => 'pending', 'payload_json' => '{"text":"Напоминание: будем рады снова увидеть вас в «Севере».","reason":"demo"}'],
        ['id' => 2, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'phone' => '+7 903 221-55-90', 'template' => 'return_visit', 'status' => 'processed', 'payload_json' => '{}'],
        ['id' => 3, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+200 days')), 'phone' => '+7 926 008-77-31', 'template' => 'manual_return', 'status' => 'draft', 'payload_json' => '{"text":"Спасибо за визит! Забронируйте стол на выходные.","phone":"+7 926 008-77-31","manual_prepare":true}'],
        ['id' => 4, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+200 days')), 'phone' => '+7 981 440-12-66', 'template' => 'manual_return', 'status' => 'ready_manual', 'payload_json' => '{"text":"Давно вас не было — ждём снова в гастропабе.","phone":"+7 981 440-12-66","manual_prepare":true}'],
    ];
}

/** Проект-админ: лиды (другие заведения) */
function demo_leads(): array
{
    return [
        ['id' => 1, 'restaurant_name' => 'Кафе «У озера»',       'city' => 'Казань',       'status' => 'contacted',      'contact_name' => 'Р. Хасанов', 'contact_phone' => '+7 917 100-01-01', 'expected_mrr' => 4900,  'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')), 'score' => 72, 'priority' => 'warm'],
        ['id' => 2, 'restaurant_name' => 'Суши-бар Koi',         'city' => 'Санкт-Петербург', 'status' => 'demo_scheduled', 'contact_name' => 'А. Иванова', 'contact_phone' => '+7 921 200-02-02', 'expected_mrr' => 7900,  'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')), 'score' => 85, 'priority' => 'hot'],
        ['id' => 3, 'restaurant_name' => 'Гриль Urban',          'city' => 'Екатеринбург', 'status' => 'negotiation',   'contact_name' => 'П. Смирнов', 'contact_phone' => '+7 922 300-03-03', 'expected_mrr' => 7900,  'created_at' => date('Y-m-d H:i:s', strtotime('-7 days')), 'score' => 90, 'priority' => 'hot'],
        ['id' => 4, 'restaurant_name' => 'Винный бар «Гранат»', 'city' => 'Москва',       'status' => 'negotiation',   'contact_name' => 'Е. Волкова', 'contact_phone' => '+7 903 400-04-04', 'expected_mrr' => 12900, 'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'score' => 88, 'priority' => 'warm'],
    ];
}

/** Кухня (KDS): заказы по столам */
function demo_kitchen_orders(): array
{
    return [
        ['id' => 1001, 'table_name' => 'Зал · стол 1', 'order_status' => 'new', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 min')), 'created_at_short' => date('H:i', strtotime('-2 min')), 'items' => [['menu_name' => 'Стейк рибай 250 г', 'quantity' => 1, 'station' => 'HOT'], ['menu_name' => 'Капучино', 'quantity' => 2, 'station' => 'BAR']]],
        ['id' => 1002, 'table_name' => 'Терраса · стол 3', 'order_status' => 'accepted', 'created_at' => date('Y-m-d H:i:s', strtotime('-8 min')), 'created_at_short' => date('H:i', strtotime('-8 min')), 'items' => [['menu_name' => 'Бургер «Домашний»', 'quantity' => 2, 'station' => 'HOT'], ['menu_name' => 'Салат с креветками и авокадо', 'quantity' => 1, 'station' => 'COLD']]],
        ['id' => 1003, 'table_name' => 'Бар · стол 5', 'order_status' => 'cooking', 'created_at' => date('Y-m-d H:i:s', strtotime('-15 min')), 'created_at_short' => date('H:i', strtotime('-15 min')), 'items' => [['menu_name' => 'Паста карбонара', 'quantity' => 2, 'station' => 'HOT'], ['menu_name' => 'Лимонад домашний 0,5 л', 'quantity' => 2, 'station' => 'BAR']]],
        ['id' => 1004, 'table_name' => 'Зал · стол 2', 'order_status' => 'ready', 'created_at' => date('Y-m-d H:i:s', strtotime('-22 min')), 'created_at_short' => date('H:i', strtotime('-22 min')), 'items' => [['menu_name' => 'Тартар из лосося', 'quantity' => 1, 'station' => 'COLD'], ['menu_name' => 'Эспрессо', 'quantity' => 2, 'station' => 'BAR']]],
        ['id' => 1005, 'table_name' => 'Терраса · стол 4', 'order_status' => 'ready', 'created_at' => date('Y-m-d H:i:s', strtotime('-28 min')), 'created_at_short' => date('H:i', strtotime('-28 min')), 'items' => [['menu_name' => 'Филе лосося на гриле', 'quantity' => 2, 'station' => 'HOT']]],
        ['id' => 1006, 'table_name' => 'Зал · стол 1', 'order_status' => 'delivered', 'created_at' => date('Y-m-d H:i:s', strtotime('-35 min')), 'created_at_short' => date('H:i', strtotime('-35 min')), 'items' => [['menu_name' => 'Чизкейк «Нью-Йорк»', 'quantity' => 2, 'station' => 'DESSERT']]],
    ];
}

/**
 * Стол для гостевого QR в демо (любой table_id > 0 валиден; имя подставляется для 1–5).
 */
function demo_table_by_id(int $tableId): array
{
    // Reserved id for platform/delivery preview on demo hosts (see public_html/qr.php).
    if ($tableId === 900001) {
        return ['id' => 900001, 'name' => 'Платформа · доставка', 'restaurant_id' => 0];
    }
    $tableId = max(1, $tableId);
    $names = [
        1 => 'Зал · стол 1',
        2 => 'Зал · стол 2',
        3 => 'Терраса · стол 3',
        4 => 'Терраса · стол 4',
        5 => 'Бар · стол 5',
    ];
    $name = $names[$tableId] ?? ('Зал · стол ' . $tableId);
    return ['id' => $tableId, 'name' => $name, 'restaurant_id' => 0];
}

/** Block destructive or sensitive actions in demo (call from POST handlers). */
function demo_block_if_demo(string $action = 'this action'): void
{
    if (is_demo_mode()) {
        if (function_exists('e')) {
            $msg = "Demo environment — " . $action . " is disabled.";
        } else {
            $msg = "Demo environment — this action is disabled.";
        }
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Demo</title></head><body><p>' . htmlspecialchars($msg) . '</p><p><a href="javascript:history.back()">Go back</a></p></body></html>';
        exit;
    }
}
