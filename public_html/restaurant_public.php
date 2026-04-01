<?php

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/schema_guard.php';

$pdo = db();

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
security_rate_limit('restaurant_public:' . security_hash_ip($ip), 200, 3600);

// На основном домене: ?rest_id=X — загрузка ресторана по ID; проверки: существует, active, deleted_at IS NULL (если колонка есть)
if (!$currentRestaurant && isset($_GET['rest_id'])) {
    $rid = (int)$_GET['rest_id'];
    if ($rid > 0) {
        $deletedSql = schema_guard_restaurants_deleted_sql('');
        $statusSql = (function_exists('db_column_exists') && db_column_exists('restaurants', 'status')) ? " AND status = 'active'" : '';
        $stmt = $pdo->prepare("SELECT * FROM restaurants WHERE id = :id" . $statusSql . $deletedSql . " LIMIT 1");
        $stmt->execute(['id' => $rid]);
        $currentRestaurant = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!$currentRestaurant) {
    http_response_code(404);
    echo "Ресторан не найден.";
    exit;
}

function stability_sanitize_utm_value(?string $v): string {
    if ($v === null || $v === '') return '';
    $v = preg_replace('/[^a-zA-Z0-9_]/', '', substr(trim($v), 0, 100));
    return $v;
}

$utmRaw = [
    'utm_source'   => $_GET['utm_source'] ?? null,
    'utm_medium'   => $_GET['utm_medium'] ?? null,
    'utm_campaign' => $_GET['utm_campaign'] ?? null,
    'utm_content'  => $_GET['utm_content'] ?? null,
    'utm_term'     => $_GET['utm_term'] ?? null,
];
$utm = [];
foreach ($utmRaw as $k => $val) {
    $s = stability_sanitize_utm_value($val);
    if ($s !== '') $utm[$k] = $s;
}
if (!empty($utm)) {
    $_SESSION['utm'] = $utm;
    if (function_exists('growth_utm_record')) {
        require_once __DIR__ . '/../app/growth.php';
        growth_utm_record((int)$currentRestaurant['id'], $utm);
    }
}

$promoMode = isset($_GET['promo']) && (string)$_GET['promo'] === '1';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}


$name      = (string)($currentRestaurant['name'] ?? 'Ресторан');
$desc      = trim((string)($currentRestaurant['description'] ?? ''));
$address   = trim((string)($currentRestaurant['address'] ?? ''));
$phone     = trim((string)($currentRestaurant['phone'] ?? ''));
$hours     = trim((string)($currentRestaurant['work_hours'] ?? $currentRestaurant['hours'] ?? ''));
$website   = trim((string)($currentRestaurant['website'] ?? ''));
$instagram = trim((string)($currentRestaurant['instagram'] ?? ''));


$coverPath = trim((string)($currentRestaurant['cover_image_path'] ?? $currentRestaurant['cover_path'] ?? ''));
$logoPath  = trim((string)($currentRestaurant['logo_image_path'] ?? $currentRestaurant['logo_path'] ?? ''));

// Лояльность
$loyaltyEnabled = !empty($currentRestaurant['loyalty_enabled']);
$loyaltyPercent = (int)($currentRestaurant['loyalty_percent'] ?? 5);
if ($loyaltyPercent < 0) $loyaltyPercent = 0;
if ($loyaltyPercent > 100) $loyaltyPercent = 100;


$cash     = !empty($currentRestaurant['allow_payment_cash']);
$cardTerm = !empty($currentRestaurant['allow_payment_card_later']);

$categories = [];
$items = [];
$itemsByCategory = [];


