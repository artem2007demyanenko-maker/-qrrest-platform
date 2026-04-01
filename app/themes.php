<?php


function qr_builtin_themes(): array
{
    return [
        'dark_glass' => [
            'label' => 'Тёмная, стеклянная',
            'desc'  => 'Глубокий тёмный фон, стеклянные карточки, неоновые акценты.',
            'classes' => [
                'body'         => 'min-h-screen bg-slate-950 text-slate-50',
                'header_chip'  => 'bg-slate-900/80 border border-slate-800',
                'payment_chip' => 'bg-slate-900/80 border border-slate-700',
                'category_chip'=> 'bg-slate-900/80 border border-slate-800',
                'item_card'    => 'bg-slate-900/80 border border-slate-800',
                'placeholder'  => 'bg-slate-900/80 border border-dashed border-slate-700',
                'mini_cart'    => 'bg-slate-900/80 border border-slate-700',
                'cart_shell'   => 'border border-slate-700/70 bg-slate-900/95 md:bg-slate-900/90 md:backdrop-blur-md shadow md:shadow-[0_20px_60px_rgba(0,0,0,0.65)]',
                'cart_item'    => 'bg-slate-950/70 border border-slate-800',
                'price'        => 'text-emerald-400',
                'price_total'  => 'text-emerald-400',
            ],
        ],
        'light_clean' => [
            'label' => 'Кофейня / тёплая светлая',
            'desc'  => 'Бежево-янтарная, “уютная кофейня”: свет, тепло, булочки и капучино.',
            'classes' => [
                'body'         => 'min-h-screen text-slate-900 bg-amber-50 bg-[radial-gradient(circle_at_top,_#fed7aa_0,_#fffbeb_40%,_#fefce8_100%)]',
                'header_chip'  => 'bg-white/90 border border-amber-200 shadow-sm',
                'payment_chip' => 'bg-amber-100/80 border border-amber-300',
                'category_chip'=> 'bg-white/90 border border-amber-200 shadow-sm',
                'item_card'    => 'bg-white/95 border border-amber-200 shadow-sm',
                'placeholder'  => 'bg-amber-50 border border-dashed border-amber-300',
                'mini_cart'    => 'bg-amber-100 border border-amber-300 shadow-md',
                'cart_shell'   => 'border border-amber-200 bg-white/95 md:bg-amber-50/95 md:backdrop-blur-sm shadow md:shadow-[0_18px_50px_rgba(251,191,36,0.35)]',
                'cart_item'    => 'bg-amber-50 border border-amber-200',
                'price'        => 'text-amber-700',
                'price_total'  => 'text-amber-700',
            ],
        ],
        'classic_light' => [
            'label' => 'Классическая светлая',
            'desc'  => 'Чистый светлый стиль: белый фон, нейтральные кнопки и аккуратные карточки.',
            'classes' => [
                'body'         => 'min-h-screen text-slate-900 bg-white bg-[radial-gradient(circle_at_top,_#ffffff_0,_#f8fafc_45%,_#eef2ff_100%)]',
                'header_chip'  => 'bg-white/90 border border-slate-200 shadow-sm',
                'payment_chip' => 'bg-slate-100/70 border border-slate-200',
                'category_chip'=> 'bg-white/90 border border-slate-200 shadow-sm',
                'item_card'    => 'bg-white/95 border border-slate-200 shadow-sm',
                'placeholder'  => 'bg-slate-50 border border-dashed border-slate-300',
                'mini_cart'    => 'bg-white border border-slate-200 shadow-md',
                'cart_shell'   => 'border border-slate-200 bg-white/95 md:bg-white/90 md:backdrop-blur-sm shadow md:shadow-[0_18px_50px_rgba(15,23,42,0.10)]',
                'cart_item'    => 'bg-slate-50 border border-slate-200',
                'price'        => 'text-slate-900',
                'price_total'  => 'text-slate-900',
            ],
        ],
        'premium_dark' => [
            'label' => 'Премиум тёмная',
            'desc'  => 'Премиальная тёмная палитра: глубокий фон, золотые акценты и мягкие тени.',
            'classes' => [
                'body'         => 'min-h-screen bg-[#0f0f0f] text-slate-50',
                'header_chip'  => 'bg-[#141414]/80 border border-amber-900/40 shadow-sm',
                'payment_chip' => 'bg-amber-950/40 border border-amber-900/40',
                'category_chip'=> 'bg-[#121212]/70 border border-amber-900/30',
                'item_card'    => 'bg-[#161616]/70 border border-amber-900/30',
                'placeholder'  => 'bg-[#0f0f0f]/80 border border-dashed border-amber-900/30',
                'mini_cart'    => 'bg-[#141414]/90 border border-amber-900/35 shadow-md',
                'cart_shell'   => 'border border-amber-900/35 bg-[#0f0f0f]/80 md:bg-[#0f0f0f]/70 md:backdrop-blur-md shadow md:shadow-[0_20px_60px_rgba(245,158,11,0.18)]',
                'cart_item'    => 'bg-[#141414]/70 border border-amber-900/30',
                'price'        => 'text-amber-400',
                'price_total'  => 'text-amber-300',
            ],
        ],
        'elegant_minimal' => [
            'label' => 'Элегантный минимализм',
            'desc'  => 'Тонкие границы, много воздуха и спокойные контрастные акценты.',
            'classes' => [
                'body'         => 'min-h-screen text-slate-900 bg-[#f7f7f9] bg-[radial-gradient(circle_at_top,_#ffffff_0,_#f8fafc_55%,_#f1f5f9_100%)]',
                'header_chip'  => 'bg-white/80 border border-slate-200 shadow-sm',
                'payment_chip' => 'bg-white/70 border border-slate-200',
                'category_chip'=> 'bg-white/80 border border-slate-200 shadow-sm',
                'item_card'    => 'bg-white/90 border border-slate-200 shadow-sm',
                'placeholder'  => 'bg-white border border-dashed border-slate-300',
                'mini_cart'    => 'bg-white/90 border border-slate-200 shadow-md',
                'cart_shell'   => 'border border-slate-200 bg-white/90 md:bg-white/80 md:backdrop-blur-sm shadow md:shadow-[0_18px_50px_rgba(2,6,23,0.08)]',
                'cart_item'    => 'bg-slate-50 border border-slate-200',
                'price'        => 'text-slate-900',
                'price_total'  => 'text-slate-900',
            ],
        ],
        'gastro_bold' => [
            'label' => 'Яркое “гastro”',
            'desc'  => 'Контрастная палитра для “menu board” ощущения: насыщенные кнопки и акценты.',
            'classes' => [
                'body'         => 'min-h-screen bg-[#0b1020] text-slate-50',
                'header_chip'  => 'bg-[#101a3a]/80 border border-indigo-600/30 shadow-sm',
                'payment_chip' => 'bg-indigo-950/50 border border-indigo-700/40',
                'category_chip'=> 'bg-[#0f1836]/70 border border-indigo-700/30',
                'item_card'    => 'bg-[#122040]/65 border border-indigo-700/25',
                'placeholder'  => 'bg-[#0b1020]/70 border border-dashed border-indigo-700/20',
                'mini_cart'    => 'bg-[#0f1836]/90 border border-indigo-700/35 shadow-md',
                'cart_shell'   => 'border border-indigo-700/35 bg-[#0b1020]/70 md:bg-[#0b1020]/60 md:backdrop-blur-md shadow md:shadow-[0_20px_60px_rgba(79,70,229,0.30)]',
                'cart_item'    => 'bg-[#0f1836]/70 border border-indigo-700/25',
                'price'        => 'text-indigo-300',
                'price_total'  => 'text-indigo-200',
            ],
        ],
        'dark_emerald' => [
            'label' => 'Тёмная, изумрудная',
            'desc'  => 'Премиальный тёмный стиль с изумрудными акцентами.',
            'classes' => [
                'body'         => 'min-h-screen bg-slate-950 text-slate-50',
                'header_chip'  => 'bg-emerald-950/60 border border-emerald-800',
                'payment_chip' => 'bg-emerald-950/60 border border-emerald-800',
                'category_chip'=> 'bg-slate-950/80 border border-emerald-800',
                'item_card'    => 'bg-slate-950/80 border border-emerald-800/70',
                'placeholder'  => 'bg-slate-950/80 border border-dashed border-emerald-800',
                'mini_cart'    => 'bg-slate-950/80 border border-emerald-800',
                'cart_shell'   => 'border border-emerald-800/70 bg-slate-950/95 md:bg-slate-950/90 md:backdrop-blur-md shadow md:shadow-[0_20px_60px_rgba(4,120,87,0.55)]',
                'cart_item'    => 'bg-slate-950/80 border border-emerald-800/70',
                'price'        => 'text-emerald-300',
                'price_total'  => 'text-emerald-300',
            ],
        ],
    ];
}


