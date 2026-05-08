<?php

declare(strict_types=1);

/**
 * Convierte priority e importance a enteros 1–10 (SQLite INTEGER + CHECK).
 *
 * php database/migrate_topics_numeric_1_10.php
 */
$base = dirname(__DIR__);
require $base . '/src/Bootstrap.php';
App\Bootstrap::registerAutoload($base);

use App\Support\TopicScales;

$db = $base . '/database/app.sqlite';
if (!file_exists($db)) {
    fwrite(STDERR, "No existe database/app.sqlite.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$createSql = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='topics'")->fetchColumn();
if (!is_string($createSql) || $createSql === '') {
    fwrite(STDERR, "No existe tabla topics.\n");
    exit(1);
}

if (strpos($createSql, 'BETWEEN 1 AND 10') !== false) {
    echo "OK: topics ya usa escala numérica 1–10.\n";
    exit(0);
}

$pdo->exec('PRAGMA foreign_keys=OFF');
$pdo->exec('BEGIN');

$topicRows = $pdo->query('SELECT * FROM topics')->fetchAll(PDO::FETCH_ASSOC);
$hasComments = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='comments'")->fetchColumn();
$commentRows = $hasComments ? $pdo->query('SELECT * FROM comments')->fetchAll(PDO::FETCH_ASSOC) : [];

if ($hasComments) {
    $pdo->exec('DROP TABLE comments');
}
$pdo->exec('DROP TABLE topics');

$pdo->exec(
    'CREATE TABLE topics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL REFERENCES teams(id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES users(id),
    person_id INTEGER REFERENCES team_people(id) ON DELETE SET NULL,
    title TEXT NOT NULL,
    body TEXT,
    priority INTEGER NOT NULL DEFAULT 5 CHECK (priority BETWEEN 1 AND 10),
    importance INTEGER NOT NULL DEFAULT 5 CHECK (importance BETWEEN 1 AND 10),
    status TEXT NOT NULL DEFAULT \'open\' CHECK (status IN (\'open\', \'in_progress\', \'blocked\', \'done\', \'archived\')),
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    updated_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
    completed_at TEXT
)'
);

$ins = $pdo->prepare(
    'INSERT INTO topics (id, team_id, author_id, person_id, title, body, priority, importance, status, created_at, updated_at, completed_at)
     VALUES (:id, :team_id, :author_id, :person_id, :title, :body, :priority, :importance, :status, :created_at, :updated_at, :completed_at)'
);

foreach ($topicRows as $r) {
    $ins->execute([
        'id' => (int) $r['id'],
        'team_id' => (int) $r['team_id'],
        'author_id' => (int) $r['author_id'],
        'person_id' => $r['person_id'] !== null && $r['person_id'] !== '' ? (int) $r['person_id'] : null,
        'title' => (string) $r['title'],
        'body' => isset($r['body']) ? $r['body'] : null,
        'priority' => TopicScales::normalizePriority($r['priority'] ?? TopicScales::DEFAULT),
        'importance' => TopicScales::normalizeImportance($r['importance'] ?? TopicScales::DEFAULT),
        'status' => (string) ($r['status'] ?? 'open'),
        'created_at' => (string) ($r['created_at'] ?? date('c')),
        'updated_at' => (string) ($r['updated_at'] ?? date('c')),
        'completed_at' => isset($r['completed_at']) && $r['completed_at'] !== '' ? (string) $r['completed_at'] : null,
    ]);
}

$pdo->exec(
    'CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_id INTEGER NOT NULL REFERENCES topics(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    body TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
)'
);

$ci = $pdo->prepare(
    'INSERT INTO comments (id, topic_id, user_id, body, created_at) VALUES (:id, :topic_id, :user_id, :body, :created_at)'
);
foreach ($commentRows as $c) {
    $ci->execute([
        'id' => (int) $c['id'],
        'topic_id' => (int) $c['topic_id'],
        'user_id' => (int) $c['user_id'],
        'body' => (string) $c['body'],
        'created_at' => (string) ($c['created_at'] ?? date('c')),
    ]);
}

$pdo->exec('CREATE INDEX IF NOT EXISTS idx_topics_team ON topics(team_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_topics_person ON topics(person_id)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_topics_priority ON topics(priority)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_topics_importance ON topics(importance)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_topics_status ON topics(status)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_topics_updated ON topics(updated_at DESC)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_topic ON comments(topic_id)');

$pdo->exec('COMMIT');
$pdo->exec('PRAGMA foreign_keys=ON');

echo "OK: urgencia e importancia pasadas a escala 1–10.\n";
