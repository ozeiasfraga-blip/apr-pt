<?php
/**
 * Gera os ícones do Android a partir da logo NWT.
 *
 * Uso: php android/tools/gera-icones.php
 *
 * - ic_launcher_foreground.png (adaptive, Android 8+): fundo transparente,
 *   logo NWT centralizada (~62% da largura, dentro da zona segura).
 * - ic_maskable.png: mesma logo sobre fundo branco (compatibilidade).
 * - ic_launcher.png (legacy, pré-Android 8): quadrado branco de cantos
 *   arredondados com a logo centralizada (~72%).
 */

$raiz = dirname(__DIR__, 2); // raiz do projeto (android/tools/../..)
$logo = imagecreatefrompng("$raiz/html/assets/img/nwt-logo.png");
$lw = imagesx($logo);
$lh = imagesy($logo);
echo "logo: {$lw}x{$lh}\n";

function canvasTransparente(int $size)
{
    $img = imagecreatetruecolor($size, $size);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transp = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transp);
    return $img;
}

function colaLogo($canvas, $logo, int $lw, int $lh, int $size, float $fracao)
{
    $tw = (int)round($size * $fracao);
    $th = (int)round($tw * $lh / $lw);
    $x = (int)round(($size - $tw) / 2);
    $y = (int)round(($size - $th) / 2);
    imagealphablending($canvas, false); // copia o canal alpha do PNG
    imagecopyresampled($canvas, $logo, $x, $y, 0, 0, $tw, $th, $lw, $lh);
}

function retanguloArredondado($img, int $size, int $raio, $cor)
{
    $r = $raio;
    imagefilledrectangle($img, $r, 0, $size - $r - 1, $size - 1, $cor);
    imagefilledrectangle($img, 0, $r, $size - 1, $size - $r - 1, $cor);
    imagefilledellipse($img, $r, $r, 2 * $r, 2 * $r, $cor);
    imagefilledellipse($img, $size - $r - 1, $r, 2 * $r, 2 * $r, $cor);
    imagefilledellipse($img, $r, $size - $r - 1, 2 * $r, 2 * $r, $cor);
    imagefilledellipse($img, $size - $r - 1, $size - $r - 1, 2 * $r, 2 * $r, $cor);
}

// ── 1. Foreground do ícone adaptativo (108dp base) ──────────
$fore = ['mdpi' => 108, 'hdpi' => 162, 'xhdpi' => 216, 'xxhdpi' => 324, 'xxxhdpi' => 432];
foreach ($fore as $d => $size) {
    $c = canvasTransparente($size);
    colaLogo($c, $logo, $lw, $lh, $size, 0.62);
    imagepng($c, "$raiz/android/app/src/main/res/mipmap-$d/ic_launcher_foreground.png", 9);
    imagedestroy($c);
    echo "foreground $d ({$size}px) ok\n";
}

// ── 1b. Maskable (mesmo visual, mantido por compatibilidade) ─
foreach ($fore as $d => $size) {
    $c = canvasTransparente($size);
    $branco = imagecolorallocate($c, 255, 255, 255);
    imagealphablending($c, true);
    imagefilledrectangle($c, 0, 0, $size - 1, $size - 1, $branco);
    imagealphablending($c, false);
    colaLogo($c, $logo, $lw, $lh, $size, 0.55);
    imagesavealpha($c, true);
    imagepng($c, "$raiz/android/app/src/main/res/mipmap-$d/ic_maskable.png", 9);
    imagedestroy($c);
    echo "maskable $d ({$size}px) ok\n";
}

// ── 2. Ícone legacy (pré-Android 8) ─────────────────────────
$legacy = ['mdpi' => 48, 'hdpi' => 72, 'xhdpi' => 96, 'xxhdpi' => 144, 'xxxhdpi' => 192];
foreach ($legacy as $d => $size) {
    $c = canvasTransparente($size);
    $branco = imagecolorallocate($c, 255, 255, 255);
    imagealphablending($c, true);
    retanguloArredondado($c, $size, (int)round($size * 0.22), $branco);
    imagealphablending($c, false);
    colaLogo($c, $logo, $lw, $lh, $size, 0.72);
    imagesavealpha($c, true);
    imagepng($c, "$raiz/android/app/src/main/res/mipmap-$d/ic_launcher.png", 9);
    imagedestroy($c);
    echo "legacy $d ({$size}px) ok\n";
}

echo "pronto\n";
