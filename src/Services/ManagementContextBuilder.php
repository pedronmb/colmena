<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AlertRepository;
use App\Repositories\TeamHealthRepository;
use App\Repositories\TeamPersonRepository;
use App\Repositories\TopicRepository;
use App\Support\TeamHealthThresholds;
use DateTimeImmutable;
use PDO;

final class ManagementContextBuilder
{
    private const STALE_TOPIC_DAYS = 14;
    private const MAX_TOPICS = 80;
    private const MAX_TICKETS_PER_PERSON = 8;

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
        $scope = (string) ($options['scope'] ?? 'all');

        $health = $this->healthService->computeForTeam($teamId, [
            'period_days' => null,
            'stale_days' => $staleDays,
            'scope' => $scope,
        ]);

        $peopleProfiles = $this->buildPeopleProfiles($teamId);
        $topics = $this->buildTopicsList($teamId);
        $alerts = $this->buildAlertsSummary($teamId);
        $staleTopics = $this->filterStaleTopics($topics);
        $ticketsSample = $this->buildTicketsSample($teamId, $staleDays);

        $peopleWithShare = $this->attachLoadShare($health['people'] ?? []);

        return [
            'team_id' => $teamId,
            'generated_at' => (new DateTimeImmutable())->format('c'),
            'historical_note' => 'No hay histórico de evolución de pentágono ni throughput en la base de datos.',
            'health' => $health,
            'people' => $peopleWithShare,
            'people_profiles' => $peopleProfiles,
            'topics' => $topics,
            'stale_topics' => $staleTopics,
            'alerts' => $alerts,
            'tickets_sample' => $ticketsSample,
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
        foreach ($teamSnapshot['tickets_sample'] ?? [] as $ticket) {
            if ((int) ($ticket['person_id'] ?? 0) === $personId) {
                $personTickets[] = $ticket;
            }
        }

        return [
            'team_id' => (int) ($teamSnapshot['team_id'] ?? 0),
            'person_id' => $personId,
            'team_summary' => $teamSnapshot['health']['team'] ?? [],
            'person' => $person,
            'profile' => $profile,
            'topics' => $personTopics,
            'tickets' => $personTickets,
            'historical_note' => $teamSnapshot['historical_note'] ?? '',
        ];
    }

    /**
     * Personas que conviene generar recomendación individual.
     *
     * @param array<string, mixed> $teamSnapshot
     * @return list<int>
     */
    public function personIdsForGeneration(array $teamSnapshot): array
    {
        $ids = [];
        foreach ($teamSnapshot['people'] ?? [] as $person) {
            $personId = (int) ($person['person_id'] ?? 0);
            if ($personId < 1) {
                continue;
            }
            if ($this->personNeedsRecommendation($person)) {
                $ids[] = $personId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $person
     */
    private function personNeedsRecommendation(array $person): bool
    {
        if (!empty($person['is_direct_team'])) {
            return true;
        }

        $status = (string) ($person['status'] ?? '');
        if (in_array($status, ['red', 'yellow'], true)) {
            return true;
        }

        $metrics = is_array($person['metrics'] ?? null) ? $person['metrics'] : [];
        if ((int) ($metrics['stale_tickets'] ?? 0) > 0) {
            return true;
        }
        if ((int) ($metrics['critical_topics'] ?? 0) > 0) {
            return true;
        }
        if ((int) ($person['load_score'] ?? 0) >= TeamHealthThresholds::LOAD_YELLOW_MIN) {
            return true;
        }

        return false;
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
     * @return list<array<string, mixed>>
     */
    private function buildPeopleProfiles(int $teamId): array
    {
        $people = $this->peopleRepo->listByTeam($teamId);
        $out = [];
        foreach ($people as $row) {
            $out[] = [
                'person_id' => (int) ($row['id'] ?? 0),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'role' => $row['role'] ?? null,
                'is_direct_team' => !empty($row['is_direct_team']),
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
     * @return list<array<string, mixed>>
     */
    private function buildTopicsList(int $teamId): array
    {
        $topics = $this->topicsRepo->listByTeam($teamId, false, self::MAX_TOPICS);
        $out = [];
        foreach ($topics as $topic) {
            $arr = $topic->toArray();
            if (!in_array($arr['status'] ?? '', ['open', 'in_progress', 'blocked'], true)) {
                continue;
            }
            if (empty($arr['person_id'])) {
                continue;
            }
            $out[] = [
                'id' => (int) $arr['id'],
                'person_id' => (int) $arr['person_id'],
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
     * @return list<array<string, mixed>>
     */
    private function buildTicketsSample(int $teamId, int $staleDays): array
    {
        if (!$this->healthRepo->hasTable('invgate_tickets')) {
            return [];
        }

        $tickets = $this->healthRepo->listOpenTicketsForTeam($teamId);
        if ($tickets === []) {
            return [];
        }

        $comments = $this->healthRepo->listCommentsForTeam($teamId);
        $commentsByTicket = [];
        foreach ($comments as $c) {
            $tid = (int) $c['ticket_id'];
            if (!isset($commentsByTicket[$tid])) {
                $commentsByTicket[$tid] = [];
            }
            $commentsByTicket[$tid][] = $c;
        }

        $staleCutoff = time() - ($staleDays * 86400);
        $byPerson = [];
        foreach ($tickets as $ticket) {
            $pid = (int) ($ticket['person_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            $lastTs = $this->lastInteractionTs($ticket, $commentsByTicket[(int) $ticket['id']] ?? []);
            $isStale = $lastTs !== null && $lastTs < $staleCutoff;
            if (!$isStale) {
                continue;
            }
            if (!isset($byPerson[$pid])) {
                $byPerson[$pid] = [];
            }
            if (count($byPerson[$pid]) >= self::MAX_TICKETS_PER_PERSON) {
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

        $out = [];
        foreach ($byPerson as $list) {
            foreach ($list as $item) {
                $out[] = $item;
            }
        }

        return $this->enrichTicketTitles($out);
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
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
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
