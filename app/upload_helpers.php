<?php

if (!defined('MENU_ITEM_IMAGE_MAX_BYTES')) {
    define('MENU_ITEM_IMAGE_MAX_BYTES', 10 * 1024 * 1024);
}


function ensure_storage_subdir(string $subdir): string
{

    $base = __DIR__ . '/../public_html/storage';

    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }

    $dir = $base . '/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}


function upload_menu_image(array $file): ?string
{
    if (empty($file['tmp_name']) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $size = isset($file['size']) ? (int)$file['size'] : (int)@filesize((string)$file['tmp_name']);
    if ($size <= 0 || $size > MENU_ITEM_IMAGE_MAX_BYTES) {
        return null;
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    $mime = @mime_content_type($file['tmp_name']);
    if (!isset($allowedMime[$mime])) {
        return null;
    }

    $ext = $allowedMime[$mime];


    $dir = ensure_storage_subdir('menu_images');

    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $fullPath = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        return null;
    }


    return 'menu_images/' . $name;
}
