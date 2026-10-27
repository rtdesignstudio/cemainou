<?php

// Scrie SVG-urile Carbon sub numele Heroicons (resources/icons/carbon.php), pentru toate mărimile pe care le cere Filament (o, s, m, c).
// Rulat după ce se schimbă lista: php scripts/carbon-icons.php (are nevoie de codeat3/blade-carbon-icons, din require-dev).

$root = dirname(__DIR__);
$source = $root.'/vendor/codeat3/blade-carbon-icons/resources/svg';
$target = $root.'/resources/svg/heroicons-carbon';
@mkdir($target, 0755, true);
array_map('unlink', glob($target.'/*.svg'));
$missing = [];
foreach (require $root.'/resources/icons/carbon.php' as $hero => $carbon) {
    $svg = @file_get_contents("{$source}/{$carbon}.svg");
    if ($svg === false) {
        $missing[] = "{$hero} → {$carbon}";

        continue;
    }
    $svg = preg_replace(['/<title>.*?<\/title>/s', '/<defs\s*\/>/', '/\s+data-name="[^"]*"/'], '', $svg);
    foreach (['o', 's', 'm', 'c'] as $size) {
        file_put_contents("{$target}/{$size}-{$hero}.svg", trim($svg)."\n");
    }
}
// desenate de noi, în același spirit (unghiuri drepte, ca săgeata din logo): înlocuiesc Carbon-ul pentru aceste nume
foreach (['bars-2', 'bars-3', 'bars-3-bottom-left'] as $hero) {
    foreach (['o', 's', 'm', 'c'] as $size) {
        copy($root.'/resources/icons/custom/menu.svg', "{$target}/{$size}-{$hero}.svg");
    }
}
copy($root.'/vendor/codeat3/blade-carbon-icons/LICENSE.md', $target.'/LICENSE.md');
copy($root.'/resources/icons/APACHE-2.0.txt', $target.'/LICENSE-carbon.txt');   // Carbon (IBM) e Apache 2.0: textul licenței merge cu iconițele
echo count(glob($target.'/*.svg'))." files in resources/svg/heroicons-carbon\n";
if ($missing) {
    fwrite(STDERR, 'Missing in Carbon: '.implode(', ', $missing)."\n");
    exit(1);
}
