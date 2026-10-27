<?php

namespace App\Support;

use Throwable;

/**
 * O imagine prea mare ca să ajungă direct la agent (read_file): micșorată la cel mult 2000 px pe latura lungă, ca JPEG, până încape sub limită.
 * Modelele oricum nu văd mai mult de atât; originalul rămâne neatins. null dacă nu se poate (format necunoscut, memorie, lipsă GD / Imagick).
 */
class AgentImage
{
    public const MAX_SIDE = 2000;

    /** @return array{data: string, width: int, height: int}|null */
    public static function shrink(string $path, int $maxBytes): ?array
    {
        $memory = ini_get('memory_limit');
        try {
            @ini_set('memory_limit', '768M');                                 // o captură de 50 MB decomprimată are nevoie de loc

            return class_exists(\Imagick::class) ? self::withImagick($path, $maxBytes) : self::withGd($path, $maxBytes);
        } catch (Throwable) {
            return null;
        } finally {
            @ini_set('memory_limit', $memory);
        }
    }

    private static function withImagick(string $path, int $maxBytes): ?array
    {
        $image = new \Imagick;
        $image->readImage($path.'[0]');                                       // doar primul cadru (GIF animat)
        $image->setImageBackgroundColor('white');
        $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);      // transparența devine alb
        $image->autoOrient();
        if (max($image->getImageWidth(), $image->getImageHeight()) > self::MAX_SIDE) {
            $image->thumbnailImage(self::MAX_SIDE, self::MAX_SIDE, true);
        }
        $image->setImageFormat('jpeg');
        $image->stripImage();
        foreach ([85, 70, 55] as $quality) {
            $image->setImageCompressionQuality($quality);
            $data = $image->getImageBlob();
            if (strlen($data) <= $maxBytes) {
                return ['data' => $data, 'width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            }
        }

        return null;
    }

    private static function withGd(string $path, int $maxBytes): ?array
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if (! $source) {
            return null;
        }
        [$w, $h] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
        $target = imagecreatetruecolor($nw, $nh);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
        foreach ([85, 70, 55] as $quality) {
            ob_start();
            imagejpeg($target, null, $quality);
            $data = (string) ob_get_clean();
            if (strlen($data) <= $maxBytes) {
                return ['data' => $data, 'width' => $nw, 'height' => $nh];
            }
        }

        return null;
    }
}
