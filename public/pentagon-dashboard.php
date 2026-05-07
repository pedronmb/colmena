<?php

declare(strict_types=1);

/**
 * Compatibilidad: el radar por persona vive en dashboard.php (pestaña Perfiles).
 */
header('Location: dashboard.php?panel=pentagon', true, 302);
exit;
