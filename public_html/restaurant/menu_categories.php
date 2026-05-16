<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['action'] ?? '') === 'save_sort') {
        $_POST['action'] = 'save_category_sort';
    }

    require __DIR__ . '/menu_manage.php';
    exit;
}

header('Location: /restaurant/menu_manage.php#categories', true, 302);
exit;
