<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InvgateCatalogRepository;

final class InvgateCatalogSyncService
{
    /** @var InvgateClient */
    private $client;

    /** @var InvgateCatalogRepository */
    private $catalogRepo;

    public function __construct(InvgateClient $client, InvgateCatalogRepository $catalogRepo)
    {
        $this->client = $client;
        $this->catalogRepo = $catalogRepo;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, InvgateCatalogRepository $catalogRepo): self
    {
        $ig = InvgateTicketSyncService::parseInvgateConfig($config);
        $client = new InvgateClient(
            $ig['server_url'],
            $ig['user'],
            $ig['password'],
            $ig['limit']
        );

        return new self($client, $catalogRepo);
    }

    /**
     * @return array{
     *   ok: bool,
     *   categories: array{fetched: int, upserted: int, skipped: int},
     *   types: array{fetched: int, upserted: int, skipped: int},
     *   statuses: array{fetched: int, upserted: int, skipped: int},
     *   errors: list<array{scope: string, error: string}>
     * }
     */
    public function run(): array
    {
        $result = [
            'ok' => true,
            'categories' => ['fetched' => 0, 'upserted' => 0, 'skipped' => 0],
            'types' => ['fetched' => 0, 'upserted' => 0, 'skipped' => 0],
            'statuses' => ['fetched' => 0, 'upserted' => 0, 'skipped' => 0],
            'errors' => [],
        ];

        try {
            $categories = $this->client->fetchCategories();
            $result['categories']['fetched'] = count($categories);
            foreach ($categories as $row) {
                if (!is_array($row)) {
                    $result['categories']['skipped']++;
                    continue;
                }
                if ($this->catalogRepo->upsertCategory($row)) {
                    $result['categories']['upserted']++;
                } else {
                    $result['categories']['skipped']++;
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = ['scope' => 'categories', 'error' => $e->getMessage()];
        }

        try {
            $types = $this->client->fetchIncidentTypes();
            $result['types']['fetched'] = count($types);
            foreach ($types as $row) {
                if (!is_array($row)) {
                    $result['types']['skipped']++;
                    continue;
                }
                if ($this->catalogRepo->upsertType($row)) {
                    $result['types']['upserted']++;
                } else {
                    $result['types']['skipped']++;
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = ['scope' => 'types', 'error' => $e->getMessage()];
        }

        try {
            $statuses = $this->client->fetchIncidentStatuses();
            $result['statuses']['fetched'] = count($statuses);
            foreach ($statuses as $row) {
                if (!is_array($row)) {
                    $result['statuses']['skipped']++;
                    continue;
                }
                if ($this->catalogRepo->upsertStatus($row)) {
                    $result['statuses']['upserted']++;
                } else {
                    $result['statuses']['skipped']++;
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = ['scope' => 'statuses', 'error' => $e->getMessage()];
        }

        $this->syncMissingFromTickets($result);

        if ($result['errors'] !== []) {
            $result['ok'] = ($result['categories']['upserted'] + $result['types']['upserted'] + $result['statuses']['upserted']) > 0;
        }

        return $result;
    }

    /**
     * @param array{
     *   ok: bool,
     *   categories: array{fetched: int, upserted: int, skipped: int},
     *   types: array{fetched: int, upserted: int, skipped: int},
     *   statuses: array{fetched: int, upserted: int, skipped: int},
     *   errors: list<array{scope: string, error: string}>
     * } $result
     */
    private function syncMissingFromTickets(array &$result): void
    {
        try {
            $missing = $this->catalogRepo->listMissingCatalogIdsFromTickets();
        } catch (\Throwable $e) {
            $result['errors'][] = ['scope' => 'missing_ids', 'error' => $e->getMessage()];
            return;
        }

        foreach ($missing['category_ids'] as $id) {
            try {
                $rows = $this->client->fetchList('/categories', ['id' => $id]);
                foreach ($rows as $row) {
                    if (is_array($row) && $this->catalogRepo->upsertCategory($row)) {
                        $result['categories']['upserted']++;
                    }
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['scope' => 'category_id:' . (string) $id, 'error' => $e->getMessage()];
            }
        }

        foreach ($missing['type_ids'] as $id) {
            try {
                $rows = $this->client->fetchList('/incident.attributes.type', ['id' => $id]);
                foreach ($rows as $row) {
                    if (is_array($row) && $this->catalogRepo->upsertType($row)) {
                        $result['types']['upserted']++;
                    }
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['scope' => 'type_id:' . (string) $id, 'error' => $e->getMessage()];
            }
        }

        foreach ($missing['status_ids'] as $id) {
            try {
                $rows = $this->client->fetchList('/incident.attributes.status', ['id' => $id]);
                foreach ($rows as $row) {
                    if (is_array($row) && $this->catalogRepo->upsertStatus($row)) {
                        $result['statuses']['upserted']++;
                    }
                }
            } catch (\Throwable $e) {
                $result['errors'][] = ['scope' => 'status_id:' . (string) $id, 'error' => $e->getMessage()];
            }
        }
    }
}

