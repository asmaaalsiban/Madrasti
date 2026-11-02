<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use Illuminate\Validation\ValidationException;

/**
 * Turns a photo or scan of a signature on paper into a transparent PNG: the paper colour is found from the edges of the
 * picture and removed, the ink keeps its colour (a blue pen stays blue), and the picture is cropped to the signature.
 * `strength` (0-100) is how much of the faint grey of the paper and the shadows is taken away: higher removes more.
 */
class SignatureImage
{
    public const MAX_SIDE = 900;   // the working size: bigger photos are shrunk first (faster, and plenty for print)
    public const OUT_WIDTH = 700;  // the stored signature is at most this wide

    /** @return string PNG bytes */
    public function process(string $bytes, int $strength = 50): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['image' => [__('signatures.no_gd')]]);
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw ValidationException::withMessages(['image' => [__('signatures.not_an_image')]]);
        }

        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1.0, self::MAX_SIDE / max($w, $h));
        if ($scale < 1.0) {
            $source = imagescale($source, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC) ?: $source;
            [$w, $h] = [imagesx($source), imagesy($source)];
        }

        $paper = $this->paper($source, $w, $h);
        $strength = max(0, min(100, $strength));
        $from = 8 + $strength * 0.7;      // darker than the paper by less than this: fully transparent
        $to = $from + 50;                 // darker by more than this: fully opaque

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));

        [$minX, $minY, $maxX, $maxY] = [$w, $h, -1, -1];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($source, $x, $y);
                [$r, $g, $b] = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
                $darker = max($paper[0] - $r, $paper[1] - $g, $paper[2] - $b); // how far the pixel is below the paper
                if ($darker <= $from) {
                    continue;
                }
                $opacity = $darker >= $to ? 1.0 : ($darker - $from) / ($to - $from);
                $opacity = $opacity * $opacity * (3 - 2 * $opacity); // smooth edges
                $gd = (int) round(127 * (1 - $opacity));
                imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $gd));
                if ($opacity > 0.25) {
                    [$minX, $maxX, $minY, $maxY] = [min($minX, $x), max($maxX, $x), min($minY, $y), max($maxY, $y)];
                }
            }
        }

        if ($maxX < 0) {
            throw ValidationException::withMessages(['image' => [__('signatures.nothing_found')]]);
        }

        // Crop to the ink with a small margin, and keep it to a sensible width.
        $pad = 6;
        $x0 = max(0, $minX - $pad);
        $y0 = max(0, $minY - $pad);
        $cropW = min($w, $maxX + $pad + 1) - $x0;
        $cropH = min($h, $maxY + $pad + 1) - $y0;
        $crop = imagecreatetruecolor($cropW, $cropH);
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        imagefill($crop, 0, 0, imagecolorallocatealpha($crop, 255, 255, 255, 127));
        imagecopy($crop, $out, 0, 0, $x0, $y0, $cropW, $cropH);
        if ($cropW > self::OUT_WIDTH) {
            $scaled = imagescale($crop, self::OUT_WIDTH, (int) round($cropH * self::OUT_WIDTH / $cropW), IMG_BICUBIC);
            if ($scaled !== false) {
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                $crop = $scaled;
            }
        }

        ob_start();
        imagepng($crop, null, 6);

        return (string) ob_get_clean();
    }

    /**
     * The colour of the paper: the average of the pixels along the edges of the picture (a signature does not reach the
     * edges), ignoring the darkest quarter in case a finger or a shadow got in.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function paper(\GdImage $image, int $w, int $h): array
    {
        $edge = [];
        $ring = max(1, (int) round(min($w, $h) * 0.03));
        $step = max(1, (int) floor(max($w, $h) / 120));
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                if ($x < $ring || $y < $ring || $x >= $w - $ring || $y >= $h - $ring) {
                    $rgb = imagecolorat($image, $x, $y);
                    $edge[] = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
                }
            }
        }
        usort($edge, fn ($a, $b) => array_sum($b) <=> array_sum($a)); // brightest first
        $edge = array_slice($edge, 0, max(1, (int) ceil(count($edge) * 0.75)));

        return [
            (int) round(array_sum(array_column($edge, 0)) / count($edge)),
            (int) round(array_sum(array_column($edge, 1)) / count($edge)),
            (int) round(array_sum(array_column($edge, 2)) / count($edge)),
        ];
    }
}
