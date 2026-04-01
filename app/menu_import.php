<?php
/**
 * Menu import from CSV (v1).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema_guard.php';

/**
 * Parse UTF‑8 CSV file with headers:
 * category_name,item_name,price,description,available
 *
 * @return array{ok:bool,rows:array<int,array>,errors:array<int,string>}
 */
function menu_import_parse_csv(string $path): array
{
    $result = [
        'ok'     => false,
        'rows'   => [],
        'errors' => [],
    ];

    if (!is_readable($path)) {
        $result['errors'][] = 'Файл недоступен для чтения.';
        return $result;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        $result['errors'][] = 'Не удалось открыть файл.';
        return $result;
    }

    // Detect delimiter by first non-empty line
    $firstLine = '';
    $pos = ftell($handle);
    while (($line = fgets($handle)) !== false) {
        $trim = trim($line, " \t\r\n\0\x0B");
        if ($trim !== '') {
            $firstLine = $trim;
            break;
        }
    }
    if ($firstLine === '') {
        fclose($handle);
        $result['errors'][] = 'Файл пустой.';
        return $result;
    }
    $commaCount = substr_count($firstLine, ',');
    $semiCount  = substr_count($firstLine, ';');
    $delimiter  = $commaCount >= $semiCount ? ',' : ';';

    // Rewind back to start
    fseek($handle, $pos, SEEK_SET);

    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        $result['errors'][] = 'Не удалось прочитать заголовок CSV.';
        return $result;
    }
    $headers = array_map('trim', $headers);

    $required = ['category_name', 'item_name', 'price', 'description', 'available'];
    foreach ($required as $h) {
        if (!in_array($h, $headers, true)) {
            fclose($handle);
            $result['errors'][] = 'Отсутствует обязательный столбец: ' . $h;
            return $result;
        }
    }

    $map = array_flip($headers);
    $rowNum = 1; // 1 = header
    $rows = [];
    $errors = [];

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rowNum++;
        if ($row === [null] || $row === false) {
            continue;
        }
        // Normalize length
        if (count($row) < count($headers)) {
            $row = array_merge($row, array_fill(0, count($headers) - count($row), ''));
        }

        $category   = trim((string)($row[$map['category_name']] ?? ''));
        $itemName   = trim((string)($row[$map['item_name']] ?? ''));
        $priceRaw   = trim((string)($row[$map['price']] ?? ''));
        $desc       = trim((string)($row[$map['description']] ?? ''));
        $availableR = trim((string)($row[$map['available']] ?? ''));

        if ($category === '' && $itemName === '' && $priceRaw === '' && $desc === '' && $availableR === '') {
            continue;
        }

        $errParts = [];
        if ($category === '') {
            $errParts[] = 'category_name пустой';
        }
        if ($itemName === '') {
            $errParts[] = 'item_name пустой';
        }

        $priceNorm = str_replace(',', '.', $priceRaw);
        $priceVal  = is_numeric($priceNorm) ? (float)$priceNorm : 0.0;
        if ($priceVal <= 0) {
            $errParts[] = 'price должен быть > 0';
        }

        $availBool = null;
        if ($availableR === '') {
            $availBool = 1;
        } else {
            $vLower = strtolower($availableR);
            if (in_array($vLower, ['1', 'true', 'yes', 'y', 'да'], true)) {
                $availBool = 1;
            } elseif (in_array($vLower, ['0', 'false', 'no', 'n', 'нет'], true)) {
                $availBool = 0;
            } else {
                $errParts[] = 'available должен быть 1/0/true/false/yes/no';
            }
        }

        $rows[] = [
            'row_num'       => $rowNum,
            'category_name' => $category,
            'item_name'     => $itemName,
            'price'         => $priceVal,
            'description'   => $desc,
            'available'     => $availBool ?? 1,
            'raw_available' => $availableR,
            'parse_error'   => $errParts ? implode('; ', $errParts) : null,
        ];

        if ($errParts) {
            $errors[] = 'Строка ' . $rowNum . ': ' . implode('; ', $errParts);
        }
    }

    fclose($handle);

    $result['rows']   = $rows;
    $result['errors'] = $errors;
    $result['ok']     = empty($errors);

    return $result;
}

