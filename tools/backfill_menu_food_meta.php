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

    $filled = strFilled($origIngredients) || strFilled($origAllergens) || strFilled($origTags) || ($origWeight !== null && $origWeight > 0);
    $bad = looksMojibake($origIngredients) || looksMojibake($origAllergens) || looksMojibake($origTags);

    if ($bad) {
        $mojibakeFound++;
    }

    if ($filled && !$bad && !$force) {
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
echo "Skipped: {$skipped}" . PHP_EOL;
echo "Undetermined: {$undetermined}" . PHP_EOL;
echo "Mojibake detected: {$mojibakeFound}" . PHP_EOL;
echo PHP_EOL . "Examples (before/after):" . PHP_EOL;
foreach ($examples as $ex) {
    echo "- #{$ex['id']} {$ex['name']} [{$ex['source']}]" . PHP_EOL;
    echo "  before: " . json_encode($ex['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "  after : " . json_encode($ex['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

exit(0);

