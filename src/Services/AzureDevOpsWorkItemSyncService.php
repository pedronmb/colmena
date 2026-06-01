<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AzureWorkItemRepository;
use App\Repositories\TeamPersonRepository;
use App\Support\AzureDevOpsFinalStates;

final class AzureDevOpsWorkItemSyncService
{
    /** @var AzureDevOpsClient */
    private $client;

    /** @var AzureWorkItemRepository */
    private $workItemsRepo;

    /** @var TeamPersonRepository */
    private $peopleRepo;

    /** @var AzureDevOpsFinalStates */
    private $finalStates;

    /** @var int */
    private $reconcileLimit;

    /** @var string|null */
    private $wiql;

    public function __construct(
        AzureDevOpsClient $client,
        AzureWorkItemRepository $workItemsRepo,
        TeamPersonRepository $peopleRepo,
        AzureDevOpsFinalStates $finalStates,
        int $reconcileLimit = 50,
        ?string $wiql = null
    ) {
        $this->client = $client;
        $this->workItemsRepo = $workItemsRepo;
        $this->peopleRepo = $peopleRepo;
        $this->finalStates = $finalStates;
        $this->reconcileLimit = max(0, $reconcileLimit);
        $this->wiql = $wiql !== null && trim($wiql) !== '' ? trim($wiql) : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        AzureWorkItemRepository $workItemsRepo,
        TeamPersonRepository $peopleRepo
    ): self {
        $parsed = self::parseAzureConfig($config);
        $client = new AzureDevOpsClient(
            $parsed['organization'],
            $parsed['project'],
            $parsed['pat'],
            $parsed['max_items']
        );
        $finalStates = new AzureDevOpsFinalStates($parsed['final_states']);
        $wiql = $parsed['wiql'] ?? AzureDevOpsClient::DEFAULT_SYNC_WIQL;

        return new self(
            $client,
            $workItemsRepo,
            $peopleRepo,
            $finalStates,
            $parsed['reconcile_limit'],
            $wiql
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   organization: string,
     *   project: string,
     *   pat: string,
     *   max_items: int,
     *   final_states: ?list<string>,
     *   reconcile_limit: int,
     *   wiql: ?string
     * }
     */
    public static function parseAzureConfig(array $config): array
    {
        $az = is_array($config['azure_devops'] ?? null) ? $config['azure_devops'] : [];
        $org = trim((string) ($az['organization'] ?? ''));
        $project = trim((string) ($az['project'] ?? ''));
        $pat = trim((string) ($az['pat'] ?? ''));
        if ($org === '' || $project === '' || $pat === '') {
            throw new \RuntimeException(
                'Azure DevOps no está configurado en config.php (organization, project, pat).'
            );
        }

        $maxItems = (int) ($az['max_items'] ?? 200);
        $reconcileLimit = (int) ($az['reconcile_limit'] ?? 50);

        $finalStates = null;
        if (isset($az['final_states']) && is_array($az['final_states'])) {
            /** @var list<string> $finalStates */
            $finalStates = array_values(array_filter(
                $az['final_states'],
                static fn ($v): bool => is_string($v) && trim($v) !== ''
            ));
            if ($finalStates === []) {
                $finalStates = null;
            }
        }

        $wiqlConfig = $az['wiql'] ?? null;
        $wiql = is_string($wiqlConfig) && trim($wiqlConfig) !== '' ? trim($wiqlConfig) : null;

        return [
            'organization' => $org,
            'project' => $project,
            'pat' => $pat,
            'max_items' => max(1, min(500, $maxItems)),
            'final_states' => $finalStates,
            'reconcile_limit' => max(0, min(500, $reconcileLimit)),
            'wiql' => $wiql,
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   items_upserted: int,
     *   items_updated_to_final: int,
     *   items_skipped_final_new: int,
     *   items_skipped_already_final: int,
     *   items_reconciled: int,
     *   items_removed: int,
     *   person_ids_backfilled: int,
     *   errors: list<array{azure_id: int, error: string}>
     * }
     */
    public function run(): array
    {
        $result = [
            'ok' => true,
            'items_upserted' => 0,
            'items_updated_to_final' => 0,
            'items_skipped_final_new' => 0,
            'items_skipped_already_final' => 0,
            'items_reconciled' => 0,
            'items_removed' => 0,
            'person_ids_backfilled' => 0,
            'errors' => [],
        ];

        $wiql = $this->wiql ?? AzureDevOpsClient::DEFAULT_SYNC_WIQL;
        $items = $this->client->fetchWorkItems($wiql);

        /** @var array<int, true> */
        $apiIdsSet = [];
        foreach ($items as $item) {
            $azureId = (int) ($item['id'] ?? 0);
            if ($azureId > 0) {
                $apiIdsSet[$azureId] = true;
            }
            $this->countSyncResult(
                $result,
                $this->workItemsRepo->applySyncItem(
                    $item,
                    $this->resolvePersonId($item),
                    $this->finalStates
                )
            );
        }

        $toReconcile = $this->workItemsRepo->listForReconciliation($apiIdsSet, $this->finalStates);
        $reconciled = 0;
        foreach ($toReconcile as $row) {
            if ($reconciled >= $this->reconcileLimit) {
                break;
            }
            $azureId = (int) ($row['azure_id'] ?? 0);
            if ($azureId <= 0) {
                continue;
            }

            try {
                $item = $this->client->fetchWorkItemById($azureId);
                if ($item === null) {
                    if ($this->workItemsRepo->markRemoved($azureId)) {
                        $result['items_removed']++;
                    }
                    $reconciled++;
                    continue;
                }

                $this->countSyncResult(
                    $result,
                    $this->workItemsRepo->applySyncItem(
                        $item,
                        $this->resolvePersonId($item),
                        $this->finalStates
                    )
                );
                $result['items_reconciled']++;
                $reconciled++;
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'azure_id' => $azureId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $result['person_ids_backfilled'] = $this->workItemsRepo->backfillPersonIds($this->peopleRepo);

        if ($result['errors'] !== [] && $result['items_upserted'] === 0 && $result['items_reconciled'] === 0) {
            $result['ok'] = false;
        }

        return $result;
    }

    /**
     * @param array{
     *   assigned_to?: string,
     *   assigned_unique_name?: string
     * } $item
     */
    private function resolvePersonId(array $item): ?int
    {
        $upn = trim((string) ($item['assigned_unique_name'] ?? ''));
        if ($upn !== '') {
            return $this->peopleRepo->findPersonIdByEmail($upn);
        }

        $name = trim((string) ($item['assigned_to'] ?? ''));
        if ($name !== '' && str_contains($name, '@')) {
            return $this->peopleRepo->findPersonIdByEmail($name);
        }

        return null;
    }

    /**
     * @param array{
     *   ok: bool,
     *   items_upserted: int,
     *   items_updated_to_final: int,
     *   items_skipped_final_new: int,
     *   items_skipped_already_final: int,
     *   items_reconciled: int,
     *   items_removed: int,
     *   errors: list<array{azure_id: int, error: string}>
     * } $result
     */
    private function countSyncResult(array &$result, string $outcome): void
    {
        switch ($outcome) {
            case 'inserted':
            case 'updated':
                $result['items_upserted']++;
                break;
            case 'updated_to_final':
                $result['items_upserted']++;
                $result['items_updated_to_final']++;
                break;
            case 'skipped_final_new':
                $result['items_skipped_final_new']++;
                break;
            case 'skipped_already_final':
                $result['items_skipped_already_final']++;
                break;
        }
    }
}
