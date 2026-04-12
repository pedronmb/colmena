<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$db = $base . '/database/app.sqlite';
if (file_exists($db)) {
    unlink($db);
}
$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(file_get_contents($base . '/database/schema.sql'));

$pdo->exec(
    "INSERT INTO teams (name, description) VALUES ('Mi espacio — Usuario Demo', 'Espacio de trabajo personal')"
);
$demoTeamId = (int) $pdo->query('SELECT last_insert_rowid()')->fetchColumn();

$hash = password_hash('demo123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    'INSERT INTO users (email, display_name, password_hash, role, availability, personal_team_id)
     VALUES (:email, :name, :hash, :role, :avail, :ptid)'
);
$stmt->execute([
    'email' => 'demo@local.test',
    'name' => 'Usuario Demo',
    'hash' => $hash,
    'role' => 'admin',
    'avail' => 'available',
    'ptid' => $demoTeamId,
]);

$demoUserId = (int) $pdo->query('SELECT last_insert_rowid()')->fetchColumn();

$stmt = $pdo->prepare(
    'INSERT INTO team_members (team_id, user_id, role_in_team) VALUES (:tid, :uid, :role)'
);
$stmt->execute(['tid' => $demoTeamId, 'uid' => $demoUserId, 'role' => 'owner']);

$tp = $pdo->prepare(
    'INSERT INTO team_people (team_id, display_name, email, role) VALUES (:tid, :dn, :em, :rl)'
);
$tp->execute(['tid' => $demoTeamId, 'dn' => 'Ana García', 'em' => null, 'rl' => 'Desarrollo']);
$tp->execute(['tid' => $demoTeamId, 'dn' => 'Luis Pérez', 'em' => null, 'rl' => 'Diseño']);

echo "OK: database initialized at {$db}\n";
echo "Demo: demo@local.test / demo123\n";
