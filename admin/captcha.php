<?php
/* Draws the "type the letters" picture for the sign-in page. The answer is kept only in the session. */
require __DIR__ . '/../includes/bootstrap.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$code = captcha_new();
$w = 180; $h = 56;
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 255, 248, 235));
for ($i = 0; $i < 6; $i++) {
    imageline($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), imagecolorallocate($im, random_int(150, 220), random_int(150, 220), random_int(150, 220)));
}
for ($i = 0; $i < 250; $i++) imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($im, random_int(120, 200), random_int(120, 200), random_int(120, 200)));
// Built-in font, enlarged per letter so it works on any host without font files
foreach (str_split($code) as $i => $ch) {
    $l = imagecreatetruecolor(12, 16);
    imagefill($l, 0, 0, imagecolorallocate($l, 255, 248, 235));
    imagecolortransparent($l, imagecolorallocate($l, 255, 248, 235));
    imagestring($l, 5, 2, 0, $ch, imagecolorallocate($l, random_int(20, 90), random_int(20, 60), random_int(0, 40)));
    $sz = random_int(30, 38);
    imagecopyresampled($im, $l, 12 + $i * 32 + random_int(-3, 3), random_int(2, 14), 0, 0, $sz - 8, $sz, 12, 16);
    imagedestroy($l);
}
imageline($im, 0, random_int(18, 38), $w, random_int(18, 38), imagecolorallocate($im, 120, 70, 20));
header('Content-Type: image/png');
imagepng($im);
imagedestroy($im);
