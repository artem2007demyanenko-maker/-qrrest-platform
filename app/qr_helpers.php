<?php


function ensure_storage_dir(string $subdir): string
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


function generate_table_qr_image(array $restaurant, array $table): string
{

    require_once __DIR__ . '/lib/phpqrcode/qrlib.php';

    $config   = require __DIR__ . '/config.php';
    $domain   = $config['app']['main_domain'];
    $protocol = $config['app']['protocol'] ?? 'https';


    $url = $protocol . '://' . $restaurant['subdomain'] . '.' . $domain
        . '/qr.php?table_id=' . urlencode($table['id']);


    $dir = ensure_storage_dir('qr');

    $filename = 'rest_' . $restaurant['id'] . '_table_' . $table['id'] . '.png';
    $fullPath = $dir . '/' . $filename;


    QRcode::png($url, $fullPath, QR_ECLEVEL_M, 6);


    return 'qr/' . $filename;
}
