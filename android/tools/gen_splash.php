<?php
/**
 * Gera as imagens de abertura do app Android com a marca NWT.
 *  - splash_nwt.png → cartão branco com a logo NWT + "APR/PT" (fundo da janela)
 *  - nwt_marca.png  → selo branco com a logo (branding do splash do Android 12+)
 * Uso: php gen_splash.php
 */
$FONT_B = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$FONT_R = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$LOGO   = '/var/www/apr-pt/html/assets/img/nwt-logo.png';
$OUT    = '/var/www/apr-pt/android/app/src/main/res/drawable-xxhdpi';

if (!is_dir($OUT)) mkdir($OUT, 0755, true);
foreach ([$FONT_B, $FONT_R, $LOGO] as $f) {
    if (!is_file($f)) { fwrite(STDERR, "não encontrado: $f\n"); exit(1); }
}

$SS = 4; // supersampling (desenha 4x e reduz — bordas suaves)

function cor($im, $hex, $alpha = 0) {
    $hex = ltrim($hex, '#');
    return imagecolorallocatealpha($im,
        hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
}

function canvas($w, $h) {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagealphablending($im, true);
    return $im;
}

function roundedRect($im, $x1, $y1, $x2, $y2, $r, $cor) {
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $cor);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $cor);
    imagefilledellipse($im, $x1 + $r, $y1 + $r, 2 * $r, 2 * $r, $cor);
    imagefilledellipse($im, $x2 - $r, $y1 + $r, 2 * $r, 2 * $r, $cor);
    imagefilledellipse($im, $x1 + $r, $y2 - $r, 2 * $r, 2 * $r, $cor);
    imagefilledellipse($im, $x2 - $r, $y2 - $r, 2 * $r, 2 * $r, $cor);
}

function logo($dst, $x, $y, $largura) {
    global $LOGO;
    $src = imagecreatefrompng($LOGO);
    $sw = imagesx($src); $sh = imagesy($src);
    $altura = (int)round($sh * $largura / $sw);
    imagecopyresampled($dst, $src, (int)$x, (int)$y, 0, 0, (int)$largura, $altura, $sw, $sh);
    imagedestroy($src);
    return $altura;
}

function textoCentro($im, $size, $cx, $baseY, $cor, $fonte, $txt) {
    $bbox = imagettfbbox($size, 0, $fonte, $txt);
    $w = $bbox[2] - $bbox[0];
    imagettftext($im, $size, 0, (int)round($cx - $w / 2), (int)$baseY, $cor, $fonte, $txt);
}

function salvar($im, $w, $h, $SS, $destino) {
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, imagesx($im), imagesy($im));
    imagepng($out, $destino);
    imagedestroy($out);
    imagedestroy($im);
    printf("%-22s %dx%d — %.1f KB\n", basename($destino), $w, $h, filesize($destino) / 1024);
}

/* ─── 1) Cartão de abertura ─────────────────────────────── */
$W = 760; $H = 500;
$im = canvas($W * $SS, $H * $SS);
$branco = cor($im, '#ffffff');
$azul   = cor($im, '#0d3b66');
$cinza  = cor($im, '#4a5768');

roundedRect($im, 0, 0, $W * $SS - 1, $H * $SS - 1, 52 * $SS, $branco);

$larg = 250 * $SS;
$alt  = logo($im, ($W * $SS - $larg) / 2, 92 * $SS, $larg);
$fim  = 92 * $SS + $alt;

textoCentro($im, 74 * $SS, $W * $SS / 2, $fim + 86 * $SS, $azul,  $GLOBALS['FONT_B'], 'APR/PT');
textoCentro($im, 27 * $SS, $W * $SS / 2, $fim + 148 * $SS, $cinza, $GLOBALS['FONT_R'], 'Segurança do Trabalho · NR-10 e NR-35');

salvar($im, $W, $H, $SS, "$OUT/splash_nwt.png");

/* ─── 2) Selo NWT (Android 12+) ─────────────────────────── */
$W = 480; $H = 144;
$im = canvas($W * $SS, $H * $SS);
$branco = cor($im, '#ffffff');
roundedRect($im, 0, 0, $W * $SS - 1, $H * $SS - 1, 40 * $SS, $branco);

$larg = 290 * $SS;
$alt  = (int)round(54 * $larg / 150);
logo($im, ($W * $SS - $larg) / 2, ($H * $SS - $alt) / 2, $larg);

salvar($im, $W, $H, $SS, "$OUT/nwt_marca.png");
