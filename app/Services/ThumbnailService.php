<?php

namespace App\Services;

/**
 * GD-based thumbnail generator. Cover-crop semantics: scale source to
 * cover target dimensions, crop excess from the centered short axis.
 * Always outputs JPEG regardless of source format (alpha lost for PNG).
 */
class ThumbnailService
{
    public const MAX_W   = 400;
    public const MAX_H   = 300;
    public const QUALITY = 85;

    /**
     * Generate a cover-cropped JPEG thumbnail from $srcPath, save to $destPath.
     * Creates parent directories if missing. Returns true on success.
     */
    public static function generate(
        string $srcPath,
        string $destPath,
        int $maxW = self::MAX_W,
        int $maxH = self::MAX_H,
        int $quality = self::QUALITY
    ): bool {
        if (! is_file($srcPath)) return false;

        $info = @getimagesize($srcPath);
        if (! $info) return false;
        [$srcW, $srcH, $type] = $info;
        if ($srcW <= 0 || $srcH <= 0) return false;

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($srcPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($srcPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
            default        => false,
        };
        if (! $src) return false;

        // Cover-crop math.
        $sourceRatio = $srcW / $srcH;
        $targetRatio = $maxW / $maxH;
        if ($sourceRatio > $targetRatio) {
            // Source wider than target → keep full height, crop sides.
            $srcCropH = $srcH;
            $srcCropW = (int) round($srcH * $targetRatio);
            $srcCropX = (int) round(($srcW - $srcCropW) / 2);
            $srcCropY = 0;
        } else {
            // Source taller than (or equal to) target → keep full width, crop top/bottom.
            $srcCropW = $srcW;
            $srcCropH = (int) round($srcW / $targetRatio);
            $srcCropX = 0;
            $srcCropY = (int) round(($srcH - $srcCropH) / 2);
        }

        $dst = imagecreatetruecolor($maxW, $maxH);
        // White background (no alpha channel in JPEG output).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $maxW, $maxH, $white);

        imagecopyresampled(
            $dst, $src,
            0, 0,
            $srcCropX, $srcCropY,
            $maxW, $maxH,
            $srcCropW, $srcCropH
        );

        $dir = dirname($destPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $ok = @imagejpeg($dst, $destPath, $quality);
        imagedestroy($src);
        imagedestroy($dst);
        if ($ok) {
            @chmod($destPath, 0644);
        }
        return (bool) $ok;
    }
}
