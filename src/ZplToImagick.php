<?php

/**
 * Webcooking ZPL to Imagick
 *
 * Copyright (c) 2025 Vincent Enjalbert
 * Licensed under LGPL-3.0-or-later. See the LICENSE file for details.
 */

declare(strict_types=1);

namespace Webcooking\ZplToImage;

use Imagick;

/**
 * Class ZplToImagick
 *
 * Provides methods to rasterize ZPL -> SVG -> Imagick objects.
 */
class ZplToImagick
{
    use RsvgConvertTrait;
    /**
     * Rasterize an SVG string into an Imagick object.
     *
     * @param string $svgContent
     * @param int $width Target width in pixels
     * @param int $height Target height in pixels
     * @param int $dpi Resolution (DPI)
     * @return Imagick
     * @throws \RuntimeException
     */
    public static function fromSvg(string $svgContent, int $widthPixels, int $heightPixels, int $dpi): \Imagick
    {
        // Try rsvg-convert first if available (more reliable for complex SVGs)
        if (self::isRsvgConvertAvailable()) {
            $pngData = self::rsvgConvertToPng($svgContent, $widthPixels, $heightPixels);
            return self::createImagickFromPngData($pngData);
        }

        // Fallback to direct Imagick conversion
        $imagick = new \Imagick();

        // Set density for proper rendering
        $imagick->setResolution($dpi, $dpi);

        // Read SVG content
        $imagick->readImageBlob($svgContent);

        // Resize to exact dimensions if needed
        $imagick->resizeImage($widthPixels, $heightPixels, \Imagick::FILTER_LANCZOS, 1);

        // Flatten the image (remove transparency)
        $imagick->setImageBackgroundColor('white');
        return $imagick->flattenImages();
    }

    /**
     * Convert a ZPL string directly to an Imagick object.
     *
     * @param string $zpl
     * @param float $widthInches
     * @param float $heightInches
     * @param int $dpi
     * @param string $fontRenderer
     * @return Imagick
     */
    public static function convert(string $zpl, float $widthInches = 4.0, float $heightInches = 6.0, int $dpi = 300, string $fontRenderer = 'noto'): Imagick
    {
        $svg = ZplToSvg::convert($zpl, $widthInches, $heightInches, $dpi, $fontRenderer);
        $width = (int)round($widthInches * $dpi);
        $height = (int)round($heightInches * $dpi);
        return self::fromSvg($svg, $width, $height, $dpi);
    }
}
