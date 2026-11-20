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
    INSERT INTO team_people (id, team_id, display_name, invgate_id, is_direct_team) VALUES
      (1, 1, 'Ada', 101, 1), (2, 2, 'Grace', 202, 1), (3, 1, 'Sin tickets', 303, 1),
      (4, 1, 'Sin ID', NULL, 1), (5, 1, 'Zoe', 505, 0);
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
    UPDATE topics SET person_id = 1 WHERE title = 'Primero';
    INSERT INTO invgate_tickets (person_id, invgate_incident_id, title, status_id, created_at, last_update) VALUES
      (1, 101, 'Comentario reciente', 1, '0', '2026-11-10T00:00:00Z'),
      (1, 102, 'Estado sin informar', NULL, '0', strftime('%s','2026-11-18T12:00:00Z') || '000'),
      (1, 103, 'Cerrado', 5, '0', '2026-11-20T00:00:00Z'),
      (NULL, 104, 'Huérfano', 1, '0', '2026-11-20T00:00:00Z'),
      (2, 105, 'De B', 1, '0', '2026-11-20T00:00:00Z'),
      (1, 106, 'Una actualización', 1, '0', '2026-11-17T12:00:00Z'),
      (1, 107, 'Otro comentario', 1, '0', '2026-11-16T00:00:00Z'),
      (1, 108, 'Primera prioridad', 1, '0', '2026-11-15T00:00:00Z'),
      (1, 109, 'Actividad antigua', 1, '0', '2026-11-14T00:00:00Z'),
      (1, 110, 'Sin actividad', 1, '0', '0'), (5, 111, 'De Zoe', 1, '0', '2026-11-13T00:00:00Z');
    INSERT INTO invgate_ticket_comments (incident_id, message, created_at, msg_num)
      SELECT id, 'Comentario', '2026-11-19T14:00:00Z', 1 FROM invgate_tickets WHERE invgate_incident_id = 101;
    INSERT INTO invgate_ticket_comments (incident_id, message, created_at, msg_num)
      SELECT id, 'Comentario', '2026-11-17T09:00:00-03:00', 1 FROM invgate_tickets WHERE invgate_incident_id = 107;
    INSERT INTO invgate_ticket_priorities (ticket_id, priority)
      SELECT id, 1 FROM invgate_tickets WHERE invgate_incident_id = 108;
    INSERT INTO invgate_ticket_priorities (ticket_id, priority)
      SELECT id, 2 FROM invgate_tickets WHERE invgate_incident_id = 102;
    UPDATE invgate_tickets SET priority = 1 WHERE invgate_incident_id = 107;
    SQL);

$now = new DateTimeImmutable('2026-11-19 23:50:00-03:00');
$service = new WidgetSummaryService($pdo);
$teamA = $service->forTeam(1, $now, []);
$teamB = $service->forTeam(2, $now, []);
$invgate = $service->forTeam(1, $now, [], 'invgate');
$people = $service->forTeam(1, $now, [], 'invgate-people');
$topics = $service->forTeam(1, $now, [], 'topics');

$checks = [
    'Las cuatro alertas del rango cuentan y se muestran las tres más cercanas' =>
        $teamA['alerts']['total'] === 4 && array_column($teamA['alerts']['items'], 'title') === ['Hoy', 'Mañana', 'Tercera'],
    'El umbral, el estado y el orden de temas críticos se respetan' =>
        $teamA['critical_topics']['total'] === 4 && array_column($teamA['critical_topics']['items'], 'title') === ['Primero', 'Segundo', 'Tercero'],
    'Se cuentan solo los tickets asignados de A y el resumen omite DevOps' =>
        $teamA['invgate_open'] === 8 && $teamA['topics_active'] === 6 && !array_key_exists('devops_active', $teamA),
    'El equipo B recibe sus propios títulos y conteos' => $teamB['invgate_open'] === 1 && $teamB['topics_active'] === 1
        && array_column($teamB['alerts']['items'], 'title') === ['Alerta de B']
        && array_column($teamB['critical_topics']['items'], 'title') === ['Tema de B'],
    'InvGate muestra solo cinco tickets abiertos ordenados por la última actualización o comentario' =>
        array_column($invgate['detail'], 'number') === [101, 102, 107, 106, 108]
        && $invgate['detail'][0]['person'] === 'Ada'
        && $invgate['detail'][0]['interaction_at'] === '2026-11-19T14:00:00+00:00',
    'El directorio muestra solo el equipo directo, incluidas las personas sin tickets' =>
        array_column($people['detail'], 'name') === ['Ada', 'Sin ID', 'Sin tickets']
        && $people['detail'][1]['tickets'] === [] && $people['detail'][2]['tickets'] === [],
    'La pantalla por persona mantiene el contador general de InvGate' => $people['invgate_open'] === $teamA['invgate_open'],
    'Las prioridades manuales ordenan los tickets y conservan los que no tienen valor' =>
        array_column($people['detail'][0]['tickets'], 'number') === [108, 102, 101, 106, 107, 109, 110]
        && array_column($people['detail'][0]['tickets'], 'manual_priority') === [1, 2, null, null, null, null, null],
    'Temas incluye todos los activos con datos de estado y responsable' => count($topics['detail']) === 6
        && $topics['detail'][0]['person'] === 'Ada' && $topics['detail'][0]['status'] === 'blocked'
        && $topics['detail'][0]['critical'] === true
        && !in_array('Tema de B', array_column($topics['detail'], 'title'), true),
];

$pdo->exec('DROP TABLE invgate_ticket_comments; DROP TABLE invgate_ticket_priorities;');
$checks['Sin comentarios o prioridades, se usan las actualizaciones y los tickets quedan sin prioridad manual'] =
    array_column($service->forTeam(1, $now, [], 'invgate')['detail'], 'number') === [102, 106, 107, 108, 109]
    && $service->forTeam(1, $now, [], 'invgate-people')['detail'][0]['tickets'][0]['manual_priority'] === null;
$pdo->exec('DELETE FROM invgate_tickets;');
$empty = $service->forTeam(1, $now, [
    'invgate' => ['server_url' => 'https://example.test', 'user' => 'fixture', 'password' => 'fixture'],
]);
$checks['InvGate configurado con cero resultados devuelve cero'] = $empty['invgate_open'] === 0;
$pdo->exec('DROP TABLE invgate_tickets;');
$unavailable = $service->forTeam(1, $now, []);
$checks['InvGate sin tabla figura como no disponible'] = $unavailable['invgate_open'] === null;
$checks['Sin datos de InvGate el directorio conserva el filtro de equipo directo'] =
    array_column($service->forTeam(1, $now, [], 'invgate-people')['detail'], 'name') === ['Ada', 'Sin ID', 'Sin tickets'];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException($label);
    }
    echo 'OK ' . $label . PHP_EOL;
}
