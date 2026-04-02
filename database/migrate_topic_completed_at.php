<?php

declare(strict_types=1);

/**
 * Añade topics.completed_at (fecha en que se marcó como realizado).
 * php database/migrate_topic_completed_at.php
 */
$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cols = $pdo->query('PRAGMA table_info(topics)')->fetchAll(PDO::FETCH_ASSOC);
$has = false;
foreach ($cols as $c) {
    if (($c['name'] ?? '') === 'completed_at') {
        $has = true;
        break;
    }
}
if (!$has) {
    $pdo->exec('ALTER TABLE topics ADD COLUMN completed_at TEXT');
}

$pdo->exec(
    "UPDATE topics SET completed_at = updated_at WHERE status = 'done' AND (completed_at IS NULL OR completed_at = '')"
);

echo "OK: topics.completed_at listo.\n";
