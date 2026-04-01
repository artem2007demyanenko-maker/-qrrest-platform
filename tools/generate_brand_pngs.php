<?php
/**
 * One-off: render brand mark to PNG (GD). Run: php tools/generate_brand_pngs.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$out32 = $root . '/public_html/assets/brand/favicon-32.png';
$out180 = $root . '/public_html/assets/brand/apple-touch-icon.png';

function draw_mark(GdImage $im, int $size): void
{
    $bg1 = [15, 23, 42];
    $stroke = [71, 85, 105];
    $green = [52, 211, 153];
    $gray = [100, 116, 139];

    $c = imagecolorallocate($im, $bg1[0], $bg1[1], $bg1[2]);
    imagefilledrectangle($im, 0, 0, $size - 1, $size - 1, $c);

    $s = $size / 32.0;
    $rx = (int) round(8 * $s);
    $pad = max(1, (int) round(1.5 * $s));

    // Rounded card: filled inner + stroke frame (simplified corners)
    $inner = imagecolorallocate($im, 18, 28, 46);
    imagefilledrectangle($im, $pad, $pad, $size - 1 - $pad, $size - 1 - $pad, $inner);
    $st = imagecolorallocate($im, $stroke[0], $stroke[1], $stroke[2]);
    imagerectangle($im, $pad, $pad, $size - 1 - $pad, $size - 1 - $pad, $st);

    $g = imagecolorallocate($im, $green[0], $green[1], $green[2]);
    $gr = imagecolorallocate($im, $gray[0], $gray[1], $gray[2]);

    $fill = static function (GdImage $im, int $x, int $y, int $w, int $h, int $col): void {
        imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, $col);
    };

    $x = static fn (float $v) => (int) round($v * $s);
    $y = static fn (float $v) => (int) round($v * $s);

    // TL finder outer 8..17
    $fill($im, $x(8), $y(8), $x(9), $y(9), $g);
    $fill($im, $x(10.5), $y(10.5), $x(4), $y(4), $c);

    // TR
    $fill($im, $x(19), $y(8), $x(5), $y(5), $g);
    $fill($im, $x(21), $y(10), $x(1), $y(1), $c);
    $fill($im, $x(23), $y(10), $x(1), $y(1), $c);

    // BL
    $fill($im, $x(8), $y(19), $x(5), $y(5), $g);
    $fill($im, $x(10), $y(21), $x(1), $y(1), $c);
    $fill($im, $x(12), $y(21), $x(1), $y(1), $c);

    // Data modules (gray)
    $fill($im, $x(19), $y(19), $x(2), $y(2), $gr);
    $fill($im, $x(22), $y(19), $x(2), $y(2), $gr);
    $fill($im, $x(22), $y(22), $x(2), $y(2), $gr);
    $fill($im, $x(25), $y(19), $x(2), $y(2), $gr);
    $fill($im, $x(25), $y(22), $x(2), $y(2), $gr);
    $fill($im, $x(19), $y(23), $x(2), $y(2), $gr);
    $fill($im, $x(22), $y(23), $x(2), $y(2), $gr);

    // Plate + fork (bottom)
    $plate = imagecolorallocate($im, 51, 65, 85);
    $plateStroke = imagecolorallocate($im, 148, 163, 184);
    $fork = imagecolorallocate($im, 203, 213, 225);
    $cx = (int) round(16 * $s);
    $cy = (int) round(26 * $s);
    $rw = (int) round(7 * $s);
    $rh = max(1, (int) round(1.5 * $s));
    imagefilledellipse($im, $cx, $cy, $rw * 2, $rh * 2, $plate);
    imageellipse($im, $cx, (int) round(26.5 * $s), (int) round(18 * $s), (int) round(4.4 * $s), $plateStroke);
    for ($i = -1; $i <= 1; $i++) {
        $fx = $cx + (int) round((6 + $i * 1) * $s);
        imageline($im, $fx, (int) round(18 * $s), $fx, (int) round(24 * $s), $fork);
    }
}

$im32 = imagecreatetruecolor(32, 32);
imagesavealpha($im32, false);
draw_mark($im32, 32);
imagepng($im32, $out32, 9);

$im180 = imagecreatetruecolor(180, 180);
imagesavealpha($im180, false);
draw_mark($im180, 180);
imagepng($im180, $out180, 9);

copy($out32, $root . '/public_html/favicon.ico');
copy($out32, $root . '/public_html/favicon.png');

echo "Wrote $out32, $out180, public_html/favicon.ico, public_html/favicon.png\n";
