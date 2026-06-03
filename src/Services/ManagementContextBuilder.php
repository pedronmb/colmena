<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AlertRepository;
use App\Repositories\TeamHealthRepository;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TopicRepository;
use App\Support\AzureDevOpsFinalStates;
use App\Support\InvgateFinalStatuses;
use App\Support\InvgatePriority;
use App\Support\ManagementOrgGrouping;
use App\Support\TeamHealthThresholds;
use DateTimeImmutable;
use PDO;

final class ManagementContextBuilder
{
    private const STALE_TOPIC_DAYS = 14;
    private const MAX_TOPICS = 80;
    private const MAX_STALE_TICKETS_PER_PERSON = 8;
    private const MAX_OPEN_TICKETS_PER_PERSON = 6;
    private const MAX_WORK_ITEMS_PER_PERSON = 5;

    /** @var TeamHealthService */
    private $healthService;

    /** @var TeamHealthRepository */
    private $healthRepo;

    /** @var TopicRepository */
    private $topicsRepo;

    /** @var AlertRepository */
    private $alertsRepo;

    /** @var TeamPersonRepository */
    private $peopleRepo;

    /** @var PDO */
    private $pdo;

    public function __construct(
        TeamHealthService $healthService,
        TeamHealthRepository $healthRepo,
        TopicRepository $topicsRepo,
        AlertRepository $alertsRepo,
        TeamPersonRepository $peopleRepo,
        PDO $pdo
    ) {
        $this->healthService = $healthService;
        $this->healthRepo = $healthRepo;
        $this->topicsRepo = $topicsRepo;
        $this->alertsRepo = $alertsRepo;
        $this->peopleRepo = $peopleRepo;
        $this->pdo = $pdo;
    }

    /**
     * @param array{stale_days?: int, scope?: string} $options
     * @return array<string, mixed>
     */
    public function buildTeamSnapshot(int $teamId, array $options = []): array
    {
        $staleDays = max(1, (int) ($options['stale_days'] ?? 3));
        $scope = (string) ($options['scope'] ?? 'direct');

        $teamPeople = $this->peopleRepo->listByTeam($teamId);
        $directTeamIds = $this->directTeamPersonIds($teamPeople);
        $org = ManagementOrgGrouping::build($this->mapPeopleForOrg($teamPeople));

        $health = $this->healthService->computeForTeam($teamId, [
            'period_days' => null,
            'stale_days' => $staleDays,
            'scope' => $scope,
        ]);

        $peopleProfiles = $this->buildPeopleProfiles($teamPeople, $directTeamIds);
        $topics = $this->buildTopicsList($teamId, $directTeamIds);
        $alerts = $this->buildAlertsSummary($teamId);
        $staleTopics = $this->filterStaleTopics($topics);
        $ticketsSample = $this->buildTicketsStaleSample($teamId, $staleDays, $directTeamIds);
        $ticketsOpenSample = $this->buildTicketsOpenSample($teamId, $staleDays, $directTeamIds);
        $workItemsSample = $this->buildWorkItemsSample($teamId, $directTeamIds);

        $peopleWithShare = $this->attachLoadShare($health['people'] ?? []);

        return [
            'team_id' => $teamId,
            'generated_at' => (new DateTimeImmutable())->format('c'),
            'scope' => $scope,
            'historical_note' => 'No hay histórico de evolución de pentágono ni throughput en la base de datos.',
            'encargados' => $org['encargados'],
            'org_by_encargado' => $org['org_by_encargado'],
            'direct_team_unassigned' => $org['direct_team_unassigned'],
            'health' => $health,
            'people' => $peopleWithShare,
            'people_profiles' => $peopleProfiles,
            'topics' => $topics,
            'stale_topics' => $staleTopics,
            'alerts' => $alerts,
            'tickets_sample' => $ticketsSample,
            'tickets_open_sample' => $ticketsOpenSample,
            'work_items_sample' => $workItemsSample,
        ];
    }

