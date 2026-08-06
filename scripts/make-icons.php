<?php

foreach ([192, 512] as $size) {
    $im = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($im, 24, 24, 27);
    $fg = imagecolorallocate($im, 220, 38, 38);
    imagefill($im, 0, 0, $bg);

    $cx = (int) ($size / 2);
    imagefilledellipse($im, $cx, (int) ($size * 0.40), (int) ($size * 0.30), (int) ($size * 0.44), $fg);
    imagefilledrectangle($im, (int) ($cx - $size * 0.025), (int) ($size * 0.62), (int) ($cx + $size * 0.025), (int) ($size * 0.74), $fg);
    imagefilledrectangle($im, (int) ($cx - $size * 0.13), (int) ($size * 0.74), (int) ($cx + $size * 0.13), (int) ($size * 0.78), $fg);

    imagepng($im, __DIR__."/../public/icons/icon-{$size}.png");
}

echo "Icons generated.\n";
