<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Services\WidgetSummaryService;

$root = dirname(__DIR__);
require $root . '/src/Bootstrap.php';
Bootstrap::registerAutoload($root);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents($root . '/database/schema.sql'));
$pdo->exec(<<<'SQL'
    INSERT INTO teams (id, name) VALUES (1, 'Equipo A'), (2, 'Equipo B');
    INSERT INTO users (id, email, display_name, password_hash) VALUES (1, 'widget@local.test', 'Widget', 'no-login');
    INSERT INTO team_people (id, team_id, display_name, email) VALUES (1, 1, 'Ada', 'ada@local.test'), (2, 2, 'Grace', 'grace@local.test');
    INSERT INTO team_alerts (team_id, author_id, title, due_date) VALUES
      (1, 1, 'Ayer', '2026-11-18'), (1, 1, 'Hoy', '2026-11-19'), (1, 1, 'Mañana', '2026-11-20'),
      (1, 1, 'Tercera', '2026-11-22'), (1, 1, 'Último día incluido', '2026-11-25'),
      (1, 1, 'Fuera del rango', '2026-11-26'), (2, 1, 'Alerta de B', '2026-11-19');
    INSERT INTO topics (team_id, author_id, title, priority, importance, status) VALUES
      (1, 1, 'En el umbral', 8, 8, 'open'), (1, 1, 'Primero', 10, 10, 'blocked'),
      (1, 1, 'Segundo', 10, 9, 'in_progress'), (1, 1, 'Tercero', 9, 8, 'open'),
      (1, 1, 'Poco urgente', 7, 10, 'open'), (1, 1, 'Poco importante', 10, 7, 'open'),
      (1, 1, 'Completado', 10, 10, 'done'), (1, 1, 'Archivado', 10, 10, 'archived'),
      (2, 1, 'Tema de B', 10, 10, 'open');
    INSERT INTO invgate_tickets (person_id, invgate_incident_id, title, status_id, created_at, last_update) VALUES
      (1, 101, 'Abierto', 1, '0', '0'), (1, 102, 'Estado sin informar', NULL, '0', '0'),
      (1, 103, 'Cerrado', 5, '0', '0'), (NULL, 104, 'Huérfano', 1, '0', '0'), (2, 105, 'De B', 1, '0', '0');
    INSERT INTO azure_work_items (person_id, azure_id, title, state, assigned_unique_name, removed_at) VALUES
      (1, 201, 'Activo de A', 'Active', NULL, NULL), (NULL, 202, 'Por email', 'New', 'ADA@local.test', NULL),
      (1, 203, 'Final', 'Done', NULL, NULL), (1, 204, 'Eliminado', 'Active', NULL, '2026-11-18'),
      (NULL, 205, 'Sin asignar', 'Active', NULL, NULL), (2, 206, 'Activo de B', 'Active', NULL, NULL);
    SQL);

$now = new DateTimeImmutable('2026-11-19 23:50:00-03:00');
$service = new WidgetSummaryService($pdo);
$teamA = $service->forTeam(1, $now, []);
$teamB = $service->forTeam(2, $now, []);

$checks = [
    'Las cuatro alertas del rango cuentan y se muestran las tres más cercanas' =>
        $teamA['alerts']['total'] === 4 && array_column($teamA['alerts']['items'], 'title') === ['Hoy', 'Mañana', 'Tercera'],
    'El umbral, el estado y el orden de temas críticos se respetan' =>
        $teamA['critical_topics']['total'] === 4 && array_column($teamA['critical_topics']['items'], 'title') === ['Primero', 'Segundo', 'Tercero'],
    'Se cuentan solo las asignaciones activas de A' => $teamA['invgate_open'] === 2 && $teamA['devops_active'] === 2,
    'El equipo B recibe sus propios títulos y conteos' => $teamB['invgate_open'] === 1 && $teamB['devops_active'] === 1
        && array_column($teamB['alerts']['items'], 'title') === ['Alerta de B']
        && array_column($teamB['critical_topics']['items'], 'title') === ['Tema de B'],
    'DevOps admite estados finales configurados' => $service->forTeam(1, $now, [
        'azure_devops' => ['final_states' => ['Done', 'New']],
    ])['devops_active'] === 1,
];

$pdo->exec('DELETE FROM invgate_tickets; DELETE FROM azure_work_items;');
$empty = $service->forTeam(1, $now, [
    'invgate' => ['server_url' => 'https://example.test', 'user' => 'fixture', 'password' => 'fixture'],
    'azure_devops' => ['organization' => 'fixture', 'project' => 'fixture', 'pat' => 'fixture'],
]);
$checks['Una integración configurada con cero resultados devuelve cero'] = $empty['invgate_open'] === 0 && $empty['devops_active'] === 0;
$pdo->exec('DROP TABLE invgate_tickets; DROP TABLE azure_work_items;');
$unavailable = $service->forTeam(1, $now, []);
$checks['Una integración sin tabla figura como no disponible'] = $unavailable['invgate_open'] === null && $unavailable['devops_active'] === null;

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException($label);
    }
    echo 'OK ' . $label . PHP_EOL;
}
