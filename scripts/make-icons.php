<?php

// Brand icon: white outline mic (matching the in-app SVG icon) on the
// signal-red gradient tile. Colors must stay in sync with
// public/css/app.css (--accent-hi/--accent-lo) and public/manifest.json.
// Drawn at 4x and downsampled because GD has no antialiasing.

$scale = 4;
$top = [240, 86, 92];      // #f0565c
$bottom = [201, 47, 54];   // #c92f36

// Stroke simulation: GD's thick lines/arcs render badly, so strokes are
// laid down as overlapping filled circles along the path.
function strokeDot($im, float $x, float $y, float $t, int $col): void
{
    imagefilledellipse($im, (int) round($x), (int) round($y), (int) round($t), (int) round($t), $col);
}

function strokeLine($im, float $x1, float $y1, float $x2, float $y2, float $t, int $col): void
{
    $steps = max(1, (int) ceil(hypot($x2 - $x1, $y2 - $y1) / ($t / 4)));
    for ($i = 0; $i <= $steps; $i++) {
        $f = $i / $steps;
        strokeDot($im, $x1 + ($x2 - $x1) * $f, $y1 + ($y2 - $y1) * $f, $t, $col);
    }
}

function strokeArc($im, float $cx, float $cy, float $r, float $a1, float $a2, float $t, int $col): void
{
    $steps = max(1, (int) ceil(deg2rad(abs($a2 - $a1)) * $r / ($t / 4)));
    for ($i = 0; $i <= $steps; $i++) {
        $a = deg2rad($a1 + ($a2 - $a1) * $i / $steps);
        strokeDot($im, $cx + $r * cos($a), $cy + $r * sin($a), $t, $col);
    }
}

foreach ([192, 512] as $size) {
    $s = $size * $scale;
    $im = imagecreatetruecolor($s, $s);

    // vertical gradient background
    for ($y = 0; $y < $s; $y++) {
        $f = $y / ($s - 1);
        $c = imagecolorallocate(
            $im,
            (int) round($top[0] + ($bottom[0] - $top[0]) * $f),
            (int) round($top[1] + ($bottom[1] - $top[1]) * $f),
            (int) round($top[2] + ($bottom[2] - $top[2]) * $f),
        );
        imageline($im, 0, $y, $s - 1, $y, $c);
    }

    $fg = imagecolorallocate($im, 255, 255, 255);
    $cx = $s / 2;
    $t = $s * 0.055; // stroke thickness

    // solid capsule (pill): caps + connecting rect
    $capW = $s * 0.24;
    $capR = $capW / 2;
    $capTopY = $s * 0.30;
    $capBotY = $s * 0.44;
    imagefilledellipse($im, (int) $cx, (int) $capTopY, (int) $capW, (int) $capW, $fg);
    imagefilledellipse($im, (int) $cx, (int) $capBotY, (int) $capW, (int) $capW, $fg);
    imagefilledrectangle($im, (int) ($cx - $capR), (int) $capTopY, (int) ($cx + $capR), (int) $capBotY, $fg);

    // cradle arc around the capsule (bottom half circle)
    $arcR = $s * 0.21;
    $arcCy = $s * 0.44;
    strokeArc($im, $cx, $arcCy, $arcR, 0, 180, $t, $fg);

    // stem and base
    strokeLine($im, $cx, $arcCy + $arcR, $cx, $s * 0.76, $t, $fg);
    strokeLine($im, $cx - $s * 0.13, $s * 0.76, $cx + $s * 0.13, $s * 0.76, $t, $fg);

    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $s, $s);
    imagepng($out, __DIR__."/../public/icons/icon-{$size}.png");
    imagedestroy($im);
    imagedestroy($out);
}

echo "Icons generated.\n";