/**
 * Preview actions for each row.
 *
 * Options:
 *  - create_missing_categories (bool)
 *  - update_existing_items (bool)
 *
 * @return array<int,array{row:array,action:string,error:?string,category_id:?int,item_id:?int}>
 */
function menu_import_preview(int $restaurantId, array $rows, array $options): array
{
    $createCategories = !empty($options['create_missing_categories']);
    $updateExisting   = !empty($options['update_existing_items']);

    $pdo = db();
    $preview = [];

    // Preload existing categories/items by name for this restaurant
    $catStmt = $pdo->prepare("SELECT id, name FROM menu_categories WHERE restaurant_id = :rest");
    $catStmt->execute(['rest' => $restaurantId]);
    $categories = [];
    foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $categories[mb_strtolower(trim($c['name']))] = (int)$c['id'];
    }

    $itemStmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE restaurant_id = :rest");
    $itemStmt->execute(['rest' => $restaurantId]);
    $items = [];
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $items[mb_strtolower(trim($i['name']))] = (int)$i['id'];
    }

    foreach ($rows as $row) {
        $category = trim((string)($row['category_name'] ?? ''));
        $itemName = trim((string)($row['item_name'] ?? ''));
        $price    = (float)($row['price'] ?? 0);
        $err      = [];
        $action   = 'skip';
        $catId    = null;
        $itemId   = null;

        if (!empty($row['parse_error'])) {
            $err[] = $row['parse_error'];
        }
        if ($category === '') {
            $err[] = 'category_name пустой';
        }
        if ($itemName === '') {
            $err[] = 'item_name пустой';
        }
        if ($price <= 0) {
            $err[] = 'price должен быть > 0';
        }

        $catKey = mb_strtolower($category);
        if (isset($categories[$catKey])) {
            $catId = $categories[$catKey];
        } elseif (!$createCategories) {
            $err[] = 'Категория не существует и авто‑создание отключено.';
        }

        $itemKey = mb_strtolower($itemName);
        if (isset($items[$itemKey])) {
            $itemId = $items[$itemKey];
            $action = $updateExisting ? 'update' : 'skip';
        } else {
            $action = 'create';
        }

        if ($err) {
            $action = 'error';
        }

        $preview[] = [
            'row'         => $row,
            'action'      => $action,
            'error'       => $err ? implode('; ', $err) : null,
            'category_id' => $catId,
            'item_id'     => $itemId,
        ];
    }

    return $preview;
}

/**
 * Apply import in a transaction.
 *
 * Options:
 *  - create_missing_categories (bool)
 *  - update_existing_items (bool)
 *  - mark_missing_items_unavailable (bool)
 *
 * @return array{created_categories:int,created_items:int,updated_items:int,marked_unavailable:int,skipped:int,errors:array<int,string>}
 */
