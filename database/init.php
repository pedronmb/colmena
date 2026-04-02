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

$hash = password_hash('demo123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    'INSERT INTO users (email, display_name, password_hash, role, availability)
     VALUES (:email, :name, :hash, :role, :avail)'
);
$stmt->execute([
    'email' => 'demo@local.test',
    'name' => 'Usuario Demo',
    'hash' => $hash,
    'role' => 'member',
    'avail' => 'available',
]);

$pdo->exec("INSERT INTO teams (name, description) VALUES ('Equipo Alpha', 'Equipo de demostración')");
$pdo->exec("INSERT INTO team_members (team_id, user_id, role_in_team) VALUES (1, 1, 'owner')");

$pdo->exec("INSERT INTO team_people (team_id, display_name, email, role) VALUES (1, 'Ana García', NULL, 'Desarrollo')");
$pdo->exec("INSERT INTO team_people (team_id, display_name, email, role) VALUES (1, 'Luis Pérez', NULL, 'Diseño')");

echo "OK: database initialized at {$db}\n";
echo "Demo: demo@local.test / demo123\n";
