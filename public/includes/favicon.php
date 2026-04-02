<?php

declare(strict_types=1);

/**
 * Favicon embebido en data: para evitar caché agresiva por URL (/assets/favicon.svg, /favicon.ico).
 */
$svgAbs = __DIR__ . '/../assets/favicon.svg';
if (!is_readable($svgAbs)) {
    return;
}
$svg = file_get_contents($svgAbs);
if ($svg === false || $svg === '') {
    return;
}
$dataHref = 'data:image/svg+xml;base64,' . base64_encode($svg);

?>
<link rel="icon" href="<?= htmlspecialchars($dataHref, ENT_QUOTES, 'UTF-8') ?>" type="image/svg+xml">
<link rel="shortcut icon" href="<?= htmlspecialchars($dataHref, ENT_QUOTES, 'UTF-8') ?>">