function menu_import_apply(int $restaurantId, array $rows, array $options): array
{
    $summary = [
        'created_categories'   => 0,
        'created_items'        => 0,
        'updated_items'        => 0,
        'marked_unavailable'   => 0,
        'skipped'              => 0,
        'errors'               => [],
    ];

    $createCategories = !empty($options['create_missing_categories']);
    $updateExisting   = !empty($options['update_existing_items']);
    $markMissingOff   = !empty($options['mark_missing_items_unavailable']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $catStmt = $pdo->prepare("SELECT id, name FROM menu_categories WHERE restaurant_id = :rest");
        $catStmt->execute(['rest' => $restaurantId]);
        $categories = [];
        foreach ($catStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $categories[mb_strtolower(trim($c['name']))] = (int)$c['id'];
        }

        $itemStmt = $pdo->prepare("SELECT id, name FROM menu_items WHERE restaurant_id = :rest");
        $itemStmt->execute(['rest' => $restaurantId]);
        $items = [];
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $items[mb_strtolower(trim($i['name']))] = (int)$i['id'];
        }

        $insertCat = $pdo->prepare("INSERT INTO menu_categories (restaurant_id, name, sort_order) VALUES (:rest, :name, :sort_order)");
        $insertItem = $pdo->prepare("
            INSERT INTO menu_items (restaurant_id, category_id, name, description, price, available)
            VALUES (:rest, :cat, :name, :desc, :price, :avail)
        ");
        $updateItem = $pdo->prepare("
            UPDATE menu_items
            SET category_id = :cat, description = :desc, price = :price, available = :avail
            WHERE id = :id AND restaurant_id = :rest
        ");

        $seenNames = [];

        $preview = menu_import_preview($restaurantId, $rows, $options);

        foreach ($preview as $entry) {
            $row    = $entry['row'];
            $action = $entry['action'];
            $err    = $entry['error'];

            $rowNum   = (int)($row['row_num'] ?? 0);
            $category = trim((string)($row['category_name'] ?? ''));
            $itemName = trim((string)($row['item_name'] ?? ''));
            $price    = (float)($row['price'] ?? 0);
            $desc     = (string)($row['description'] ?? '');
            $avail    = (int)($row['available'] ?? 1);

            if ($err !== null) {
                $summary['errors'][] = 'Строка ' . $rowNum . ': ' . $err;
                $summary['skipped']++;
                continue;
            }

            $nameKey = mb_strtolower($itemName);
            $seenNames[$nameKey] = true;

            // Ensure category id (may be created on the fly)
            $catKey = mb_strtolower($category);
            $catId = $categories[$catKey] ?? null;
            if ($catId === null) {
                if (!$createCategories) {
                    $summary['errors'][] = 'Строка ' . $rowNum . ': категория не существует и авто‑создание отключено.';
                    $summary['skipped']++;
                    continue;
                }
                $maxSort = 0;
                $stmtMax = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM menu_categories WHERE restaurant_id = :rest");
                $stmtMax->execute(['rest' => $restaurantId]);
                $maxSort = (int)$stmtMax->fetchColumn();
                $insertCat->execute([
                    'rest'       => $restaurantId,
                    'name'       => $category,
                    'sort_order' => $maxSort + 10,
                ]);
                $catId = (int)$pdo->lastInsertId();
                $categories[$catKey] = $catId;
                $summary['created_categories']++;
            }

            $itemId = $entry['item_id'] ?? null;

            if ($action === 'update' && $itemId) {
                $updateItem->execute([
                    'cat'   => $catId,
                    'desc'  => $desc,
                    'price' => $price,
                    'avail' => $avail ? 1 : 0,
                    'id'    => $itemId,
                    'rest'  => $restaurantId,
                ]);
                $summary['updated_items']++;
            } elseif ($action === 'create') {
                $insertItem->execute([
                    'rest'  => $restaurantId,
                    'cat'   => $catId,
                    'name'  => $itemName,
                    'desc'  => $desc,
                    'price' => $price,
                    'avail' => $avail ? 1 : 0,
                ]);
                $summary['created_items']++;
            } else {
                $summary['skipped']++;
            }
        }

        if ($markMissingOff) {
            if (!empty($seenNames)) {
                $placeholders = implode(',', array_fill(0, count($seenNames), '?'));
                $params = [ $restaurantId ];
                foreach (array_keys($seenNames) as $n) {
                    $params[] = $n;
                }
                $sql = "
                    UPDATE menu_items
                    SET available = 0
                    WHERE restaurant_id = ?
                      AND LOWER(TRIM(name)) NOT IN ($placeholders)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $summary['marked_unavailable'] = $stmt->rowCount();
            } else {
                $sql = "UPDATE menu_items SET available = 0 WHERE restaurant_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$restaurantId]);
                $summary['marked_unavailable'] = $stmt->rowCount();
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $summary['errors'][] = 'Ошибка при применении импорта: ' . $e->getMessage();
    }

    return $summary;
}