try {
    $stmt = $pdo->prepare("
        SELECT id, name, sort_order
        FROM menu_categories
        WHERE restaurant_id = :rid
        ORDER BY sort_order ASC, id ASC
    ");
    $stmt->execute([':rid' => (int)$currentRestaurant['id']]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $categories = [];
}


try {
    $stmt = $pdo->prepare("
        SELECT
            mi.id, mi.name, mi.description, mi.price, mi.image_path, mi.image_url, mi.category_id,
            mc.name AS category_name, mc.sort_order AS category_sort
        FROM menu_items mi
        LEFT JOIN menu_categories mc
            ON mc.id = mi.category_id AND mc.restaurant_id = :rid
        WHERE mi.restaurant_id = :rid2
          AND mi.available = 1
        ORDER BY
            (mc.sort_order IS NULL) ASC,
            mc.sort_order ASC,
            mc.id ASC,
            mi.id DESC
        LIMIT 250
    ");
    $stmt->execute([
        ':rid'  => (int)$currentRestaurant['id'],
        ':rid2' => (int)$currentRestaurant['id'],
    ]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $items = [];
}

foreach ($items as $it) {
    $cat = $it['category_name'] ? (string)$it['category_name'] : 'Меню';
    $itemsByCategory[$cat][] = $it;
}


$featured = array_slice($items, 0, 8);

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= $promoMode ? e($name . ' — Меню и заказ онлайн') : e($name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($promoMode): ?>
    <meta name="description" content="<?= e(mb_substr(strip_tags($desc ?: $name . ' — заказ еды и напитков онлайн, доставка и самовывоз.'), 0, 160)) ?>">
    <?php endif; ?>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        :root{
            --bg: #020617;
            --card: rgba(2, 6, 23, .55);
            --stroke: rgba(255,255,255,.10);
            --stroke2: rgba(255,255,255,.14);
        }
        .hero-bg{
            background:
                radial-gradient(1200px 600px at 10% 0%, rgba(16,185,129,.26), transparent 62%),
                radial-gradient(900px 520px at 90% 8%, rgba(56,189,248,.18), transparent 62%),
                radial-gradient(820px 520px at 65% 95%, rgba(232,121,249,.12), transparent 62%),
                linear-gradient(180deg, #020617, #020617);
        }
        .glass{
            background: var(--card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--stroke);
            box-shadow: 0 30px 80px rgba(0,0,0,.45);
        }
        .glow-border{
            position: relative;
        }
        .glow-border:before{
            content:"";
            position:absolute;
            inset:-1px;
            border-radius: 2rem;
            padding:1px;
            background: linear-gradient(135deg, rgba(16,185,129,.55), rgba(56,189,248,.35), rgba(232,121,249,.35));
            -webkit-mask:
                linear-gradient(#000 0 0) content-box,
                linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events:none;
            opacity:.95;
        }
        .noise{
            position: relative;
        }
        .noise:after{
            content:"";
            position:absolute;
            inset:0;
            pointer-events:none;
            opacity:.25;
            border-radius: inherit;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='240'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='240' height='240' filter='url(%23n)' opacity='.22'/%3E%3C/svg%3E");
            mix-blend-mode: overlay;
        }
        .floaty{ animation: floaty 10s ease-in-out infinite; }
        @keyframes floaty{
            0%,100%{ transform: translate3d(0,0,0) }
            50%{ transform: translate3d(0,-10px,0) }
        }
        .shine{
            position: relative;
            overflow:hidden;
        }
        .shine:before{
            content:"";
            position:absolute;
            inset:-60% -30%;
            background: linear-gradient(110deg, transparent 35%, rgba(255,255,255,.09) 45%, transparent 55%);
            transform: translateX(-30%);
            animation: shine 7s ease-in-out infinite;
        }
        @keyframes shine{
            0%{ transform: translateX(-55%) }
            40%{ transform: translateX(55%) }
            100%{ transform: translateX(55%) }
        }
        .cover{
            background-size: cover;
            background-position: center;
        }
        .chip{
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.10);
        }
        .menu-card{
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
        }
        .menu-card:hover{
            transform: translateY(-3px);
            border-color: rgba(16,185,129,.35);
            box-shadow: 0 18px 50px rgba(0,0,0,.45);
        }
        .fade-in{ animation: fadein .5s ease both; }
        @keyframes fadein{
            from{ opacity:0; transform: translateY(10px) }
            to{ opacity:1; transform: translateY(0) }
        }
        .sticky-nav{
            background: rgba(2,6,23,.55);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255,255,255,.08);
        }
        .no-scrollbar::-webkit-scrollbar{ display:none; }
        .no-scrollbar{ scrollbar-width:none; -ms-overflow-style:none; }
    </style>
</head>

<body class="min-h-screen hero-bg text-slate-50">
<!-- верхний фон-кавер -->
<div class="absolute inset-0 -z-10">
    <?php if ($coverPath): ?>
        <div class="absolute inset-0 cover" style="background-image:url('/storage/<?= e($coverPath) ?>')"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-slate-950/20 via-slate-950/70 to-slate-950"></div>
    <?php endif; ?>
</div>

<!-- NAV -->
<header class="sticky top-0 z-30 sticky-nav">
    <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-3 min-w-0">
            <?php if ($logoPath): ?>
                <div class="w-10 h-10 rounded-2xl overflow-hidden border border-white/10">
                    <img class="w-full h-full object-cover" src="/storage/<?= e($logoPath) ?>" alt="<?= e($name) ?>">
                </div>
            <?php else: ?>
                <div class="w-10 h-10 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center text-[10px] text-white/70">
                    LOGO
                </div>
            <?php endif; ?>

            <div class="min-w-0">
                <div class="truncate font-semibold"><?= e($name) ?></div>
                <div class="text-[11px] text-white/55 truncate">
                    <?= $address ? e($address) : 'Официальная страница ресторана' ?>
                </div>
            </div>
        </div>

        <nav class="hidden sm:flex items-center gap-1 text-xs text-white/70">
            <a href="#menu" class="px-3 py-2 rounded-2xl hover:bg-white/5">Меню</a>
            <a href="#about" class="px-3 py-2 rounded-2xl hover:bg-white/5">О ресторане</a>
            <a href="#how" class="px-3 py-2 rounded-2xl hover:bg-white/5">Как заказать</a>
            <a href="/login.php" class="px-3 py-2 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10">
                Вход персонала
            </a>
        </nav>

        <a href="#menu" class="sm:hidden px-3 py-2 rounded-2xl bg-emerald-400 text-slate-950 font-semibold">
            Меню
        </a>
    </div>
</header>

<main class="max-w-6xl mx-auto px-4 py-8 sm:py-12 space-y-10">
    <!-- HERO -->
    <section class="grid lg:grid-cols-[1.2fr_.8fr] gap-5 items-end">
        <div class="glass glow-border noise rounded-[2rem] p-6 sm:p-8 shine fade-in">
            <div class="inline-flex items-center gap-2 text-[11px] text-white/80 chip px-3 py-1 rounded-full mb-5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                QR-меню у столика • быстро • без ожидания
            </div>

            <h1 class="text-4xl sm:text-6xl font-semibold leading-[1.02]">
                <?= e($name) ?>
            </h1>

            <p class="mt-4 text-sm sm:text-base text-white/80 max-w-2xl leading-relaxed">
                <?= $desc !== '' ? e($desc) : 'Здесь всё устроено так, чтобы вы просто наслаждались. Сканируете QR, выбираете блюда — мы начинаем готовить.' ?>
            </p>

            <div class="mt-6 flex flex-col sm:flex-row gap-2">
                <a href="#menu"
                   class="inline-flex items-center justify-center px-5 py-3 rounded-2xl bg-emerald-400 hover:bg-emerald-300 text-slate-950 font-semibold shadow-lg shadow-emerald-500/25 transition">
                    Посмотреть меню
                </a>
                <a href="#how"
                   class="inline-flex items-center justify-center px-5 py-3 rounded-2xl bg-white/5 hover:bg-white/10 border border-white/10 text-white/85 font-semibold transition">
                    Как сделать заказ
                </a>
            </div>

            <div class="mt-6 flex flex-wrap gap-2 text-[11px] text-white/70">
                <?php if ($cash): ?><span class="px-3 py-1 rounded-full chip">Наличные</span><?php endif; ?>
                <?php if ($cardTerm): ?><span class="px-3 py-1 rounded-full chip">Картой (терминал)</span><?php endif; ?>
                <?php if ($loyaltyEnabled): ?>
                    <span class="px-3 py-1 rounded-full chip">
                        Бонусы <?= (int)$loyaltyPercent ?>% • только в этом ресторане
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="space-y-3 fade-in" style="animation-delay:.08s">
            <div class="glass noise rounded-[2rem] p-5 sm:p-6 floaty">
                <div class="text-[11px] uppercase tracking-[0.18em] text-white/55 mb-3">Сегодня</div>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-2xl bg-white/5 border border-white/10 p-4">
                        <div class="text-white/85 font-semibold">Время</div>
                        <div class="text-white/65 text-xs mt-1"><?= $hours ? e($hours) : 'Уточняйте у персонала' ?></div>
                    </div>
                    <div class="rounded-2xl bg-white/5 border border-white/10 p-4">
                        <div class="text-white/85 font-semibold">Локация</div>
                        <div class="text-white/65 text-xs mt-1"><?= $address ? e($address) : '—' ?></div>
                    </div>
                    <div class="rounded-2xl bg-white/5 border border-white/10 p-4">
                        <div class="text-white/85 font-semibold">Телефон</div>
                        <div class="text-white/65 text-xs mt-1">
                            <?= $phone ? '<a class="underline decoration-dotted hover:text-white" href="tel:'.e($phone).'">'.e($phone).'</a>' : '—' ?>
                        </div>
                    </div>
                    <div class="rounded-2xl bg-white/5 border border-white/10 p-4">
                        <div class="text-white/85 font-semibold">Соцсети</div>
                        <div class="text-white/65 text-xs mt-1">
                            <?php if ($instagram): ?>
                                <a class="underline decoration-dotted hover:text-white" target="_blank" rel="nofollow noopener" href="<?= e($instagram) ?>">Instagram</a>
                            <?php elseif ($website): ?>
                                <a class="underline decoration-dotted hover:text-white" target="_blank" rel="nofollow noopener" href="<?= e($website) ?>">Сайт</a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-2xl bg-emerald-400/10 border border-emerald-400/20 p-4">
                    <div class="text-white/90 font-semibold">Заказ через QR</div>
                    <div class="text-white/70 text-xs mt-1">
                        QR-код на столике откроет меню именно для вашего столика.
                    </div>
                </div>
            </div>

            <div class="glass noise rounded-[2rem] p-5 sm:p-6">
                <div class="text-[11px] uppercase tracking-[0.18em] text-white/55 mb-3">Быстрые ссылки</div>
                <div class="flex flex-wrap gap-2 text-xs">
                    <a href="#menu" class="px-3 py-2 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10">Меню</a>
                    <a href="#featured" class="px-3 py-2 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10">Хиты</a>
                    <a href="#how" class="px-3 py-2 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10">Как заказать</a>
                    <a href="/login.php" class="px-3 py-2 rounded-2xl bg-white/5 border border-white/10 hover:bg-white/10">Вход персонала</a>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURED -->
    <section id="featured" class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl sm:text-2xl font-semibold">Хиты меню</h2>
            <div class="h-px flex-1 bg-gradient-to-r from-white/10 via-white/5 to-transparent"></div>
        </div>

        <?php if (!$featured): ?>
            <div class="glass noise rounded-[2rem] p-6 text-white/75">
                Меню пока пустое. Добавьте блюда в панели ресторана — и они появятся здесь автоматически.
            </div>
        <?php else: ?>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <?php foreach ($featured as $it): ?>
                    <?php
                        $img = menu_item_image_url($it) ?? '';
                        $price = number_format((float)$it['price'], 0, '.', ' ');
                    ?>
                    <article class="glass noise rounded-[2rem] p-3 menu-card border border-white/10">
                        <div class="rounded-2xl overflow-hidden bg-white/5 border border-white/10 aspect-[4/3]">
                            <?php if ($img): ?>
                                <img src="<?= e($img) ?>" alt="<?= e($it['name']) ?>" loading="lazy" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-[11px] text-white/50">
                                    Нет фото
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-semibold text-sm truncate"><?= e($it['name']) ?></div>
                                    <div class="text-[11px] text-white/55 truncate">
                                        <?= e($it['category_name'] ?: 'Меню') ?>
                                    </div>
                                </div>
                                <div class="text-sm font-semibold text-emerald-200 whitespace-nowrap">
                                    <?= $price ?> ₽
                                </div>
                            </div>

                            <?php if (!empty($it['description'])): ?>
                                <div class="mt-2 text-[11px] text-white/65 line-clamp-2">
                                    <?= e($it['description']) ?>
                                </div>
                            <?php endif; ?>

                            <div class="mt-3 text-[11px] text-white/55">
                                Заказать можно через QR на столике
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- MENU -->
    <section id="menu" class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl sm:text-2xl font-semibold">Меню</h2>
            <div class="h-px flex-1 bg-gradient-to-r from-white/10 via-white/5 to-transparent"></div>
        </div>

        <?php if ($itemsByCategory): ?>
            <!-- чипсы категорий -->
            <div class="flex gap-2 overflow-x-auto no-scrollbar py-1">
                <?php foreach ($itemsByCategory as $catName => $_): ?>
                    <a href="#cat-<?= md5($catName) ?>"
                       class="whitespace-nowrap px-4 py-2 rounded-full chip text-xs text-white/85 hover:border-emerald-400/40">
                        <?= e($catName) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- категории -->
            <div class="space-y-8">
                <?php foreach ($itemsByCategory as $catName => $list): ?>
                    <div id="cat-<?= md5($catName) ?>" class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-white/95"><?= e($catName) ?></h3>
                            <div class="h-px flex-1 bg-gradient-to-r from-white/10 via-white/5 to-transparent"></div>
                        </div>

                        <div class="grid sm:grid-cols-2 gap-3">
                            <?php foreach ($list as $it): ?>
                                <?php
                                    $img = menu_item_image_url($it) ?? '';
                                    $price = number_format((float)$it['price'], 0, '.', ' ');
                                ?>
                                <article class="glass noise rounded-[2rem] p-4 menu-card border border-white/10">
                                    <div class="flex gap-3">
                                        <div class="w-20 h-20 rounded-2xl overflow-hidden bg-white/5 border border-white/10 flex-shrink-0">
                                            <?php if ($img): ?>
                                                <img src="<?= e($img) ?>" alt="<?= e($it['name']) ?>" loading="lazy" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <div class="w-full h-full flex items-center justify-center text-[10px] text-white/50">Нет фото</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <div class="font-semibold text-sm truncate"><?= e($it['name']) ?></div>
                                                    <?php if (!empty($it['description'])): ?>
                                                        <div class="mt-1 text-[11px] text-white/65 line-clamp-2">
                                                            <?= e($it['description']) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-sm font-semibold text-emerald-200 whitespace-nowrap">
                                                    <?= $price ?> ₽
                                                </div>
                                            </div>

                                            <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-white/60">
                                                <span class="px-2 py-1 rounded-full bg-white/5 border border-white/10">заказ через QR</span>
                                                <?php if ($loyaltyEnabled): ?>
                                                    <span class="px-2 py-1 rounded-full bg-emerald-400/10 border border-emerald-400/20">
                                                        + бонусы
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="glass noise rounded-[2rem] p-6 text-white/75">
                Меню пока пустое. Добавьте категории и блюда — и страница будет “вау” автоматически.
            </div>
        <?php endif; ?>
    </section>

    <!-- ABOUT -->
    <section id="about" class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl sm:text-2xl font-semibold">О ресторане</h2>
            <div class="h-px flex-1 bg-gradient-to-r from-white/10 via-white/5 to-transparent"></div>
        </div>

        <div class="glass glow-border noise rounded-[2rem] p-6 sm:p-8">
            <div class="grid md:grid-cols-3 gap-3">
                <div class="rounded-2xl bg-white/5 border border-white/10 p-5">
                    <div class="text-sm font-semibold">Атмосфера</div>
                    <div class="text-xs text-white/70 mt-2 leading-relaxed">
                        Тёплый свет, аккуратная подача, быстрый сервис. Страница — как витрина, заказ — через QR у столика.
                    </div>
                </div>
                <div class="rounded-2xl bg-white/5 border border-white/10 p-5">
                    <div class="text-sm font-semibold">Лояльность</div>
                    <div class="text-xs text-white/70 mt-2 leading-relaxed">
                        <?= $loyaltyEnabled
                            ? 'Начисляем бонусы на номер телефона. Потратить можно только в этом ресторане.'
                            : 'Программа лояльности может быть включена владельцем ресторана.'
                        ?>
                    </div>
                </div>
                <div class="rounded-2xl bg-white/5 border border-white/10 p-5">
                    <div class="text-sm font-semibold">Оплата</div>
                    <div class="text-xs text-white/70 mt-2 leading-relaxed">
                        <?= ($cash || $cardTerm)
                            ? 'Оплата у персонала: ' . ($cash ? 'наличные ' : '') . ($cardTerm ? 'картой (терминал)' : '')
                            : 'Оплата у персонала.'
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- HOW -->
    <section id="how" class="space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-xl sm:text-2xl font-semibold">Как сделать заказ</h2>
            <div class="h-px flex-1 bg-gradient-to-r from-white/10 via-white/5 to-transparent"></div>
        </div>

        <div class="glass noise rounded-[2rem] p-6 sm:p-8">
            <div class="grid sm:grid-cols-3 gap-3">
                <div class="rounded-2xl bg-white/5 border border-white/10 p-5">
                    <div class="text-sm font-semibold">1) Сканируете QR</div>
                    <div class="text-xs text-white/70 mt-2">
                        QR на столике ведёт в меню ресторана.
                    </div>
                </div>
                <div class="rounded-2xl bg-white/5 border border-white/10 p-5">
                    <div class="text-sm font-semibold">2) Выбираете блюда</div>
                    <div class="text-xs text-white/70 mt-2">
                        Добавляете в корзину и отправляете заказ без ожидания официанта.
                    </div>
                </div>
                <div class="rounded-2xl bg-white/5 border border-white/10 p-5">
                    <div class="text-sm font-semibold">3) Мы готовим</div>
                    <div class="text-xs text-white/70 mt-2">
                        Персонал видит заказ и кухня берет его в работу.
                    </div>
                </div>
            </div>

            <div class="mt-5 flex flex-col sm:flex-row gap-2 sm:items-center sm:justify-between">
                <div class="text-xs text-white/60">
                    Важно: витрина не оформляет заказ — заказ только через QR у столика.
                </div>
                <a href="/login.php" class="text-xs text-white/70 hover:text-white underline decoration-dotted">
                    Персоналу → вход
                </a>
            </div>
        </div>
    </section>

    <footer class="pb-10 text-center text-[11px] text-white/45">
        © <?= date('Y') ?> <?= e($name) ?>
    </footer>
</main>

</body>
</html>
