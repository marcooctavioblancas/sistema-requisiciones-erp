<?php
// Renderiza el código guardado en $_SESSION['captcha_code'] (generado por index.php)
// como imagen distorsionada -- solo LEE el código, nunca lo genera, para que la
// imagen mostrada siempre coincida con lo que index.php va a validar.
require_once 'includes/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Defensivo: si alguien pide esta imagen sin haber pasado por index.php primero
// (sesión sin código todavía), se genera uno aquí mismo para no romper el request.
if (empty($_SESSION['captcha_code'])) {
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $codigo = '';
    for ($i = 0; $i < 5; $i++) {
        $codigo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    $_SESSION['captcha_code'] = $codigo;
}
$codigo = $_SESSION['captcha_code'];

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$ancho = 140;
$alto  = 50;
$img = imagecreatetruecolor($ancho, $alto);

$bg = imagecolorallocate($img, 245, 247, 250);
imagefill($img, 0, 0, $bg);

// Cada carácter se dibuja en un lienzo pequeño aparte y se rota con imagerotate()
// -- da distorsión real por carácter sin depender de ninguna fuente TTF instalada
// en el contenedor (imagestring() usa las fuentes de mapa de bits propias de GD).
$x = 8;
foreach (str_split($codigo) as $letra) {
    $charImg = imagecreatetruecolor(22, 28);
    $charBg  = imagecolorallocate($charImg, 245, 247, 250);
    imagefill($charImg, 0, 0, $charBg);
    $color = imagecolorallocate($charImg, random_int(15, 90), random_int(15, 90), random_int(90, 160));
    imagestring($charImg, 5, 3, 5, $letra, $color);

    $angulo = random_int(-25, 25);
    $rotado = imagerotate($charImg, $angulo, $charBg);

    $w = imagesx($rotado);
    $h = imagesy($rotado);
    imagecopy($img, $rotado, $x, (int) (($alto - $h) / 2) + random_int(-3, 3), 0, 0, $w, $h);

    imagedestroy($charImg);
    imagedestroy($rotado);
    $x += 24;
}

// Líneas de ruido ENCIMA del texto -- cruzan las letras, no solo el fondo.
for ($i = 0; $i < 5; $i++) {
    $lineColor = imagecolorallocate($img, random_int(150, 200), random_int(150, 200), random_int(150, 200));
    imageline($img, random_int(0, $ancho), random_int(0, $alto), random_int(0, $ancho), random_int(0, $alto), $lineColor);
}
for ($i = 0; $i < 60; $i++) {
    $dotColor = imagecolorallocate($img, random_int(170, 210), random_int(170, 210), random_int(170, 210));
    imagesetpixel($img, random_int(0, $ancho - 1), random_int(0, $alto - 1), $dotColor);
}

imagepng($img);
imagedestroy($img);
