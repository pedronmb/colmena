<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvgateStatsRepository;
use App\Support\InvgateFinalStatuses;
use App\Support\InvgatePriority;
use App\Support\InvgateTimestamp;
use DateTimeImmutable;

final class InvgateStatsService
{
    /** @var InvgateStatsRepository */
    private $repo;

    public function __construct(InvgateStatsRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * @param array{period_days: ?int, stale_days: int} $options
     * @return array<string, mixed>
     */
    public function statsByTeam(int $teamId, array $options): array
    {
        $periodDays = $options['period_days'] ?? null;
        $staleDays = max(1, (int) ($options['stale_days'] ?? 3));
        $now = new DateTimeImmutable();
        $nowTs = $now->getTimestamp();
        $staleCutoff = $nowTs - ($staleDays * 86400);
        $periodCutoff = $periodDays !== null ? $nowTs - ($periodDays * 86400) : null;
        $period7Cutoff = $nowTs - (7 * 86400);
        $period30Cutoff = $nowTs - (30 * 86400);

        $people = $this->repo->listPeopleForTeam($teamId);
        $allTickets = $this->repo->listTicketsForTeam($teamId);
        $allComments = $this->repo->listCommentsForTeam($teamId);

        $commentsByTicket = [];
        foreach ($allComments as $comment) {
            $ticketId = $comment['ticket_id'];
            if (!isset($commentsByTicket[$ticketId])) {
                $commentsByTicket[$ticketId] = [];
            }
            $commentsByTicket[$ticketId][] = $comment;
        }

        $ticketsByPerson = [];
        foreach ($allTickets as $ticket) {
            $pid = $ticket['person_id'];
            if (!isset($ticketsByPerson[$pid])) {
                $ticketsByPerson[$pid] = [];
            }
            $ticketsByPerson[$pid][] = $ticket;
        }

        $peopleStats = [];
        foreach ($people as $person) {
            $personId = $person['id'];
            $tickets = $ticketsByPerson[$personId] ?? [];
            $peopleStats[] = $this->buildPersonStats(
                $person,
                $tickets,
                $commentsByTicket,
                $nowTs,
                $staleCutoff,
                $periodCutoff,
                $period7Cutoff,
                $period30Cutoff,
                $staleDays
            );
        }

        usort(
            $peopleStats,
            static function (array $a, array $b): int {
                $loadA = isset($a['current']['weighted_load']) ? (int) $a['current']['weighted_load'] : 0;
                $loadB = isset($b['current']['weighted_load']) ? (int) $b['current']['weighted_load'] : 0;
                if ($loadA !== $loadB) {
                    return $loadB <=> $loadA;
                }
                $nameA = isset($a['person']['display_name']) ? (string) $a['person']['display_name'] : '';
                $nameB = isset($b['person']['display_name']) ? (string) $b['person']['display_name'] : '';

                return strcasecmp($nameA, $nameB);
            }
        );

        $teamSummary = $this->buildTeamSummary($peopleStats);

        $periodLabel = 'all';
        if ($periodDays === 7) {
            $periodLabel = '7';
        } elseif ($periodDays === 30) {
            $periodLabel = '30';
        }

        return [
            'period' => $periodLabel,
            'stale_days' => $staleDays,
            'team_summary' => $teamSummary,
            'people' => $peopleStats,
            'meta' => [
                'generated_at' => $now->format('c'),
                'people_with_invgate_id' => count($people),
                'data_notes' => 'Métricas calculadas sobre datos locales sincronizados. '
                    . 'Los tickets cerrados solo aparecen si estuvieron abiertos al sincronizar. '
                    . 'Las métricas de comentarios requieren sync_invgate_comments.php.',
            ],
        ];
    }

    /**
     * @param array{
     *   id: int,
     *   display_name: string,
     *   invgate_id: int,
     *   email: ?string,
     *   role: ?string
     * } $person
     * @param list<array<string, mixed>> $tickets
     * @param array<int, list<array<string, mixed>>> $commentsByTicket
     * @return array<string, mixed>
     */
    private function buildPersonStats(
        array $person,
        array $tickets,
        array $commentsByTicket,
        int $nowTs,
        int $staleCutoff,
        ?int $periodCutoff,
        int $period7Cutoff,
        int $period30Cutoff,
        int $staleDays
    ): array {
        $invgateId = $person['invgate_id'];
        $openTickets = [];
        $finalTickets = [];
        foreach ($tickets as $ticket) {
            if (InvgateFinalStatuses::isFinal($ticket['status_id'])) {
                $finalTickets[] = $ticket;
            } else {
                $openTickets[] = $ticket;
            }
        }

        $openCount = count($openTickets);
        $weightedLoad = 0;
        $highPriorityCount = 0;
        $agesDays = [];
        $staleCount = 0;
        $agingHighPriorityCount = 0;
        $agingThresholdTs = $nowTs - ($staleDays * 86400);

        $statusDist = [];
        $typeDist = [];
        $categoryDist = [];

        foreach ($openTickets as $ticket) {
            $priority = $ticket['priority'];
            $weightedLoad += InvgatePriority::weight($priority);
            if (InvgatePriority::isHigh($priority)) {
                $highPriorityCount++;
            }

            $createdTs = InvgateTimestamp::epochSeconds($ticket['created_at']);
            if ($createdTs !== null) {
                $ageDays = ($nowTs - $createdTs) / 86400;
                $agesDays[] = $ageDays;
                if (InvgatePriority::isHigh($priority) && $createdTs < $agingThresholdTs) {
                    $agingHighPriorityCount++;
                }
            }

            $lastInteractionTs = $this->lastInteractionTs($ticket, $commentsByTicket[$ticket['id']] ?? []);
            if ($lastInteractionTs !== null && $lastInteractionTs < $staleCutoff) {
                $staleCount++;
            } elseif ($lastInteractionTs === null) {
                $lastUpdateTs = InvgateTimestamp::epochSeconds($ticket['last_update']);
                if ($lastUpdateTs !== null && $lastUpdateTs < $staleCutoff) {
                    $staleCount++;
                }
            }

            $this->incrementDistribution($statusDist, $ticket['status_id'], $ticket['status_name'] ?? 'Sin estado');
            $this->incrementDistribution($typeDist, $ticket['type_id'], $ticket['type_name'] ?? 'Sin tipo');
            $this->incrementDistribution($categoryDist, $ticket['category_id'], $ticket['category_name'] ?? 'Sin categoría');
        }

        $resolvedInPeriod = 0;
        $resolved7d = 0;
        $resolved30d = 0;
        $resolutionHours = [];
        $weeklyThroughput = $this->emptyWeeklyBuckets($nowTs);

        foreach ($finalTickets as $ticket) {
            $lastUpdateTs = InvgateTimestamp::epochSeconds($ticket['last_update']);
            $createdTs = InvgateTimestamp::epochSeconds($ticket['created_at']);

            if ($lastUpdateTs !== null && $lastUpdateTs >= $period7Cutoff) {
                $resolved7d++;
            }
            if ($lastUpdateTs !== null && $lastUpdateTs >= $period30Cutoff) {
                $resolved30d++;
            }
            if ($periodCutoff !== null && $lastUpdateTs !== null && $lastUpdateTs >= $periodCutoff) {
                $resolvedInPeriod++;
            } elseif ($periodCutoff === null) {
                $resolvedInPeriod++;
            }

            if ($createdTs !== null && $lastUpdateTs !== null && $lastUpdateTs >= $createdTs) {
                if ($periodCutoff === null || ($lastUpdateTs >= $periodCutoff)) {
                    $resolutionHours[] = ($lastUpdateTs - $createdTs) / 3600;
                }
            }

            if ($lastUpdateTs !== null) {
                $weekKey = $this->isoWeekKey($lastUpdateTs);
                if (isset($weeklyThroughput[$weekKey])) {
                    $weeklyThroughput[$weekKey]++;
                }
            }
        }

        $commentMetrics = $this->computeCommentMetrics(
            $person,
            $tickets,
            $openTickets,
            $commentsByTicket,
            $nowTs,
            $invgateId
        );

        $ticketsWithSolution = 0;
        foreach ($tickets as $ticket) {
            $ticketComments = $commentsByTicket[$ticket['id']] ?? [];
            foreach ($ticketComments as $c) {
                if ($c['is_solution']) {
                    $ticketsWithSolution++;
                    break;
                }
            }
        }
        $solutionRatePct = count($tickets) > 0
            ? round(100 * $ticketsWithSolution / count($tickets), 1)
            : null;

        return [
            'person' => [
                'id' => $person['id'],
                'display_name' => $person['display_name'],
                'invgate_id' => $person['invgate_id'],
                'email' => $person['email'],
                'role' => $person['role'],
            ],
            'current' => [
                'open_count' => $openCount,
                'weighted_load' => $weightedLoad,
                'high_priority_pct' => $openCount > 0
                    ? round(100 * $highPriorityCount / $openCount, 1)
                    : null,
                'backlog_age_avg_days' => $this->avg($agesDays),
                'backlog_age_median_days' => $this->median($agesDays),
                'stale_count' => $staleCount,
                'aging_high_priority_count' => $agingHighPriorityCount,
            ],
            'distributions' => [
                'by_status' => $this->distributionToList($statusDist, $openCount),
                'by_type' => $this->distributionToList($typeDist, $openCount),
                'by_category' => $this->distributionToList($categoryDist, $openCount),
            ],
            'historical' => [
                'resolved_in_period' => $resolvedInPeriod,
                'resolved_7d' => $resolved7d,
                'resolved_30d' => $resolved30d,
                'resolution_avg_hours' => $this->avg($resolutionHours),
                'resolution_p50_hours' => $this->percentile($resolutionHours, 50),
                'resolution_p90_hours' => $this->percentile($resolutionHours, 90),
                'weekly_throughput' => $this->weeklyThroughputToList($weeklyThroughput),
            ],
            'comments' => array_merge($commentMetrics, [
                'solution_rate_pct' => $solutionRatePct,
            ]),
        ];
    }

    /**
     * @param array{
     *   id: int,
     *   display_name: string,
     *   invgate_id: int
     * } $person
     * @param list<array<string, mixed>> $allTickets
     * @param list<array<string, mixed>> $openTickets
     * @param array<int, list<array<string, mixed>>> $commentsByTicket
     * @return array<string, mixed>
     */
    private function computeCommentMetrics(
        array $person,
        array $allTickets,
        array $openTickets,
        array $commentsByTicket,
        int $nowTs,
        int $invgateId
    ): array {
        $agentCommentTotal = 0;
        $ticketCommentCounts = [];
        $firstResponseHours = [];
        $idleHours = [];

        foreach ($allTickets as $ticket) {
            $ticketId = $ticket['id'];
            $ticketComments = $commentsByTicket[$ticketId] ?? [];
            $ticketCommentCounts[] = count($ticketComments);

            $createdTs = InvgateTimestamp::epochSeconds($ticket['created_at']);
            $firstAgentTs = null;

            foreach ($ticketComments as $comment) {
                if ($comment['author_id'] === $invgateId) {
                    $agentCommentTotal++;
                    $commentTs = InvgateTimestamp::epochSeconds($comment['created_at']);
                    if ($commentTs !== null && $createdTs !== null && $commentTs > $createdTs) {
                        if ($firstAgentTs === null || $commentTs < $firstAgentTs) {
                            $firstAgentTs = $commentTs;
                        }
                    }
                }
            }

            if ($firstAgentTs !== null && $createdTs !== null) {
                $firstResponseHours[] = ($firstAgentTs - $createdTs) / 3600;
            }
        }

        foreach ($openTickets as $ticket) {
            $lastTs = $this->lastInteractionTs($ticket, $commentsByTicket[$ticket['id']] ?? []);
            if ($lastTs !== null) {
                $idleHours[] = ($nowTs - $lastTs) / 3600;
            }
        }

        $hasComments = array_sum($ticketCommentCounts) > 0;

        return [
            'avg_per_ticket' => count($allTickets) > 0 && $hasComments
                ? round(array_sum($ticketCommentCounts) / count($allTickets), 2)
                : null,
            'total' => $hasComments ? $agentCommentTotal : null,
            'avg_first_response_hours' => $hasComments ? $this->avg($firstResponseHours) : null,
            'avg_idle_hours' => $hasComments ? $this->avg($idleHours) : null,
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
     * @param array<string|int, array{label: string, count: int}> $dist
     */
    private function incrementDistribution(array &$dist, ?int $id, string $label): void
    {
        $key = $id !== null ? (string) $id : '_null';
        if (!isset($dist[$key])) {
            $dist[$key] = ['id' => $id, 'label' => $label, 'count' => 0];
        }
        $dist[$key]['count']++;
    }

    /**
     * @param array<string, array{id: ?int, label: string, count: int}> $dist
     * @return list<array{id: ?int, label: string, count: int, pct: ?float}>
     */
    private function distributionToList(array $dist, int $total): array
    {
        $list = array_values($dist);
        usort(
            $list,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count']
        );
        foreach ($list as &$item) {
            $item['pct'] = $total > 0 ? round(100 * $item['count'] / $total, 1) : null;
        }
        unset($item);

        return $list;
    }

    /**
     * @return array<string, int>
     */
    private function emptyWeeklyBuckets(int $nowTs): array
    {
        $buckets = [];
        for ($i = 7; $i >= 0; $i--) {
            $ts = $nowTs - ($i * 7 * 86400);
            $buckets[$this->isoWeekKey($ts)] = 0;
        }

        return $buckets;
    }

    private function isoWeekKey(int $timestamp): string
    {
        $dt = (new DateTimeImmutable())->setTimestamp($timestamp);

        return $dt->format('o-\WW');
    }

    /**
     * @param array<string, int> $weeklyThroughput
     * @return list<array{week: string, count: int}>
     */
    private function weeklyThroughputToList(array $weeklyThroughput): array
    {
        $list = [];
        foreach ($weeklyThroughput as $week => $count) {
            $list[] = ['week' => $week, 'count' => $count];
        }

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $peopleStats
     * @return array<string, mixed>
     */
    private function buildTeamSummary(array $peopleStats): array
    {
        $openTotal = 0;
        $weightedTotal = 0;
        $staleTotal = 0;
        $resolved30Total = 0;
        $ranking = [];

        foreach ($peopleStats as $row) {
            $current = $row['current'] ?? [];
            $openTotal += (int) ($current['open_count'] ?? 0);
            $weightedTotal += (int) ($current['weighted_load'] ?? 0);
            $staleTotal += (int) ($current['stale_count'] ?? 0);
            $resolved30Total += (int) ($row['historical']['resolved_30d'] ?? 0);
            $ranking[] = [
                'person_id' => $row['person']['id'],
                'display_name' => $row['person']['display_name'],
                'weighted_load' => (int) ($current['weighted_load'] ?? 0),
                'open_count' => (int) ($current['open_count'] ?? 0),
            ];
        }

        usort(
            $ranking,
            static fn (array $a, array $b): int => $b['weighted_load'] <=> $a['weighted_load']
        );

        return [
            'open_total' => $openTotal,
            'weighted_load_total' => $weightedTotal,
            'stale_total' => $staleTotal,
            'resolved_30d_total' => $resolved30Total,
            'top_by_load' => array_slice($ranking, 0, 3),
        ];
    }

    /** @param list<float> $values */
    private function avg(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /** @param list<float> $values */
    private function median(array $values): ?float
    {
        return $this->percentile($values, 50);
    }

    /** @param list<float> $values */
    private function percentile(array $values, int $pct): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $idx = (int) ceil(($pct / 100) * count($values)) - 1;
        $idx = max(0, min($idx, count($values) - 1));

        return round($values[$idx], 2);
    }
}
