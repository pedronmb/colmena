<?php

declare(strict_types=1);

/** Ejecutar: php tests/invgate-prioritization.php [ruta-pdf-de-prueba] */
use App\Bootstrap;
use App\Repositories\InvgateTicketPriorityRepository;
use App\Repositories\InvgateTicketRepository;
use App\Services\InvgatePrioritizationPdf;
use App\Support\InvgateManualPriority;

$root = dirname(__DIR__);
require $root . '/src/Bootstrap.php';
Bootstrap::registerAutoload($root);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec(file_get_contents($root . '/database/schema.sql'));
$pdo->exec("INSERT INTO teams (id, name) VALUES (1, 'Equipo A'), (2, 'Equipo B')");
$pdo->exec("INSERT INTO team_people (id, team_id, display_name, invgate_id) VALUES
    (1, 1, 'María García', 101), (2, 1, 'José Pérez', 102), (3, 2, 'Otra persona', 103), (4, 1, 'Sin tickets', 104)");
$tickets = new InvgateTicketRepository($pdo);
foreach ([[1, 101, 1], [1, 102, 1], [1, 103, 1], [1, 104, 5], [2, 201, 1], [3, 301, 1]] as [$person, $incident, $status]) {
    $tickets->upsertFromApi($person, ['id' => $incident, 'title' => 'Ticket ' . $incident, 'status_id' => $status, 'priority_id' => 3]);
}
$ids = $pdo->query('SELECT invgate_incident_id, id FROM invgate_tickets')->fetchAll(PDO::FETCH_KEY_PAIR);
$repo = new InvgateTicketPriorityRepository($pdo);
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $action, string $exception): void
{
    try {
        $action();
    } catch (Throwable $e) {
        check($e instanceof $exception, 'Tipo inesperado de error: ' . get_class($e));
        return;
    }
    check(false, 'Se aceptó una operación inválida.');
}

check(count($repo->listGroupedByTeam(1)) === 3, 'Debe incluir personas sin tickets y excluir otro equipo.');
check(count($repo->findGroup(1, 1)['tickets']) === 3, 'Debe excluir cerrados.');
check($repo->findGroup(1, 3) === null, 'No debe mostrar personas de otro equipo.');
$repo->saveForPerson(1, 1, [
    ['ticket_id' => (int) $ids[101], 'priority' => 9],
    ['ticket_id' => (int) $ids[103], 'priority' => 1],
]);
$group = $repo->findGroup(1, 1);
check(array_column($group['tickets'], 'invgate_incident_id') === [103, 101, 102], 'Orden por prioridad y vacíos al final.');
check(array_column($group['tickets'], 'manual_priority') === [1, 9, null], 'Persistencia numérica.');
$tickets->upsertFromApi(1, ['id' => 103, 'title' => 'Actualizado desde InvGate', 'status_id' => 1, 'priority_id' => 4]);
$group = $repo->findGroup(1, 1);
check($group['tickets'][0]['manual_priority'] === 1 && $group['tickets'][0]['priority'] === 4, 'El sync debe conservar la prioridad manual.');
foreach ([0, -1, 1.5, true, [], '1e2', '3.5', 2147483648] as $invalid) {
    rejects(static fn () => InvgateManualPriority::parse($invalid), InvalidArgumentException::class);
}
check(InvgateManualPriority::parse(null) === null, 'Permite quitar prioridad.');
check(InvgateManualPriority::parse(2147483647) === 2147483647, 'Permite el entero máximo.');
foreach ([104, 201, 301] as $incident) {
    rejects(static fn () => $repo->saveForPerson(1, 1, [['ticket_id' => (int) $ids[$incident], 'priority' => 1]]), OutOfBoundsException::class);
}
rejects(static fn () => $repo->saveForPerson(1, 3, [['ticket_id' => (int) $ids[301], 'priority' => 1]]), OutOfBoundsException::class);
rejects(static fn () => $repo->saveForPerson(1, 1, [
    ['ticket_id' => (int) $ids[101], 'priority' => 2], ['ticket_id' => (int) $ids[201], 'priority' => 1],
]), OutOfBoundsException::class);
check($repo->findGroup(1, 1)['tickets'][1]['manual_priority'] === 9, 'Un lote inválido no debe guardar parcialmente.');
rejects(static fn () => $repo->saveForPerson(1, 1, [
    ['ticket_id' => (int) $ids[101], 'priority' => 2], ['ticket_id' => (int) $ids[101], 'priority' => 3],
]), InvalidArgumentException::class);
$repo->saveForPerson(1, 1, [['ticket_id' => (int) $ids[103], 'priority' => null]]);
check(array_column($repo->findGroup(1, 1)['tickets'], 'invgate_incident_id') === [101, 102, 103], 'Quitar prioridad y ordenar empates.');

$sample = $repo->findGroup(1, 1);
$sample['tickets'][0]['title'] = 'Revisión de integración: conexión, permisos y sincronización de información (áéíóú ñ Ñ)';
$sample['tickets'][1]['title'] = 'Validar "título" <con símbolos> y ruta C:\\carpeta\\archivo';
for ($i = 0; $i < 75; $i++) {
    $sample['tickets'][] = array_merge($sample['tickets'][0], [
        'invgate_incident_id' => 1000 + $i,
        'manual_priority' => $i + 10,
        'title' => $i === 35 ? str_repeat('Título extenso con acentos y palabraslargassinseparación ', 250)
            : 'Verificar la sincronización y los permisos de acceso del equipo. Caso ' . $i,
    ]);
}
$pdf = (new InvgatePrioritizationPdf())->render($sample['person'], $sample['tickets']);
check(str_starts_with($pdf, '%PDF-1.4'), 'PDF válido.');
check(substr_count($pdf, '/Type /Page ') >= 3, 'Listas largas y títulos extensos deben paginarse.');
check(str_contains($pdf, 'startxref'), 'PDF con tabla de referencias.');
$emptyPdf = (new InvgatePrioritizationPdf())->render(['display_name' => 'Sin tickets'], []);
check(str_contains($emptyPdf, 'Esta persona no tiene tickets abiertos.'), 'Exportación vacía válida.');
if (isset($argv[1])) {
    file_put_contents($argv[1], $pdf);
}
$pdo->exec('DELETE FROM invgate_tickets WHERE id = ' . (int) $ids[101]);
check((int) $pdo->query('SELECT count(*) FROM invgate_ticket_priorities')->fetchColumn() === 0, 'Borrado en cascada de prioridades.');
echo 'OK: ' . $checks . " verificaciones de priorización y PDF.\n";
