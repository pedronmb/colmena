<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvgateTicketRepository;
use App\Repositories\TeamPersonRepository;

final class InvgateTicketSyncService
{
    /** @var InvgateClient */
    private $client;

    /** @var TeamPersonRepository */
    private $peopleRepo;

    /** @var InvgateTicketRepository */
    private $ticketsRepo;

    public function __construct(
        InvgateClient $client,
        TeamPersonRepository $peopleRepo,
        InvgateTicketRepository $ticketsRepo
    ) {
        $this->client = $client;
        $this->peopleRepo = $peopleRepo;
        $this->ticketsRepo = $ticketsRepo;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, TeamPersonRepository $peopleRepo, InvgateTicketRepository $ticketsRepo): self
    {
        $ig = self::parseInvgateConfig($config);
        $client = new InvgateClient(
            $ig['server_url'],
            $ig['user'],
            $ig['password'],
            $ig['limit']
        );

        return new self($client, $peopleRepo, $ticketsRepo);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{server_url: string, user: string, password: string, limit: int}
     */
    public static function parseInvgateConfig(array $config): array
    {
        $ig = is_array($config['invgate'] ?? null) ? $config['invgate'] : [];
        $serverUrl = trim((string) ($ig['server_url'] ?? ''));
        $user = trim((string) ($ig['user'] ?? ''));
        $password = (string) ($ig['password'] ?? '');
        $limit = (int) ($ig['limit'] ?? 100);

        if ($serverUrl === '' || $user === '' || $password === '') {
            throw new \RuntimeException(
                'InvGate no está configurado en config.php (server_url, user, password).'
            );
        }

        return [
            'server_url' => $serverUrl,
            'user' => $user,
            'password' => $password,
            'limit' => max(1, min(500, $limit)),
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   people_total: int,
     *   people_ok: int,
     *   tickets_upserted: int,
     *   tickets_status_updated: int,
     *   tickets_skipped: int,
     *   errors: list<array{person_id: int, display_name: string, error: string}>
     * }
     */
    public function run(): array
    {
        $people = $this->peopleRepo->listWithInvgateId();
        $result = [
            'ok' => true,
            'people_total' => count($people),
            'people_ok' => 0,
            'tickets_upserted' => 0,
            'tickets_status_updated' => 0,
            'tickets_skipped' => 0,
            'errors' => [],
        ];

        if ($people === []) {
            return $result;
        }

        foreach ($people as $person) {
            $personId = (int) $person['id'];
            $agentId = (int) $person['invgate_id'];
            $displayName = (string) $person['display_name'];
            $finalStatusIds = [5, 6, 7, 8];

            try {
                $incidents = $this->client->fetchIncidentsByAgent($agentId);
                /** @var array<int, true> */
                $apiIncidentIds = [];
                foreach ($incidents as $incident) {
                    if (!is_array($incident)) {
                        $result['tickets_skipped']++;
                        continue;
                    }
                    $incidentId = isset($incident['id']) ? (int) $incident['id'] : 0;
                    if ($incidentId > 0) {
                        $apiIncidentIds[$incidentId] = true;
                    }
                    if ($this->ticketsRepo->upsertFromApi($personId, $incident)) {
                        $result['tickets_upserted']++;
                    } else {
                        $result['tickets_skipped']++;
                    }
                }

                // Reconciliación: tickets que existen en BD pero ya no vienen en
                // `/incidents.by.agent`. Si siguen fuera de estados finales, los
                // traemos individualmente para actualizar su status.
                $ticketsToReconcile = $this->ticketsRepo
                    ->listTicketsForStatusReconciliation($personId, $finalStatusIds);
                foreach ($ticketsToReconcile as $ticketRow) {
                    $incidentId = isset($ticketRow['invgate_incident_id'])
                        ? (int) $ticketRow['invgate_incident_id']
                        : 0;
                    if ($incidentId <= 0) {
                        continue;
                    }
                    if (isset($apiIncidentIds[$incidentId])) {
                        continue;
                    }

                    try {
                        $incident = $this->client->fetchIncidentById($incidentId);
                        $statusId = isset($incident['status_id']) ? (int) $incident['status_id'] : null;
                        if ($statusId !== null && $statusId <= 0) {
                            $statusId = null;
                        }

                        $assignedInvgateId = isset($incident['assigned_id']) ? (int) $incident['assigned_id'] : null;
                        if ($assignedInvgateId !== null && $assignedInvgateId <= 0) {
                            $assignedInvgateId = null;
                        }
                        $newPersonId = $assignedInvgateId !== null
                            ? $this->peopleRepo->findPersonIdByInvgateId($assignedInvgateId)
                            : null;

                        $lastUpdate = isset($incident['last_update'])
                            ? trim((string) $incident['last_update'])
                            : '';
                        if ($lastUpdate === '') {
                            $lastUpdate = '0';
                        }

                        if ($this->ticketsRepo->updateStatusAndAssigneeByInvgateIncidentId(
                            $incidentId,
                            $statusId,
                            $lastUpdate,
                            $newPersonId
                        )) {
                            $result['tickets_status_updated']++;
                        } else {
                            $result['tickets_skipped']++;
                        }
                    } catch (\Throwable $e) {
                        $result['tickets_skipped']++;
                        $result['errors'][] = [
                            'person_id' => $personId,
                            'display_name' => $displayName,
                            'error' => 'Error actualizando status para invgate_incident_id #' . $incidentId
                                . ': ' . $e->getMessage(),
                        ];
                    }
                }

                $result['people_ok']++;
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'person_id' => $personId,
                    'display_name' => $displayName,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($result['errors'] !== []) {
            $result['ok'] = $result['people_ok'] > 0;
        }

        return $result;
    }
}
