#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../app/bootstrap.php';

/**
 * Usage:
 *   php tools/backfill_menu_food_meta.php --dry-run
 *   php tools/backfill_menu_food_meta.php --apply
 *   php tools/backfill_menu_food_meta.php --apply --restaurant-id=1
 *   php tools/backfill_menu_food_meta.php --apply --force
 */

$opts = getopt('', ['dry-run', 'apply', 'force', 'restaurant-id::', 'limit::']);
$isDryRun = isset($opts['dry-run']) || !isset($opts['apply']);
$isApply = isset($opts['apply']);
$force = isset($opts['force']);
$restaurantId = isset($opts['restaurant-id']) ? (int)$opts['restaurant-id'] : 0;
$limit = isset($opts['limit']) ? max(0, (int)$opts['limit']) : 0;

$pdo = db();

$where = [];
$params = [];
if ($restaurantId > 0) {
    $where[] = 'restaurant_id = :rid';
    $params[':rid'] = $restaurantId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$limitSql = $limit > 0 ? (' LIMIT ' . $limit) : '';

$sql = "
    SELECT id, restaurant_id, category_id, name, description, ingredients, allergens, weight_grams, tags
    FROM menu_items
    {$whereSql}
    ORDER BY restaurant_id ASC, id ASC
    {$limitSql}
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

function norm(string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return $s;
}

function hasCyr(string $s): bool {
    return preg_match('/[А-Яа-яЁё]/u', $s) === 1;
}

function looksMojibake(string $s): bool {
    if ($s === '') return false;
    if (strpos($s, "�") !== false) return true;
    return preg_match('/Ð|Ñ|â/u', $s) === 1;
}

function repairMojibake(string $s): ?string {
    if (!looksMojibake($s)) return null;
    $fixed = @mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    if (!is_string($fixed) || trim($fixed) === '') {
        return null;
    }
    if (hasCyr($fixed) && !looksMojibake($fixed)) {
        return trim($fixed);
    }
    return null;
}

function csvTags(array $tags): ?string {
    $out = [];
    foreach ($tags as $t) {
        $v = trim(mb_strtolower((string)$t, 'UTF-8'));
        if ($v !== '' && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out ? implode(',', $out) : null;
}

function inferFoodMeta(array $row): array {
    $name = norm((string)($row['name'] ?? ''));
    $desc = norm((string)($row['description'] ?? ''));

    $rules = [
        [
            'match' => ['омлет с томатами и зеленью', 'омлет'],
            'ingredients' => 'Яйца, томаты, зелень, сливочное масло',
            'allergens' => 'Яйца, молочные продукты',
            'weight_grams' => 220,
            'tags' => ['завтрак'],
        ],
        [
            'match' => ['картофель по-деревенски'],
            'ingredients' => 'Картофель, специи, растительное масло',
            'allergens' => null,
            'weight_grams' => 180,
            'tags' => ['гарнир'],
        ],
        [
            'match' => ['морс клюквенный', 'морс'],
            'ingredients' => 'Клюква, вода, сахар',
            'allergens' => null,
            'weight_grams' => 300,
            'tags' => ['напиток'],
        ],
        [
            'match' => ['эспрессо'],
            'ingredients' => 'Кофе, вода',
            'allergens' => null,
            'weight_grams' => 30,
            'tags' => ['кофе', 'напиток'],
        ],
        [
            'match' => ['капучино'],
            'ingredients' => 'Эспрессо, молоко',
            'allergens' => 'Молочные продукты',
            'weight_grams' => 250,
            'tags' => ['кофе', 'напиток'],
        ],
        [
            'match' => ['чизкейк «нью-йорк»', 'чизкейк'],
            'ingredients' => 'Сливочный сыр, сливки, яйца, сахар, песочная основа',
            'allergens' => 'Молочные продукты, яйца, глютен',
            'weight_grams' => 150,
            'tags' => ['десерт'],
        ],
        [
            'match' => ['тирамису'],
            'ingredients' => 'Маскарпоне, сливки, яйца, савоярди, кофе, какао',
            'allergens' => 'Молочные продукты, яйца, глютен',
            'weight_grams' => 150,
            'tags' => ['десерт'],
        ],
        [
            'match' => ['паста карбонара', 'карбонара'],
            'ingredients' => 'Спагетти, гуанчиале, яичный желток, пармезан, чёрный перец',
            'allergens' => 'Глютен, яйца, молочные продукты',
            'weight_grams' => 300,
            'tags' => ['паста'],
        ],
        [
            'match' => ['бургер «домашний»', 'бургер'],
            'ingredients' => 'Булочка, говяжья котлета, чеддер, бекон, огурцы, соус',
            'allergens' => 'Глютен, молочные продукты',
            'weight_grams' => 300,
            'tags' => ['бургер'],
        ],
        [
            'match' => ['лимонад домашний', 'лимонад'],
            'ingredients' => 'Вода, лайм, мята, сироп, лёд',
            'allergens' => null,
            'weight_grams' => 500,
            'tags' => ['напиток'],
        ],
        [
            'match' => ['чай зелёный жасмин', 'зеленый жасмин', 'зелёный жасмин'],
            'ingredients' => 'Зелёный чай, жасмин',
            'allergens' => null,
            'weight_grams' => 300,
            'tags' => ['чай', 'напиток'],
        ],
        [
            'match' => ['стейк рибай', 'рибай'],
            'ingredients' => 'Говядина, соль, перец',
            'allergens' => null,
            'weight_grams' => 250,
            'tags' => ['мясо'],
        ],
        [
            'match' => ['филе лосося на гриле', 'лосося на гриле', 'лосось на гриле'],
            'ingredients' => 'Лосось, лимонный соус, цветная капуста',
            'allergens' => 'Рыба',
            'weight_grams' => 260,
            'tags' => ['рыба'],
        ],
        [
            'match' => ['ризотто с белыми грибами', 'ризотто'],
            'ingredients' => 'Рис арборио, белые грибы, сливочное масло, пармезан',
            'allergens' => 'Молочные продукты',
            'weight_grams' => 280,
            'tags' => ['горячее'],
        ],
        [
            'match' => ['овощи гриль'],
            'ingredients' => 'Цукини, баклажан, перец, томаты, оливковое масло',
            'allergens' => null,
            'weight_grams' => 200,
            'tags' => ['гарнир', 'овощи'],
        ],
        [
            'match' => ['сырник', 'сырники'],
            'ingredients' => 'Творог, яйцо, мука, сахар, сметана, ягодный соус',
            'allergens' => 'Молочные продукты, яйца, глютен',
            'weight_grams' => 180,
            'tags' => ['завтрак', 'вегетарианское'],
        ],
        [
            'match' => ['куриный суп', 'суп с лапшой', 'лапша'],
            'ingredients' => 'Куриный бульон, куриное филе, лапша, морковь, зелень',
            'allergens' => 'Глютен',
            'weight_grams' => 320,
            'tags' => ['суп'],
        ],
        [
            'match' => ['борщ'],
            'ingredients' => 'Говядина, свёкла, капуста, картофель, морковь, лук',
            'allergens' => null,
            'weight_grams' => 350,
            'tags' => ['суп'],
        ],
        [
            'match' => ['цезарь'],
            'ingredients' => 'Салат ромэн, курица, сыр, сухарики, соус цезарь',
            'allergens' => 'Молочные продукты, яйца, глютен',
            'weight_grams' => 220,
            'tags' => ['салат'],
        ],
        [
            'match' => ['тирамису', 'чизкейк', 'торт', 'пирожн'],
            'ingredients' => null,
            'allergens' => 'Молочные продукты, яйца, глютен',
            'weight_grams' => 150,
            'tags' => ['десерт'],
        ],
    ];

    foreach ($rules as $r) {
        foreach ($r['match'] as $m) {
            if (str_contains($name, $m)) {
                return [
                    'ingredients' => $r['ingredients'],
                    'allergens' => $r['allergens'],
                    'weight_grams' => $r['weight_grams'],
                    'tags' => csvTags($r['tags']),
                    'source' => 'rule:' . $m,
                ];
            }
        }
    }

    $tags = [];
    $ingredients = null;
    $allergens = null;
    $weight = null;

    if (str_contains($name, 'суп') || str_contains($name, 'борщ')) {
        $tags[] = 'суп';
        $weight = 320;
    }
    if (str_contains($name, 'салат')) {
        $tags[] = 'салат';
        $weight = $weight ?? 220;
    }
    if (str_contains($name, 'кофе') || str_contains($name, 'чай') || str_contains($name, 'морс') || str_contains($name, 'лимонад')) {
        $tags[] = 'напиток';
        $allergens = null;
        $weight = $weight ?? 300;
    }
    if (str_contains($name, 'эспрессо')) {
        $tags[] = 'кофе';
        $tags[] = 'напиток';
        $weight = 30;
        $ingredients = $ingredients ?? 'Кофе, вода';
    }
    if (str_contains($name, 'капучино')) {
        $tags[] = 'кофе';
        $tags[] = 'напиток';
        $weight = 250;
        $ingredients = $ingredients ?? 'Эспрессо, молоко';
        $allergens = 'Молочные продукты';
    }
    if (str_contains($name, 'чай') && (str_contains($name, 'жасмин') || str_contains($desc, 'жасмин'))) {
        $tags[] = 'чай';
        $tags[] = 'напиток';
        $weight = $weight ?? 300;
        $ingredients = $ingredients ?? 'Зелёный чай, жасмин';
    }
    if (str_contains($name, 'морс') && str_contains($name, 'клюк')) {
        $tags[] = 'напиток';
        $weight = $weight ?? 300;
        $ingredients = $ingredients ?? 'Клюква, вода, сахар';
    }
    if (str_contains($name, 'лимонад')) {
        $tags[] = 'напиток';
        $weight = $weight ?? 500;
        $ingredients = $ingredients ?? 'Вода, лайм, мята, сироп, лёд';
    }
    if (str_contains($name, 'омлет')) {
        $tags[] = 'завтрак';
        $weight = $weight ?? 220;
        $ingredients = $ingredients ?? 'Яйца, томаты, зелень, сливочное масло';
        $allergens = $allergens ?? 'Яйца, молочные продукты';
    }
    if (str_contains($name, 'картофель по-деревенски')) {
        $tags[] = 'гарнир';
        $weight = $weight ?? 180;
        $ingredients = $ingredients ?? 'Картофель, специи, растительное масло';
    }
    if (str_contains($name, 'карбонара')) {
        $tags[] = 'паста';
        $weight = $weight ?? 300;
        $ingredients = $ingredients ?? 'Спагетти, гуанчиале, яичный желток, пармезан, чёрный перец';
        $allergens = 'Глютен, яйца, молочные продукты';
    }
    if (str_contains($name, 'бургер')) {
        $tags[] = 'бургер';
        $weight = $weight ?? 300;
        $ingredients = $ingredients ?? 'Булочка, говяжья котлета, чеддер, бекон, огурцы, соус';
        $allergens = $allergens ?? 'Глютен, молочные продукты';
    }
    if (str_contains($name, 'рибай') || str_contains($name, 'стейк')) {
        $tags[] = 'мясо';
        $weight = $weight ?? 250;
        $ingredients = $ingredients ?? 'Говядина, соль, перец';
    }
    if (str_contains($name, 'лосось') && str_contains($name, 'грил')) {
        $tags[] = 'рыба';
        $weight = $weight ?? 260;
        $ingredients = $ingredients ?? 'Лосось, лимонный соус, цветная капуста';
        $allergens = $allergens ?? 'Рыба';
    }
    if (str_contains($name, 'ризотто')) {
        $tags[] = 'горячее';
        $weight = $weight ?? 280;
        $ingredients = $ingredients ?? 'Рис арборио, белые грибы, сливочное масло, пармезан';
        $allergens = $allergens ?? 'Молочные продукты';
    }
    if (str_contains($name, 'овощи гриль')) {
        $tags[] = 'гарнир';
        $tags[] = 'овощи';
        $weight = $weight ?? 200;
        $ingredients = $ingredients ?? 'Цукини, баклажан, перец, томаты, оливковое масло';
    }
    if (str_contains($name, 'десерт') || str_contains($name, 'торт') || str_contains($name, 'пирож')) {
        $tags[] = 'десерт';
        $allergens = $allergens ?? 'Молочные продукты, яйца, глютен';
        $weight = $weight ?? 150;
    }
    if (str_contains($name, 'бургер') || str_contains($name, 'паста') || str_contains($name, 'пицца')) {
        $allergens = 'Глютен';
        $weight = $weight ?? 300;
    }
    if (str_contains($name, 'сыр') || str_contains($desc, 'сыр')) {
        $allergens = $allergens ? ($allergens . ', Молочные продукты') : 'Молочные продукты';
    }
    if (str_contains($name, 'сливоч') || str_contains($desc, 'сливоч')) {
        $allergens = $allergens ? ($allergens . ', Молочные продукты') : 'Молочные продукты';
    }
    if (str_contains($name, 'яйц') || str_contains($desc, 'яйц')) {
        $allergens = $allergens ? ($allergens . ', яйца') : 'Яйца';
    }
    if (str_contains($desc, ',')) {
        $parts = array_filter(array_map('trim', preg_split('/[,;]+/u', $desc) ?: []), static fn($x) => $x !== '');
        if (count($parts) >= 3 && count($parts) <= 10) {
            $ingredients = implode(', ', $parts);
        }
    }

    return [
        'ingredients' => $ingredients,
        'allergens' => $allergens,
        'weight_grams' => $weight,
        'tags' => csvTags($tags),
        'source' => 'heuristic',
    ];
}

function strFilled(?string $s): bool {
    return $s !== null && trim($s) !== '';
}

$total = count($rows);
$alreadyGood = 0;
$updated = 0;
$skipped = 0;
$undetermined = 0;
$mojibakeFound = 0;
$enriched = 0;
$fullyBackfilled = 0;
$examples = [];

$upd = $pdo->prepare("
    UPDATE menu_items
    SET ingredients = :ingredients,
        allergens = :allergens,
        weight_grams = :weight_grams,
        tags = :tags
    WHERE id = :id
");

foreach ($rows as $row) {
    $id = (int)$row['id'];
    $origIngredients = isset($row['ingredients']) ? (string)$row['ingredients'] : '';
    $origAllergens = isset($row['allergens']) ? (string)$row['allergens'] : '';
    $origTags = isset($row['tags']) ? (string)$row['tags'] : '';
    $origWeight = isset($row['weight_grams']) && $row['weight_grams'] !== null ? (int)$row['weight_grams'] : null;

    $hasIngredients = strFilled($origIngredients);
    $hasAllergens = strFilled($origAllergens);
    $hasTags = strFilled($origTags);
    $hasWeight = ($origWeight !== null && $origWeight > 0);
    $filled = $hasIngredients || $hasAllergens || $hasTags || $hasWeight;
    $fullFilled = $hasIngredients && $hasAllergens && $hasTags && $hasWeight;
    $bad = looksMojibake($origIngredients) || looksMojibake($origAllergens) || looksMojibake($origTags);

    if ($bad) {
        $mojibakeFound++;
    }

    if ($fullFilled && !$bad && !$force) {
        $alreadyGood++;
        continue;
    }

    $newIngredients = $origIngredients;
    $newAllergens = $origAllergens;
    $newTags = $origTags;
    $newWeight = $origWeight;

    if (looksMojibake($newIngredients)) {
        $fixed = repairMojibake($newIngredients);
        $newIngredients = $fixed ?? '';
    }
    if (looksMojibake($newAllergens)) {
        $fixed = repairMojibake($newAllergens);
        $newAllergens = $fixed ?? '';
    }
    if (looksMojibake($newTags)) {
        $fixed = repairMojibake($newTags);
        $newTags = $fixed ?? '';
    }

    $inferred = inferFoodMeta($row);

    if (($force || !strFilled($newIngredients)) && strFilled($inferred['ingredients'] ?? null)) {
        $newIngredients = (string)$inferred['ingredients'];
    }
    if (($force || !strFilled($newAllergens)) && strFilled($inferred['allergens'] ?? null)) {
        $newAllergens = (string)$inferred['allergens'];
    }
    if (($force || !strFilled($newTags)) && strFilled($inferred['tags'] ?? null)) {
        $newTags = (string)$inferred['tags'];
    }
    if (($force || !$newWeight || $newWeight <= 0) && !empty($inferred['weight_grams'])) {
        $newWeight = (int)$inferred['weight_grams'];
    }

    $changed = (
        $newIngredients !== $origIngredients
        || $newAllergens !== $origAllergens
        || $newTags !== $origTags
        || ((int)($newWeight ?? 0) !== (int)($origWeight ?? 0))
    );

    if (!$changed) {
        $skipped++;
        if (!strFilled($newIngredients) && !strFilled($newAllergens) && !strFilled($newTags) && (!$newWeight || $newWeight <= 0)) {
            $undetermined++;
        }
        continue;
    }

    if (!$isDryRun && $isApply) {
        $upd->execute([
            ':id' => $id,
            ':ingredients' => strFilled($newIngredients) ? $newIngredients : null,
            ':allergens' => strFilled($newAllergens) ? $newAllergens : null,
            ':weight_grams' => ($newWeight && $newWeight > 0) ? $newWeight : null,
            ':tags' => strFilled($newTags) ? $newTags : null,
        ]);
    }

    $updated++;
    if ($filled) {
        $enriched++;
    } else {
        $fullyBackfilled++;
    }
    if (count($examples) < 8) {
        $examples[] = [
            'id' => $id,
            'name' => (string)$row['name'],
            'source' => (string)($inferred['source'] ?? 'mixed'),
            'before' => [
                'ingredients' => $origIngredients,
                'allergens' => $origAllergens,
                'weight_grams' => $origWeight,
                'tags' => $origTags,
            ],
            'after' => [
                'ingredients' => strFilled($newIngredients) ? $newIngredients : null,
                'allergens' => strFilled($newAllergens) ? $newAllergens : null,
                'weight_grams' => ($newWeight && $newWeight > 0) ? $newWeight : null,
                'tags' => strFilled($newTags) ? $newTags : null,
            ],
        ];
    }
}

echo "Mode: " . ($isDryRun ? "DRY-RUN" : "APPLY") . PHP_EOL;
echo "Total items: {$total}" . PHP_EOL;
echo "Already filled & good: {$alreadyGood}" . PHP_EOL;
echo "Updated: {$updated}" . PHP_EOL;
echo "Enriched existing rows: {$enriched}" . PHP_EOL;
echo "Fully backfilled rows: {$fullyBackfilled}" . PHP_EOL;
echo "Skipped: {$skipped}" . PHP_EOL;
echo "Still undetermined rows: {$undetermined}" . PHP_EOL;
echo "Mojibake detected: {$mojibakeFound}" . PHP_EOL;
echo PHP_EOL . "Examples (before/after):" . PHP_EOL;
foreach ($examples as $ex) {
    echo "- #{$ex['id']} {$ex['name']} [{$ex['source']}]" . PHP_EOL;
    echo "  before: " . json_encode($ex['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "  after : " . json_encode($ex['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit(0);
