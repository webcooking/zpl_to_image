<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Webcooking\ZplToImage\ZplToImage;
use Webcooking\ZplToImage\ZplToSvg;

// Couleurs pour le terminal
$colors = [
    'green' => "\033[32m",
    'blue' => "\033[34m",
    'yellow' => "\033[33m",
    'red' => "\033[31m",
    'reset' => "\033[0m",
];

function colorize($text, $color, $colors)
{
    return $colors[$color] . $text . $colors['reset'];
}

// Choix du renderer de font
$fontRenderer = $argv[1] ?? 'noto';

echo colorize("\n🎨 ZPL to SVG Converter - Test Suite\n", 'blue', $colors);
echo colorize(str_repeat("=", 50) . "\n", 'blue', $colors);
echo colorize("Font: ", 'yellow', $colors) . $fontRenderer . "\n\n";

// Trouver tous les fichiers ZPL dans le dossier examples
$zplFiles = glob(__DIR__ . '/*.zpl');

if (empty($zplFiles)) {
    echo colorize("❌ Aucun fichier ZPL trouvé dans le dossier examples/\n", 'red', $colors);
    exit(1);
}

$successCount = 0;
$errorCount = 0;

foreach ($zplFiles as $zplFile) {
    $filename = basename($zplFile, '.zpl');
    echo colorize("📄 Test: ", 'yellow', $colors) . $filename . "\n";

    try {
        // Lecture du fichier ZPL
        $zplContent = file_get_contents($zplFile);

        if (empty($zplContent)) {
            throw new Exception("Fichier vide");
        }

        // Conversion en SVG
        $svgContent = ZplToSvg::convert($zplContent, 4.0, 6.0, 300, $fontRenderer);

        // Nom du fichier de sortie SVG
        $outputFile = __DIR__ . '/output_' . $filename . '.svg';
        // Sauvegarde du SVG
        file_put_contents($outputFile, $svgContent);
        $fileSize = number_format(strlen($svgContent) / 1024, 2);
        echo colorize("   ✅ Success", 'green', $colors) . " - {$fileSize} KB - " . basename($outputFile) . "\n";

        // Try to generate JPEG via ZplToImage::toJpeg()
        $jpgPath = __DIR__ . '/output_' . $filename . '.jpg';
        try {
            ZplToImage::toJpeg($zplContent, $jpgPath, 4.0, 6.0, 300, $fontRenderer, 90);
            $jpgSize = number_format(filesize($jpgPath) / 1024, 2);
            echo colorize("   ✅ JPEG generated", 'green', $colors) . " - {$jpgSize} KB - " . basename($jpgPath) . "\n";
        } catch (Throwable $e) {
            echo colorize("   ⚠️ JPEG not generated", 'yellow', $colors) . " - " . $e->getMessage() . "\n";
        }
        $successCount++;
    } catch (Exception $e) {
        echo colorize("   ❌ Erreur: ", 'red', $colors) . $e->getMessage() . "\n";
        $errorCount++;
    }

    echo "\n";
}

// Summary
echo colorize(str_repeat("=", 50) . "\n", 'blue', $colors);
echo colorize("📊 Summary:\n", 'blue', $colors);
echo colorize("   ✅ Successful: ", 'green', $colors) . $successCount . "\n";
if ($errorCount > 0) {
    echo colorize("   ❌ Failed: ", 'red', $colors) . $errorCount . "\n";
}
echo colorize("\n🎯 Files generated in: ", 'blue', $colors) . "examples/output_*.svg\n";
echo colorize("\n🎯 Files generated in: ", 'blue', $colors) . "examples/output_*.svg and examples/output_*.jpg (if Imagick available)\n";
// Instructions
echo colorize("\n💡 To test with other fonts:\n", 'yellow', $colors);
echo "   php examples/test_all.php           # Noto Sans (default)\n";
echo "   php examples/test_all.php noto      # Noto Sans (modern)\n";
echo "   php examples/test_all.php ibm-vga   # IBM VGA 8x16 (retro)\n";
echo "   php examples/test_all.php /path.ttf # Your custom font\n\n";
