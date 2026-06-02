<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TeamHealthRepository;
use App\Support\InvgateFinalStatuses;
use App\Support\InvgatePriority;
use App\Support\InvgateTimestamp;
use App\Support\TeamHealthThresholds;
use DateTimeImmutable;

/**
 * Carga y salud del equipo: temas + InvGate (+ DevOps opcional).
 * Cada dimensión se normaliza 0–100 contra el máximo del equipo; load_score = min(100, suma de dimensiones).
 */
final class TeamHealthService
{
    private const LOAD_IMBALANCE_RATIO = 1.5;

    /** @var TeamHealthRepository */
    private $repo;

    public function __construct(TeamHealthRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * @param array{
     *   period_days: ?int,
     *   stale_days: int,
     *   scope: string
     * } $options
     * @return array<string, mixed>
     */
    public function computeForTeam(int $teamId, array $options): array
    {
        $staleDays = max(1, (int) ($options['stale_days'] ?? 3));
        $scope = $this->normalizeScope((string) ($options['scope'] ?? 'direct'));
        $periodDays = $options['period_days'] ?? null;

        $now = new DateTimeImmutable();
        $nowTs = $now->getTimestamp();
        $staleCutoff = $nowTs - ($staleDays * 86400);
        $agingThresholdTs = $nowTs - ($staleDays * 86400);

        $hasInvgate = $this->repo->hasTable('invgate_tickets');
        $hasDevops = $this->repo->hasTable('azure_work_items');

        $people = $this->repo->listPeople($teamId);
        $topics = $this->repo->listActiveTopics($teamId);
        $allTickets = $hasInvgate ? $this->repo->listOpenTicketsForTeam($teamId) : [];
        $allComments = $hasInvgate ? $this->repo->listCommentsForTeam($teamId) : [];
        $devopsCounts = $hasDevops ? $this->repo->countOpenDevOpsByPerson($teamId) : [];

        $commentsByTicket = [];
        foreach ($allComments as $comment) {
            $ticketId = $comment['ticket_id'];
            if (!isset($commentsByTicket[$ticketId])) {
                $commentsByTicket[$ticketId] = [];
            }
            $commentsByTicket[$ticketId][] = $comment;
        }

        $topicsByPerson = [];
        foreach ($topics as $topic) {
            $pid = $topic['person_id'];
            if (!isset($topicsByPerson[$pid])) {
                $topicsByPerson[$pid] = [];
            }
            $topicsByPerson[$pid][] = $topic;
        }

        $ticketsByPerson = [];
        foreach ($allTickets as $ticket) {
            if (InvgateFinalStatuses::isFinal($ticket['status_id'])) {
                continue;
            }
            $pid = $ticket['person_id'];
            if (!isset($ticketsByPerson[$pid])) {
                $ticketsByPerson[$pid] = [];
            }
            $ticketsByPerson[$pid][] = $ticket;
        }

        /** @var list<array<string, mixed>> */
        $rawRows = [];
        foreach ($people as $person) {
            $personId = $person['id'];
            $rawRows[] = $this->buildPersonRaw(
                $person,
                $topicsByPerson[$personId] ?? [],
                $ticketsByPerson[$personId] ?? [],
                $commentsByTicket,
                $devopsCounts[$personId] ?? 0,
                $nowTs,
                $staleCutoff,
                $agingThresholdTs
            );
        }

        $avgWorkload = 0.0;
        $workloadCount = 0;
        foreach ($rawRows as $row) {
            $workload = (float) ($row['topics_raw'] ?? 0) + (float) ($row['invgate_raw'] ?? 0);
            $avgWorkload += $workload;
            $workloadCount++;
        }
        $avgWorkload = $workloadCount > 0 ? $avgWorkload / $workloadCount : 0.0;

        foreach ($rawRows as &$row) {
            $workload = (float) ($row['topics_raw'] ?? 0) + (float) ($row['invgate_raw'] ?? 0);
            $criticalTopics = (int) ($row['critical_topics'] ?? 0);
            $criticality = (float) $criticalTopics;
            foreach ($row['high_priority_stale_tickets'] ?? [] as $_) {
                $criticality += 1.0;
            }
            if ($avgWorkload > 0 && $workload > self::LOAD_IMBALANCE_RATIO * $avgWorkload) {
                $criticality += 1.0;
                $row['imbalance_flag'] = true;
            }
            $row['criticality_raw'] = $criticality;
        }
        unset($row);

        $maxTopics = $this->maxRaw($rawRows, 'topics_raw');
        $maxInvgate = $this->maxRaw($rawRows, 'invgate_raw');
        $maxDevops = $this->maxRaw($rawRows, 'devops_raw');
        $maxCriticality = $this->maxRaw($rawRows, 'criticality_raw');

        /** @var list<array<string, mixed>> */
        $computed = [];
        foreach ($rawRows as $row) {
            $topicsScore = $this->normalizeDimension((float) $row['topics_raw'], $maxTopics);
            $invgateScore = $this->normalizeDimension((float) $row['invgate_raw'], $maxInvgate);
            $devopsScore = $this->normalizeDimension((float) $row['devops_raw'], $maxDevops);
            $criticalityScore = $this->normalizeDimension((float) $row['criticality_raw'], $maxCriticality);

            $loadScore = min(100, $topicsScore + $invgateScore + $devopsScore + $criticalityScore);

            $computed[] = array_merge($row, [
                'topics_score' => $topicsScore,
                'invgate_score' => $invgateScore,
                'devops_score' => $devopsScore,
                'criticality_score' => $criticalityScore,
                'load_score' => $loadScore,
            ]);
        }

        $loadScores = array_map(static fn (array $r): int => (int) $r['load_score'], $computed);
        $sumLoad = array_sum($loadScores);
        $maxLoad = $loadScores !== [] ? max($loadScores) : 0;
        $avgLoad = $loadScores !== [] ? array_sum($loadScores) / count($loadScores) : 0.0;

        foreach ($computed as &$row) {
            $share = $sumLoad > 0
                ? (float) (int) $row['load_score'] / (float) $sumLoad
                : 0.0;
            $concentrationPenalty = $sumLoad > 0
                && $share > (TeamHealthThresholds::TEAM_CONCENTRATION_RED_MIN / 100);
            $row['concentration_penalty'] = $concentrationPenalty;

            $healthScore = TeamHealthThresholds::healthScore([
                'load_score' => (int) $row['load_score'],
                'stale_tickets' => (int) $row['stale_tickets'],
                'high_priority_stale' => (int) $row['high_priority_stale'],
                'concentration_penalty' => $concentrationPenalty,
            ]);
            $row['health_score'] = $healthScore;
            $row['status'] = TeamHealthThresholds::personStatus(
                $healthScore,
                (int) $row['load_score'],
                (int) $row['stale_tickets']
            );
        }
        unset($row);

        $peopleOut = [];
        foreach ($computed as $row) {
            if (!$this->matchesScope($row, $scope)) {
                continue;
            }
            $peopleOut[] = $this->formatPersonOutput($row);
        }

        $teamBlock = $this->buildTeamSummary($teamId, $computed, $peopleOut);

        $periodLabel = 'all';
        if ($periodDays === 7) {
            $periodLabel = '7';
        } elseif ($periodDays === 30) {
            $periodLabel = '30';
        }

        return [
            'team' => $teamBlock,
            'people' => $peopleOut,
            'meta' => [
                'period' => $periodLabel,
                'stale_days' => $staleDays,
                'generated_at' => $now->format('c'),
                'data_sources' => [
                    'topics' => true,
                    'alerts' => false,
                    'invgate' => $hasInvgate,
                    'devops' => $hasDevops,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $person
     * @param list<array<string, mixed>> $topics
     * @param list<array<string, mixed>> $openTickets
     * @param array<int, list<array<string, mixed>>> $commentsByTicket
     * @return array<string, mixed>
     */
    private function buildPersonRaw(
        array $person,
        array $topics,
        array $openTickets,
        array $commentsByTicket,
        int $devopsItems,
        int $nowTs,
        int $staleCutoff,
        int $agingThresholdTs
    ): array {
        $topicsRaw = 0.0;
        $activeTopics = 0;
        $criticalTopics = 0;

        foreach ($topics as $topic) {
            $status = (string) ($topic['status'] ?? 'open');
            if (!in_array($status, ['open', 'in_progress', 'blocked'], true)) {
                continue;
            }
            $activeTopics++;
            $priority = (int) $topic['priority'];
            $importance = (int) $topic['importance'];
            if (TeamHealthThresholds::isCriticalTopic($priority, $importance)) {
                $criticalTopics++;
            }

            $base = ($priority + $importance) / 20.0;
            if ($status === 'blocked') {
                $base *= 1.5;
            } elseif ($status === 'in_progress') {
                $base *= 1.2;
            }
            $topicsRaw += $base;
        }

        $invgateRaw = 0.0;
        $openCount = 0;
        $staleCount = 0;
        $highPriorityStale = 0;
        $weightedTicketLoad = 0;
        /** @var list<true> */
        $highPriorityStaleTickets = [];

        foreach ($openTickets as $ticket) {
            $openCount++;
            $priority = $ticket['priority'];
            $weight = InvgatePriority::weight($priority);
            $weightedTicketLoad += $weight;
            $invgateRaw += (float) $weight;

            $isStale = false;
            $lastInteractionTs = $this->lastInteractionTs($ticket, $commentsByTicket[$ticket['id']] ?? []);
            if ($lastInteractionTs !== null && $lastInteractionTs < $staleCutoff) {
                $isStale = true;
            } elseif ($lastInteractionTs === null) {
                $lastUpdateTs = InvgateTimestamp::epochSeconds($ticket['last_update']);
                if ($lastUpdateTs !== null && $lastUpdateTs < $staleCutoff) {
                    $isStale = true;
                }
            }

            if ($isStale) {
                $staleCount++;
                $invgateRaw += 1.5;
                if (InvgatePriority::isHigh($priority)) {
                    $highPriorityStale++;
                    $highPriorityStaleTickets[] = true;
                }
            }

            $createdTs = InvgateTimestamp::epochSeconds($ticket['created_at']);
            if (
                InvgatePriority::isHigh($priority)
                && $createdTs !== null
                && $createdTs < $agingThresholdTs
            ) {
                $invgateRaw += 2.0;
            }
        }

        return [
            'person_id' => (int) $person['id'],
            'name' => (string) $person['display_name'],
            'role' => $person['role'],
            'is_direct_team' => (bool) $person['is_direct_team'],
            'topics_raw' => $topicsRaw,
            'invgate_raw' => $invgateRaw,
            'devops_raw' => (float) $devopsItems,
            'active_topics' => $activeTopics,
            'critical_topics' => $criticalTopics,
            'open_tickets' => $openCount,
            'stale_tickets' => $staleCount,
            'high_priority_stale' => $highPriorityStale,
            'high_priority_stale_tickets' => $highPriorityStaleTickets,
            'weighted_ticket_load' => $weightedTicketLoad,
            'devops_items' => $devopsItems,
            'imbalance_flag' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $ticketComments
     */
    private function lastInteractionTs(array $ticket, array $ticketComments): ?int
    {
        $lastTs = InvgateTimestamp::epochSeconds($ticket['last_update']);
        foreach ($ticketComments as $comment) {
            $ts = InvgateTimestamp::epochSeconds($comment['created_at']);
            if ($ts !== null && ($lastTs === null || $ts > $lastTs)) {
                $lastTs = $ts;
            }
        }

        return $lastTs;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function maxRaw(array $rows, string $key): float
    {
        $max = 0.0;
        foreach ($rows as $row) {
            $v = (float) ($row[$key] ?? 0);
            if ($v > $max) {
                $max = $v;
            }
        }

        return $max;
    }

    private function normalizeDimension(float $value, float $teamMax): int
    {
        if ($value <= 0) {
            return 0;
        }
        $den = $teamMax > 0 ? $teamMax : 1.0;

        return (int) round(min(100, 100 * $value / $den));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPersonOutput(array $row): array
    {
        $risks = $this->buildRisks($row);
        $actions = $this->buildSuggestedActions($row);

        return [
            'person_id' => (int) $row['person_id'],
            'name' => (string) $row['name'],
            'role' => $row['role'],
            'is_direct_team' => (bool) $row['is_direct_team'],
            'load_score' => (int) $row['load_score'],
            'health_score' => (int) $row['health_score'],
            'status' => (string) $row['status'],
            'metrics' => [
                'active_topics' => (int) $row['active_topics'],
                'critical_topics' => (int) $row['critical_topics'],
                'open_tickets' => (int) $row['open_tickets'],
                'stale_tickets' => (int) $row['stale_tickets'],
                'weighted_ticket_load' => (int) $row['weighted_ticket_load'],
                'devops_items' => (int) $row['devops_items'],
            ],
            'breakdown' => [
                'topics_score' => (int) $row['topics_score'],
                'invgate_score' => (int) $row['invgate_score'],
                'alerts_score' => 0,
                'devops_score' => (int) $row['devops_score'],
                'criticality_score' => (int) $row['criticality_score'],
            ],
            'risks' => $risks,
            'main_risk' => $risks[0] ?? null,
            'suggested_actions' => $actions,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function buildRisks(array $row): array
    {
        $risks = [];
        $load = (int) $row['load_score'];
        $health = (int) $row['health_score'];

        if ($load >= TeamHealthThresholds::LOAD_RED_MIN) {
            $risks[] = 'Tiene carga total muy alta.';
        } elseif ($load >= TeamHealthThresholds::LOAD_YELLOW_MIN) {
            $risks[] = 'Tiene carga elevada.';
        }

        if ($health < TeamHealthThresholds::HEALTH_RED_MAX) {
            $risks[] = 'Su índice de salud está en zona crítica.';
        }

        $critical = (int) $row['critical_topics'];
        if ($critical > 0) {
            $risks[] = 'Tiene ' . $critical . ' tema' . ($critical === 1 ? '' : 's') . ' crítico' . ($critical === 1 ? '' : 's') . '.';
        }

        $stale = (int) $row['stale_tickets'];
        if ($stale > 0) {
            $risks[] = 'Tiene ' . $stale . ' ticket' . ($stale === 1 ? '' : 's') . ' stale.';
        }

        $active = (int) $row['active_topics'];
        if ($active > 0 && $load < TeamHealthThresholds::LOAD_YELLOW_MIN) {
            if ($active >= 5) {
                $risks[] = 'Tiene ' . $active . ' temas activos.';
            }
        }

        if (!empty($row['imbalance_flag'])) {
            $risks[] = 'Su carga está muy por encima del promedio del equipo.';
        }

        if ($risks === []) {
            $risks[] = 'Sin señales de riesgo destacadas.';
        }

        return $risks;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function buildSuggestedActions(array $row): array
    {
        $actions = [];
        $load = (int) $row['load_score'];
        $health = (int) $row['health_score'];
        $status = (string) $row['status'];

        if ($status === 'red' || $load >= TeamHealthThresholds::LOAD_RED_MIN) {
            $actions[] = 'Revisar sobrecarga en el próximo 1:1.';
        }

        if ((int) $row['critical_topics'] > 0) {
            $actions[] = 'Repriorizar temas críticos.';
        }

        if ((int) $row['stale_tickets'] > 0 || (int) $row['high_priority_stale'] > 0) {
            $actions[] = 'Redistribuir tickets P1/P2.';
        }

        if ($load < TeamHealthThresholds::LOAD_GREEN_MAX && $health >= TeamHealthThresholds::HEALTH_GREEN_MIN) {
            $actions[] = 'Puede absorber carga adicional.';
        }

        if ($actions === []) {
            $actions[] = 'Mantener seguimiento habitual.';
        }

        return $actions;
    }

    /**
     * @param list<array<string, mixed>> $allComputed
     * @param list<array<string, mixed>> $scopedPeople
     * @return array<string, mixed>
     */
    private function buildTeamSummary(int $teamId, array $allComputed, array $scopedPeople): array
    {
        $healthScores = [];
        $loadScores = [];
        $criticalTotal = 0;
        $staleTotal = 0;
        $mostLoaded = null;
        $maxLoad = 0;

        foreach ($allComputed as $row) {
            $healthScores[] = (int) $row['health_score'];
            $load = (int) $row['load_score'];
            $loadScores[] = $load;
            $criticalTotal += (int) $row['critical_topics'];
            $staleTotal += (int) $row['stale_tickets'];

            if ($load > $maxLoad) {
                $maxLoad = $load;
                $mostLoaded = [
                    'person_id' => (int) $row['person_id'],
                    'name' => (string) $row['name'],
                    'load_score' => $load,
                ];
            }
        }

        $avgHealth = $healthScores !== [] ? (int) round(array_sum($healthScores) / count($healthScores)) : 0;
        $avgLoad = $loadScores !== [] ? (int) round(array_sum($loadScores) / count($loadScores)) : 0;
        $sumLoad = array_sum($loadScores);
        $concentration = $sumLoad > 0 && $maxLoad > 0
            ? round(100 * $maxLoad / $sumLoad, 1)
            : 0.0;
        $imbalance = $avgLoad > 0 ? round($maxLoad / $avgLoad, 2) : null;

        $statusRows = array_map(static fn (array $r): array => [
            'status' => (string) $r['status'],
            'load_score' => (int) $r['load_score'],
            'health_score' => (int) $r['health_score'],
        ], $allComputed);

        $teamStatus = TeamHealthThresholds::teamStatus($statusRows, $concentration);

        return [
            'team_id' => $teamId,
            'status' => $teamStatus,
            'health_score' => $avgHealth,
            'average_load_score' => $avgLoad,
            'max_load_score' => $maxLoad,
            'most_loaded_person' => $mostLoaded,
            'imbalance_ratio' => $imbalance,
            'concentration_percent' => $concentration,
            'critical_topics_count' => $criticalTotal,
            'stale_tickets_count' => $staleTotal,
            'main_recommendation' => $this->buildMainRecommendation(
                $teamStatus,
                $allComputed,
                $concentration,
                $staleTotal
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $allComputed
     */
    private function buildMainRecommendation(
        string $teamStatus,
        array $allComputed,
        float $concentration,
        int $staleTotal
    ): string {
        $redCount = 0;
        foreach ($allComputed as $row) {
            if (($row['status'] ?? '') === 'red') {
                $redCount++;
            }
        }

        if ($teamStatus === 'red') {
            if ($concentration > TeamHealthThresholds::TEAM_CONCENTRATION_RED_MIN) {
                return 'Revisar la distribución de carga: hay alta concentración en una persona.';
            }
            if ($redCount > 0) {
                return 'Revisar la distribución de carga y priorizar personas en rojo.';
            }

            return 'El equipo requiere atención inmediata en capacidad y riesgos.';
        }

        if ($staleTotal >= 5) {
            return 'Priorizar tickets stale y redistribuir trabajo P1/P2.';
        }

        if ($teamStatus === 'yellow') {
            return 'Monitorear personas en amarillo y equilibrar asignaciones antes de que empeoren.';
        }

        $underloaded = 0;
        $avgLoad = 0;
        $loads = array_map(static fn (array $r): int => (int) $r['load_score'], $allComputed);
        if ($loads !== []) {
            $avgLoad = (int) round(array_sum($loads) / count($loads));
        }
        foreach ($allComputed as $row) {
            if (
                ($row['status'] ?? '') === 'green'
                && (int) $row['load_score'] < $avgLoad
            ) {
                $underloaded++;
            }
        }
        if ($underloaded >= 2) {
            return 'Hay capacidad en el equipo para asignar trabajo adicional.';
        }

        return 'El equipo muestra salud y carga balanceadas; mantener el ritmo actual.';
    }

    private function normalizeScope(string $scope): string
    {
        if (in_array($scope, ['all', 'direct', 'collaborators'], true)) {
            return $scope;
        }

        return 'all';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matchesScope(array $row, string $scope): bool
    {
        if ($scope === 'all') {
            return true;
        }
        $direct = !empty($row['is_direct_team']);

        return $scope === 'direct' ? $direct : !$direct;
    }
}
