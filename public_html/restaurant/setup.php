<?php
/**
 * Restaurant setup: name, city, currency, timezone, language. Save to restaurants, redirect to dashboard.
 */

$rid = bin2hex(random_bytes(4));
require_once __DIR__ . '/../../app/bootstrap.php';
set_exception_handler(function (Throwable $e) use ($rid) {
    error_log('STABILITY_ERROR restaurant/setup.php rid=' . $rid . ' ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body><p>Something went wrong.</p><p style="font-size:11px;color:#666">RID: ' . htmlspecialchars($rid) . '</p></body></html>';
    exit;
});

require_login();
require_current_restaurant();
require_restaurant_role((int)($currentRestaurant['id'] ?? 0), ['owner', 'admin']);

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

$pdo    = db();
$restId = (int)$currentRestaurant['id'];
$errors = [];
$success = false;

$cols = 'id, name, subdomain';
if (file_exists(__DIR__ . '/../../app/schema_guard.php')) {
    require_once __DIR__ . '/../../app/schema_guard.php';
}
$hasCity     = function_exists('db_column_exists') && db_column_exists('restaurants', 'city');
$hasCurrency = function_exists('db_column_exists') && db_column_exists('restaurants', 'currency');
$hasTimezone = function_exists('db_column_exists') && db_column_exists('restaurants', 'timezone');
$hasLanguage = function_exists('db_column_exists') && db_column_exists('restaurants', 'language');
if ($hasCity)     $cols .= ', city';
if ($hasCurrency) $cols .= ', currency';
if ($hasTimezone) $cols .= ', timezone';
if ($hasLanguage) $cols .= ', language';

$deletedSql = function_exists('schema_guard_restaurants_deleted_sql') ? schema_guard_restaurants_deleted_sql('') : '';
$stmt = $pdo->prepare("SELECT {$cols} FROM restaurants WHERE id = ? {$deletedSql} LIMIT 1");
$stmt->execute([$restId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo 'Restaurant not found';
    exit;
}
$currentName     = (string)($row['name'] ?? '');
$currentCity     = $hasCity     ? (string)($row['city'] ?? '')     : '';
$currentCurrency = $hasCurrency ? (string)($row['currency'] ?? 'RUB') : 'RUB';
$currentTimezone = $hasTimezone ? (string)($row['timezone'] ?? 'Europe/Moscow') : 'Europe/Moscow';
$currentLanguage = $hasLanguage ? (string)($row['language'] ?? 'ru') : 'ru';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim((string)($_POST['name'] ?? ''));
    $city     = trim((string)($_POST['city'] ?? ''));
    $currency = trim((string)($_POST['currency'] ?? 'RUB'));
    $timezone = trim((string)($_POST['timezone'] ?? 'Europe/Moscow'));
    $language = trim((string)($_POST['language'] ?? 'ru'));

    if (mb_strlen($name) > 190) {
        $name = mb_substr($name, 0, 190);
    }
    if (mb_strlen($city) > 255) {
        $city = mb_substr($city, 0, 255);
    }
    if (mb_strlen($currency) > 8) {
        $currency = mb_substr($currency, 0, 8);
    }
    if (mb_strlen($timezone) > 64) {
        $timezone = mb_substr($timezone, 0, 64);
    }
    if (mb_strlen($language) > 16) {
        $language = mb_substr($language, 0, 16);
    }

    if ($name === '') {
        $errors[] = 'Название ресторана не может быть пустым.';
    }

    if (empty($errors)) {
        try {
            $sets = ['name = ?'];
            $params = [$name];
            if ($hasCity) {
                $sets[] = 'city = ?';
                $params[] = $city !== '' ? $city : null;
            }
            if ($hasCurrency) {
                $sets[] = 'currency = ?';
                $params[] = $currency !== '' ? $currency : null;
            }
            if ($hasTimezone) {
                $sets[] = 'timezone = ?';
                $params[] = $timezone !== '' ? $timezone : null;
            }
            if ($hasLanguage) {
                $sets[] = 'language = ?';
                $params[] = $language !== '' ? $language : null;
            }
            $params[] = $restId;
            $sql = "UPDATE restaurants SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $success = true;
            header('Location: /restaurant/dashboard.php');
            exit;
        } catch (Throwable $e) {
            error_log('STABILITY_ERROR restaurant/setup.php save rid=' . $rid . ' ' . $e->getMessage());
            $errors[] = 'Не удалось сохранить.';
        }
    }
}

$currencies = ['RUB' => 'RUB', 'USD' => 'USD', 'EUR' => 'EUR'];
$timezones  = ['Europe/Moscow' => 'Москва', 'Europe/Samara' => 'Самара', 'Asia/Yekaterinburg' => 'Екатеринбург', 'UTC' => 'UTC'];
$languages  = ['ru' => 'Русский', 'en' => 'English'];

if (file_exists(__DIR__ . '/../../app/onboarding_progress.php')) {
    require_once __DIR__ . '/../../app/onboarding_progress.php';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= brand_head_tags() ?>
    <title>Настройка ресторана — <?= e($currentRestaurant['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if (file_exists(__DIR__ . '/../assets/css/motion.css')): ?><link rel="stylesheet" href="/assets/css/motion.css"><?php endif; ?>
    <style> body { font-family: Inter, system-ui, sans-serif; } </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-50 flex" style="background-color: #0B0F19;">

<?php
$restaurantSidebarActive = 'setup';
$restaurantSidebarName = (string)($currentRestaurant['name'] ?? 'Ресторан');
require __DIR__ . '/_sidebar.php';
?>

<main class="flex-1 p-4">
    <div class="max-w-2xl mx-auto space-y-6">
        <h2 class="text-2xl font-bold text-slate-50">Setup wizard</h2>

        <!-- Step 1: Restaurant details -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
            <div class="text-xs text-gray-400 uppercase tracking-wide mb-1">Step 1</div>
            <h3 class="text-lg font-semibold text-[#F3F4F6] mb-2">Restaurant details</h3>
            <p class="text-sm text-gray-400 mb-4">Set your restaurant name, currency and timezone. You can change these later in Settings.</p>
            <?php if ($errors): ?>
                <div class="rounded-2xl bg-red-500/20 border border-red-500/50 px-4 py-2 text-sm text-red-100 mb-4">
                    <?php foreach ($errors as $e): ?>
                        <div><?= e($e) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" class="space-y-4">
                <div>
                    <label for="name" class="block text-xs text-slate-400 mb-1">Restaurant name</label>
                    <input type="text" id="name" name="name" value="<?= e($currentName) ?>" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100" required>
                </div>
                <?php if ($hasCity): ?>
                <div>
                    <label for="city" class="block text-xs text-slate-400 mb-1">City</label>
                    <input type="text" id="city" name="city" value="<?= e($currentCity) ?>" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100">
                </div>
                <?php endif; ?>
                <?php if ($hasCurrency): ?>
                <div>
                    <label for="currency" class="block text-xs text-slate-400 mb-1">Currency</label>
                    <select id="currency" name="currency" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100">
                        <?php foreach ($currencies as $code => $label): ?>
                            <option value="<?= e($code) ?>" <?= $currentCurrency === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($hasTimezone): ?>
                <div>
                    <label for="timezone" class="block text-xs text-slate-400 mb-1">Timezone</label>
                    <select id="timezone" name="timezone" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100">
                        <?php foreach ($timezones as $tz => $label): ?>
                            <option value="<?= e($tz) ?>" <?= $currentTimezone === $tz ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($hasLanguage): ?>
                <div>
                    <label for="language" class="block text-xs text-slate-400 mb-1">Language</label>
                    <select id="language" name="language" class="w-full rounded-xl bg-slate-950 border border-slate-700 px-3 py-2 text-sm text-slate-100">
                        <?php foreach ($languages as $lang => $label): ?>
                            <option value="<?= e($lang) ?>" <?= $currentLanguage === $lang ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Save and continue</button>
            </form>
        </section>

        <!-- Step 2: Add menu items -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
            <div class="text-xs text-gray-400 uppercase tracking-wide mb-1">Step 2</div>
            <h3 class="text-lg font-semibold text-[#F3F4F6] mb-2">Add your first dishes</h3>
            <p class="text-sm text-gray-400 mb-4">Create at least 3 menu items so guests can order. Add categories first if you like.</p>
            <a href="/restaurant/menu_items.php" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Add menu items</a>
        </section>

        <!-- Step 3: Create tables -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
            <div class="text-xs text-gray-400 uppercase tracking-wide mb-1">Step 3</div>
            <h3 class="text-lg font-semibold text-[#F3F4F6] mb-2">Create tables</h3>
            <p class="text-sm text-gray-400 mb-4">Add tables so each one gets its own QR code and link for guests.</p>
            <a href="/restaurant/tables.php" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Create tables</a>
        </section>

        <!-- Step 4: Print QR -->
        <section class="rounded-xl border border-gray-800 bg-[#121826] shadow-xl p-5 card-motion">
            <div class="text-xs text-gray-400 uppercase tracking-wide mb-1">Step 4</div>
            <h3 class="text-lg font-semibold text-[#F3F4F6] mb-2">Print QR codes</h3>
            <p class="text-sm text-gray-400 mb-4">Print QR codes for each table and place them on tables. Guests scan to open the menu.</p>
            <a href="/restaurant/qr_print.php" class="inline-flex items-center px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium">Print QR codes</a>
        </section>
    </div>
</main>
</body>
</html>