    public function contextHash(array $snapshot): string
    {
        $copy = $snapshot;
        unset($copy['generated_at']);
        $json = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    /**
     * @param array<string, mixed> $teamSnapshot
     * @return array<string, mixed>
     */
    public function buildPersonSnapshot(array $teamSnapshot, int $personId): array
    {
        $person = null;
        foreach ($teamSnapshot['people'] ?? [] as $p) {
            if ((int) ($p['person_id'] ?? 0) === $personId) {
                $person = $p;
                break;
            }
        }

        $profile = null;
        foreach ($teamSnapshot['people_profiles'] ?? [] as $prof) {
            if ((int) ($prof['person_id'] ?? 0) === $personId) {
                $profile = $prof;
                break;
            }
        }

        $personTopics = [];
        foreach ($teamSnapshot['topics'] ?? [] as $topic) {
            if ((int) ($topic['person_id'] ?? 0) === $personId) {
                $personTopics[] = $topic;
            }
        }

        $personTickets = [];
        foreach (['tickets_sample', 'tickets_open_sample'] as $key) {
            foreach ($teamSnapshot[$key] ?? [] as $ticket) {
                if ((int) ($ticket['person_id'] ?? 0) === $personId) {
                    $personTickets[] = $ticket;
                }
            }
        }

        $personWorkItems = [];
        foreach ($teamSnapshot['work_items_sample'] ?? [] as $item) {
            if ((int) ($item['person_id'] ?? 0) === $personId) {
                $personWorkItems[] = $item;
            }
        }

        return [
            'team_id' => (int) ($teamSnapshot['team_id'] ?? 0),
            'person_id' => $personId,
            'encargados' => $teamSnapshot['encargados'] ?? [],
            'team_summary' => $teamSnapshot['health']['team'] ?? [],
            'person' => $person,
            'profile' => $profile,
            'topics' => $personTopics,
            'tickets' => $personTickets,
            'work_items' => $personWorkItems,
            'historical_note' => $teamSnapshot['historical_note'] ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $teamSnapshot
     * @return list<int>
     */
    public function personIdsForGeneration(array $teamSnapshot): array
    {
        $ids = [];
        foreach ($teamSnapshot['people'] ?? [] as $person) {
            $personId = (int) ($person['person_id'] ?? 0);
            if ($personId < 1 || empty($person['is_direct_team'])) {
                continue;
            }
            $ids[] = $personId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<array<string, mixed>> $people
     * @return array<int, true>
     */
    private function directTeamPersonIds(array $people): array
    {
        $ids = [];
        foreach ($people as $row) {
            if (!empty($row['is_direct_team'])) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    private function mapPeopleForOrg(array $people): array
    {
        $out = [];
        foreach ($people as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'role' => $row['role'] ?? null,
                'is_direct_team' => !empty($row['is_direct_team']),
                'is_encargado' => !empty($row['is_encargado']),
                'reports_to_id' => isset($row['reports_to_id']) && $row['reports_to_id'] !== null
                    ? (int) $row['reports_to_id']
                    : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $people
     * @param array<int, true> $directTeamIds
     * @return list<array<string, mixed>>
     */
    private function buildPeopleProfiles(array $people, array $directTeamIds): array
    {
        $out = [];
        foreach ($people as $row) {
            $personId = (int) ($row['id'] ?? 0);
            if ($personId < 1 || !isset($directTeamIds[$personId])) {
                continue;
            }
            $out[] = [
                'person_id' => $personId,
                'display_name' => (string) ($row['display_name'] ?? ''),
                'role' => $row['role'] ?? null,
                'is_direct_team' => true,
                'invgate_id' => $row['invgate_id'] ?? null,
                'pentagon' => [
                    'autonomy_problem_solving' => $row['axis_autonomy_problem_solving'] ?? null,
                    'impact_scope' => $row['axis_impact_scope'] ?? null,
                    'influence_mentorship' => $row['axis_influence_mentorship'] ?? null,
                    'business_communication' => $row['axis_business_communication'] ?? null,
                    'technical_competence' => $row['axis_technical_competence'] ?? null,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<int, true> $directTeamIds
     * @return list<array<string, mixed>>
     */
    private function buildTopicsList(int $teamId, array $directTeamIds): array
    {
        if ($directTeamIds === []) {
            return [];
        }

        $topics = $this->topicsRepo->listByTeam($teamId, false, self::MAX_TOPICS);
        $out = [];
        foreach ($topics as $topic) {
            $arr = $topic->toArray();
            if (!in_array($arr['status'] ?? '', ['open', 'in_progress', 'blocked'], true)) {
                continue;
            }
            $personId = (int) ($arr['person_id'] ?? 0);
            if ($personId < 1 || !isset($directTeamIds[$personId])) {
                continue;
            }
            $out[] = [
                'id' => (int) $arr['id'],
                'person_id' => $personId,
                'title' => (string) ($arr['title'] ?? ''),
                'priority' => (int) ($arr['priority'] ?? 5),
                'importance' => (int) ($arr['importance'] ?? 5),
                'status' => (string) ($arr['status'] ?? 'open'),
                'updated_at' => (string) ($arr['updated_at'] ?? ''),
                'is_critical' => TeamHealthThresholds::isCriticalTopic(
                    (int) ($arr['priority'] ?? 5),
                    (int) ($arr['importance'] ?? 5)
                ),
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $topics
     * @return list<array<string, mixed>>
     */
    private function filterStaleTopics(array $topics): array
    {
        $cutoff = (new DateTimeImmutable())->modify('-' . self::STALE_TOPIC_DAYS . ' days')->getTimestamp();
        $stale = [];
        foreach ($topics as $topic) {
            $updated = $topic['updated_at'] ?? '';
            $ts = strtotime((string) $updated);
            if ($ts !== false && $ts < $cutoff) {
                $topic['days_without_update'] = (int) floor((time() - $ts) / 86400);
                $stale[] = $topic;
            }
        }

        return $stale;
    }

    /**
     * @return array{overdue: list<array<string, mixed>>, upcoming_7d: list<array<string, mixed>>}
     */
    private function buildAlertsSummary(int $teamId): array
    {
        $all = $this->alertsRepo->listForTeam($teamId);
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $in7 = (new DateTimeImmutable())->modify('+7 days')->format('Y-m-d');

        $overdue = [];
        $upcoming = [];
        foreach ($all as $alert) {
            $due = (string) ($alert['due_date'] ?? '');
            $item = [
                'id' => (int) ($alert['id'] ?? 0),
                'title' => (string) ($alert['title'] ?? ''),
                'due_date' => $due,
            ];
            if ($due !== '' && $due < $today) {
                $overdue[] = $item;
            } elseif ($due !== '' && $due >= $today && $due <= $in7) {
                $upcoming[] = $item;
            }
        }

        return ['overdue' => $overdue, 'upcoming_7d' => $upcoming];
    }

    /**
     * @param array<int, true> $directTeamIds
     * @return list<array<string, mixed>>
     */
    private function buildTicketsStaleSample(int $teamId, int $staleDays, array $directTeamIds): array
    {
        if ($directTeamIds === [] || !$this->healthRepo->hasTable('invgate_tickets')) {
            return [];
        }

        $tickets = $this->filterTicketsForDirectTeam(
            $this->healthRepo->listOpenTicketsForTeam($teamId),
            $directTeamIds
        );
        if ($tickets === []) {
            return [];
        }

        $commentsByTicket = $this->commentsByTicket($teamId);
        $staleCutoff = time() - ($staleDays * 86400);
        $byPerson = [];

        foreach ($tickets as $ticket) {
            $pid = (int) ($ticket['person_id'] ?? 0);
            $lastTs = $this->lastInteractionTs($ticket, $commentsByTicket[(int) $ticket['id']] ?? []);
            if ($lastTs === null || $lastTs >= $staleCutoff) {
                continue;
            }
            if (!isset($byPerson[$pid])) {
                $byPerson[$pid] = [];
            }
            if (count($byPerson[$pid]) >= self::MAX_STALE_TICKETS_PER_PERSON) {
                continue;
            }
            $byPerson[$pid][] = [
                'ticket_id' => (int) $ticket['id'],
                'person_id' => $pid,
                'invgate_incident_id' => (int) ($ticket['invgate_incident_id'] ?? 0),
                'priority' => $ticket['priority'] ?? null,
                'status_name' => $ticket['status_name'] ?? null,
                'last_update' => (string) ($ticket['last_update'] ?? ''),
                'stale' => true,
            ];
        }

        return $this->enrichTicketTitles($this->flattenByPerson($byPerson));
    }

    /**
     * @param array<int, true> $directTeamIds
     * @return list<array<string, mixed>>
     */
    private function buildTicketsOpenSample(int $teamId, int $staleDays, array $directTeamIds): array
    {
        if ($directTeamIds === [] || !$this->healthRepo->hasTable('invgate_tickets')) {
            return [];
        }

        $tickets = $this->filterTicketsForDirectTeam(
            $this->healthRepo->listOpenTicketsForTeam($teamId),
            $directTeamIds
        );
        if ($tickets === []) {
            return [];
        }

        $commentsByTicket = $this->commentsByTicket($teamId);
        $staleCutoff = time() - ($staleDays * 86400);
        $byPerson = [];

        foreach ($tickets as $ticket) {
            $pid = (int) ($ticket['person_id'] ?? 0);
            if (!isset($byPerson[$pid])) {
                $byPerson[$pid] = [];
            }
            $lastTs = $this->lastInteractionTs($ticket, $commentsByTicket[(int) $ticket['id']] ?? []);
            $isStale = $lastTs !== null && $lastTs < $staleCutoff;
            $priority = isset($ticket['priority']) ? (int) $ticket['priority'] : null;
            $byPerson[$pid][] = [
                'ticket_id' => (int) $ticket['id'],
                'person_id' => $pid,
                'invgate_incident_id' => (int) ($ticket['invgate_incident_id'] ?? 0),
                'priority' => $priority,
                'status_name' => $ticket['status_name'] ?? null,
                'last_update' => (string) ($ticket['last_update'] ?? ''),
                'stale' => $isStale,
                '_sort_weight' => InvgatePriority::weight($priority),
                '_sort_ts' => $lastTs ?? 0,
            ];
        }

        foreach ($byPerson as $pid => &$list) {
            usort(
                $list,
                static function (array $a, array $b): int {
                    $w = ($b['_sort_weight'] ?? 0) <=> ($a['_sort_weight'] ?? 0);
                    if ($w !== 0) {
                        return $w;
                    }

                    return ($a['_sort_ts'] ?? 0) <=> ($b['_sort_ts'] ?? 0);
                }
            );
            $list = array_slice($list, 0, self::MAX_OPEN_TICKETS_PER_PERSON);
            foreach ($list as &$item) {
                unset($item['_sort_weight'], $item['_sort_ts']);
            }
            unset($item);
        }
        unset($list);

        return $this->enrichTicketTitles($this->flattenByPerson($byPerson));
    }

    /**
     * @param array<int, true> $directTeamIds
     * @return list<array<string, mixed>>
     */
    private function buildWorkItemsSample(int $teamId, array $directTeamIds): array
    {
        if ($directTeamIds === [] || !$this->healthRepo->hasTable('azure_work_items')) {
            return [];
        }

        $finalStates = new AzureDevOpsFinalStates();
        $placeholders = $finalStates->sqlNotInPlaceholders();
        $lowerNames = $finalStates->namesLower();
        if ($lowerNames === []) {
            return [];
        }

        $peopleStmt = $this->pdo->prepare(
            'SELECT id, email FROM team_people WHERE team_id = :team_id'
        );
        $peopleStmt->execute(['team_id' => $teamId]);

        /** @var array<string, int> */
        $emailIndex = [];
        /** @var array<int, true> */
        $teamPersonIds = [];
        while ($row = $peopleStmt->fetch(PDO::FETCH_ASSOC)) {
            $personId = isset($row['id']) ? (int) $row['id'] : 0;
            if ($personId < 1 || !isset($directTeamIds[$personId])) {
                continue;
            }
            $teamPersonIds[$personId] = true;
            $email = isset($row['email']) && $row['email'] !== null ? trim((string) $row['email']) : '';
            if ($email !== '') {
                $emailIndex[strtolower($email)] = $personId;
            }
        }

        if ($teamPersonIds === []) {
            return [];
        }

        $itemsStmt = $this->pdo->prepare(
            'SELECT azure_id, title, work_item_type, state, assigned_to,
                    assigned_unique_name, changed_at, person_id
             FROM azure_work_items
             WHERE removed_at IS NULL
               AND LOWER(state) NOT IN (' . $placeholders . ')
             ORDER BY changed_at DESC'
        );
        $itemsStmt->execute($lowerNames);

        /** @var array<int, list<array<string, mixed>>> */
        $byPerson = [];

        while ($row = $itemsStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $personId = $this->resolveWorkItemPersonId($row, $teamPersonIds, $emailIndex);
            if ($personId === null) {
                continue;
            }
            if (!isset($byPerson[$personId])) {
                $byPerson[$personId] = [];
            }
            if (count($byPerson[$personId]) >= self::MAX_WORK_ITEMS_PER_PERSON) {
                continue;
            }
            $byPerson[$personId][] = [
                'azure_id' => (int) ($row['azure_id'] ?? 0),
                'person_id' => $personId,
                'title' => (string) ($row['title'] ?? ''),
                'work_item_type' => $row['work_item_type'] ?? null,
                'state' => (string) ($row['state'] ?? ''),
                'changed_at' => (string) ($row['changed_at'] ?? ''),
            ];
        }

        return $this->flattenByPerson($byPerson);
    }

    /**
     * @param list<array<string, mixed>> $tickets
     * @param array<int, true> $directTeamIds
     * @return list<array<string, mixed>>
     */
    private function filterTicketsForDirectTeam(array $tickets, array $directTeamIds): array
    {
        $out = [];
        foreach ($tickets as $ticket) {
            $pid = (int) ($ticket['person_id'] ?? 0);
            if ($pid < 1 || !isset($directTeamIds[$pid])) {
                continue;
            }
            if (InvgateFinalStatuses::isFinal($ticket['status_id'] ?? null)) {
                continue;
            }
            $out[] = $ticket;
        }

        return $out;
    }

    /**
     * @return array<int, list<array<string, mixed>>>
     */
    private function commentsByTicket(int $teamId): array
    {
        $comments = $this->healthRepo->listCommentsForTeam($teamId);
        $commentsByTicket = [];
        foreach ($comments as $c) {
            $tid = (int) $c['ticket_id'];
            if (!isset($commentsByTicket[$tid])) {
                $commentsByTicket[$tid] = [];
            }
            $commentsByTicket[$tid][] = $c;
        }

        return $commentsByTicket;
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byPerson
     * @return list<array<string, mixed>>
     */
    private function flattenByPerson(array $byPerson): array
    {
        $out = [];
        foreach ($byPerson as $list) {
            foreach ($list as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $tickets
     * @return list<array<string, mixed>>
     */
    private function enrichTicketTitles(array $tickets): array
    {
        if ($tickets === []) {
            return [];
        }

        $ids = array_map(static fn (array $t): int => (int) $t['ticket_id'], $tickets);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, title FROM invgate_tickets WHERE id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        /** @var array<int, string> */
        $titles = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $titles[(int) $row['id']] = (string) ($row['title'] ?? '');
        }

        foreach ($tickets as &$ticket) {
            $id = (int) $ticket['ticket_id'];
            $ticket['title'] = $titles[$id] ?? '';
        }
        unset($ticket);

        return $tickets;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, true> $teamPersonIds
     * @param array<string, int> $emailIndex
     */
    private function resolveWorkItemPersonId(array $row, array $teamPersonIds, array $emailIndex): ?int
    {
        $storedId = isset($row['person_id']) && $row['person_id'] !== null && $row['person_id'] !== ''
            ? (int) $row['person_id']
            : 0;
        if ($storedId > 0 && isset($teamPersonIds[$storedId])) {
            return $storedId;
        }

        $upn = isset($row['assigned_unique_name']) ? trim((string) $row['assigned_unique_name']) : '';
        if ($upn !== '') {
            $key = strtolower($upn);
            if (isset($emailIndex[$key])) {
                return $emailIndex[$key];
            }
        }

        $assigned = isset($row['assigned_to']) ? trim((string) $row['assigned_to']) : '';
        if ($assigned !== '') {
            $key = strtolower($assigned);
            if (isset($emailIndex[$key])) {
                return $emailIndex[$key];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $people
     * @return list<array<string, mixed>>
     */
    private function attachLoadShare(array $people): array
    {
        $sum = 0;
        foreach ($people as $p) {
            $sum += (int) ($p['load_score'] ?? 0);
        }

        $out = [];
        foreach ($people as $p) {
            $load = (int) ($p['load_score'] ?? 0);
            $share = $sum > 0 ? round(100 * $load / $sum, 1) : 0.0;
            $p['load_share_percent'] = $share;
            $out[] = $p;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<array<string, mixed>> $ticketComments
     */
    private function lastInteractionTs(array $ticket, array $ticketComments): ?int
    {
        $lastTs = \App\Support\InvgateTimestamp::epochSeconds($ticket['last_update'] ?? null);
        foreach ($ticketComments as $comment) {
            $ts = \App\Support\InvgateTimestamp::epochSeconds($comment['created_at'] ?? null);
            if ($ts !== null && ($lastTs === null || $ts > $lastTs)) {
                $lastTs = $ts;
            }
        }

        return $lastTs;
    }
}