function qr_get_themes_for_restaurant(PDO $pdo, ?int $restaurantId): array
{
    $result = [];


    foreach (qr_builtin_themes() as $slug => $data) {
        $result[$slug] = [
            'label'   => $data['label'],
            'desc'    => $data['desc'],
            'classes' => $data['classes'],
            'builtin' => true,
            'from_db' => false,
        ];
    }


    if ($restaurantId !== null) {
        try {

            $check = $pdo->query("SHOW TABLES LIKE 'qr_themes'");
            if ($check && $check->fetchColumn()) {
                $stmt = $pdo->prepare("
                    SELECT *
                    FROM qr_themes
                    WHERE restaurant_id = :rest OR restaurant_id IS NULL
                    ORDER BY is_builtin DESC, id ASC
                ");
                $stmt->execute(['rest' => $restaurantId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $slug = $row['slug'];
                    $classes = [
                        'body'         => $row['css_body'],
                        'header_chip'  => $row['css_header_chip'],
                        'payment_chip' => $row['css_payment_chip'],
                        'category_chip'=> $row['css_category_chip'],
                        'item_card'    => $row['css_item_card'],
                        'placeholder'  => $row['css_placeholder'],
                        'mini_cart'    => $row['css_mini_cart'],
                        'cart_shell'   => $row['css_cart_shell'],
                        'cart_item'    => $row['css_cart_item'],
                        'price'        => $row['css_price'],
                        'price_total'  => $row['css_price_total'],
                    ];


                    $result[$slug] = [
                        'label'   => $row['title'],
                        'desc'    => $row['description'] ?? '',
                        'classes' => $classes,
                        'builtin' => (bool)$row['is_builtin'],
                        'from_db' => true,
                    ];
                }
            }
        } catch (Throwable $e) {

        }
    }

    return $result;
}


function qr_get_theme_classes(PDO $pdo, ?int $restaurantId, string $slug): array
{
    $all = qr_get_themes_for_restaurant($pdo, $restaurantId);

    if (isset($all[$slug])) {
        return $all[$slug]['classes'];
    }


    $builtin = qr_builtin_themes();
    return $builtin['dark_glass']['classes'];
}
