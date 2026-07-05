<?php

declare(strict_types=1);

$dir = dirname(__DIR__) . '/public/assets/icons';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension required for icons.\n");
    exit(1);
}

foreach ([192, 512] as $size) {
    $img = imagecreatetruecolor($size, $size);
    $blue = imagecolorallocate($img, 29, 78, 216);
    $white = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 0, 0, $size, $size, $blue);
    $font = 5;
    $text = 'WH';
    $tw = imagefontwidth($font) * strlen($text);
    $th = imagefontheight($font);
    imagestring($img, $font, (int) (($size - $tw) / 2), (int) (($size - $th) / 2), $text, $white);
    imagepng($img, $dir . '/icon-' . $size . '.png');
    imagedestroy($img);
    echo "Created icon-{$size}.png\n";
}
