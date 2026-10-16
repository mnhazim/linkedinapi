<?php

/**
 * Jana social card "I'm attending" (PNG 1200x627, nisbah yang LinkedIn suka) guna GD.
 * Dalam sistem sebenar, ganti dengan design/template conference kau sendiri.
 */
class SocialCard
{
    private const W = 1200;
    private const H = 627;

    public static function generate(array $attendee, array $event, string $outputPath): void
    {
        $fontRegular = __DIR__ . '/../assets/fonts/DejaVuSans.ttf';
        $fontBold    = __DIR__ . '/../assets/fonts/DejaVuSans-Bold.ttf';

        $img = imagecreatetruecolor(self::W, self::H);

        // Background gradient (navy -> ungu)
        for ($y = 0; $y < self::H; $y++) {
            $t = $y / self::H;
            $c = imagecolorallocate($img, (int) (15 + 45 * $t), (int) (23 + 10 * $t), (int) (66 + 60 * $t));
            imageline($img, 0, $y, self::W, $y, $c);
        }

        $white  = imagecolorallocate($img, 255, 255, 255);
        $muted  = imagecolorallocate($img, 190, 198, 230);
        $accent = imagecolorallocate($img, 255, 196, 0);

        // Bulatan hiasan
        imagefilledellipse($img, self::W - 60, 80, 360, 360, imagecolorallocatealpha($img, 255, 255, 255, 115));
        imagefilledellipse($img, self::W - 180, self::H - 20, 260, 260, imagecolorallocatealpha($img, 255, 196, 0, 112));

        $x = 80;

        // Nama event + badge
        imagettftext($img, 26, 0, $x, 110, $white, $fontBold, $event['name']);
        imagefilledrectangle($img, $x, 150, $x + 300, 196, $accent);
        imagettftext($img, 18, 0, $x + 22, 182, imagecolorallocate($img, 20, 20, 40), $fontBold, "I'M ATTENDING");

        // Nama attendee (auto kecilkan font kalau panjang)
        $name = $attendee['name'];
        $size = self::fitFontSize($name, $fontBold, 64, 32, self::W - 2 * $x);
        imagettftext($img, $size, 0, $x, 300, $white, $fontBold, $name);

        // Jawatan @ syarikat
        $role = trim($attendee['title'] . ($attendee['company'] !== '' ? ' @ ' . $attendee['company'] : ''));
        if ($role !== '') {
            $roleSize = self::fitFontSize($role, $fontRegular, 28, 18, self::W - 2 * $x);
            imagettftext($img, $roleSize, 0, $x, 360, $muted, $fontRegular, $role);
        }

        // Garisan + tarikh & venue
        imagefilledrectangle($img, $x, 440, $x + 80, 445, $accent);
        imagettftext($img, 22, 0, $x, 500, $white, $fontBold, $event['date']);
        imagettftext($img, 20, 0, $x, 540, $muted, $fontRegular, $event['venue']);

        imagepng($img, $outputPath);
        imagedestroy($img);
    }

    private static function fitFontSize(string $text, string $font, int $max, int $min, int $maxWidth): int
    {
        for ($size = $max; $size > $min; $size -= 2) {
            $box = imagettfbbox($size, 0, $font, $text);
            if ($box[2] - $box[0] <= $maxWidth) {
                return $size;
            }
        }
        return $min;
    }
}
