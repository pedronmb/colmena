<?php

declare(strict_types=1);

namespace App\Support;

final class AzureDevOpsBoards
{
    public const DEFAULT_BOARD_ID = 'develop';

    /** @var array<string, list<string>> estados Azure que se agrupan bajo una columna del tablero */
    public const DEFAULT_STATE_ALIASES = [
        'Backlog / new' => ['Backlog', 'New'],
        'UAT' => ['En Prueba UAT'],
    ];

    /** @var array<string, array{label: string, columns: list<string>}> */
    public const DEFAULT_BOARDS = [
        'develop' => [
            'label' => 'Board Develop',
            'columns' => [
                'Backlog / new',
                'To Do',
                'Blocked',
                'In Progress',
                'To Be Tested',
                'Resolved',
            ],
        ],
        'testing' => [
            'label' => 'Board Testing',
            'columns' => [
                'Backlog / new',
                'To Be Tested',
                'In Testing',
                'Blocked',
                'Resolved',
                'UAT',
            ],
        ],
    ];

    /** @var array<string, array{label: string, columns: list<string>}> */
    private $boards;

    /** @var array<string, list<string>> */
    private $stateAliases;

    private string $defaultBoardId;

    /**
     * @param array<string, mixed>|null $azureDevOpsConfig clave azure_devops de config.php
     */
    public static function resolve(?array $azureDevOpsConfig = null): self
    {
        $boards = self::DEFAULT_BOARDS;
        $defaultBoardId = self::DEFAULT_BOARD_ID;
        $stateAliases = self::DEFAULT_STATE_ALIASES;

        if ($azureDevOpsConfig !== null) {
            if (isset($azureDevOpsConfig['boards']) && is_array($azureDevOpsConfig['boards'])) {
                $merged = self::mergeBoardsConfig($azureDevOpsConfig['boards']);
                if ($merged !== []) {
                    $boards = $merged;
                }
            }
            if (isset($azureDevOpsConfig['state_aliases']) && is_array($azureDevOpsConfig['state_aliases'])) {
                $mergedAliases = self::mergeStateAliases($azureDevOpsConfig['state_aliases']);
                if ($mergedAliases !== []) {
                    $stateAliases = $mergedAliases;
                }
            }
            if (isset($azureDevOpsConfig['default_board']) && is_string($azureDevOpsConfig['default_board'])) {
                $candidate = trim($azureDevOpsConfig['default_board']);
                if ($candidate !== '') {
                    $defaultBoardId = $candidate;
                }
            }
        }

        return new self($boards, $defaultBoardId, $stateAliases);
    }

    /**
     * @param array<string, mixed> $configAliases
     * @return array<string, list<string>>
     */
    private static function mergeStateAliases(array $configAliases): array
    {
        $merged = self::DEFAULT_STATE_ALIASES;
        foreach ($configAliases as $column => $aliases) {
            if (!is_string($column) || trim($column) === '' || !is_array($aliases)) {
                continue;
            }
            $normalized = [];
            foreach ($aliases as $alias) {
                if (!is_string($alias)) {
                    continue;
                }
                $trimmed = trim($alias);
                if ($trimmed !== '') {
                    $normalized[] = $trimmed;
                }
            }
            if ($normalized !== []) {
                $merged[trim($column)] = $normalized;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $configBoards
     * @return array<string, array{label: string, columns: list<string>}>
     */
    private static function mergeBoardsConfig(array $configBoards): array
    {
        $merged = [];
        foreach ($configBoards as $id => $def) {
            if (!is_string($id) || trim($id) === '' || !is_array($def)) {
                continue;
            }
            $boardId = trim($id);
            $default = self::DEFAULT_BOARDS[$boardId] ?? null;

            $label = isset($def['label']) && is_string($def['label']) && trim($def['label']) !== ''
                ? trim($def['label'])
                : ($default['label'] ?? $boardId);

            $columns = self::normalizeColumns(
                isset($def['columns']) && is_array($def['columns']) ? $def['columns'] : null,
                $default['columns'] ?? []
            );

            if ($columns === []) {
                continue;
            }

            $merged[$boardId] = [
                'label' => $label,
                'columns' => $columns,
            ];
        }

        return $merged;
    }

    /**
     * @param list<mixed>|null $columns
     * @param list<string> $fallback
     * @return list<string>
     */
    private static function normalizeColumns(?array $columns, array $fallback): array
    {
        if ($columns === null || $columns === []) {
            return $fallback;
        }

        $normalized = [];
        foreach ($columns as $state) {
            if (!is_string($state)) {
                continue;
            }
            $trimmed = trim($state);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return $normalized !== [] ? $normalized : $fallback;
    }

    /**
     * @param array<string, array{label: string, columns: list<string>}> $boards
     * @param array<string, list<string>> $stateAliases
     */
    private function __construct(array $boards, string $defaultBoardId, array $stateAliases)
    {
        $this->boards = $boards !== [] ? $boards : self::DEFAULT_BOARDS;
        $this->defaultBoardId = isset($this->boards[$defaultBoardId])
            ? $defaultBoardId
            : array_key_first($this->boards);
        $this->stateAliases = $stateAliases !== [] ? $stateAliases : self::DEFAULT_STATE_ALIASES;
    }

    public function defaultBoardId(): string
    {
        return $this->defaultBoardId;
    }

    /**
     * @return list<string>
     */
    public function boardIds(): array
    {
        return array_keys($this->boards);
    }

    public function hasBoard(string $boardId): bool
    {
        return isset($this->boards[$boardId]);
    }

    public function label(string $boardId): string
    {
        return $this->boards[$boardId]['label'] ?? $boardId;
    }

    /**
     * @return list<string>
     */
    public function statesForColumn(string $columnLabel): array
    {
        $states = [$columnLabel];
        if (isset($this->stateAliases[$columnLabel])) {
            foreach ($this->stateAliases[$columnLabel] as $alias) {
                $states[] = $alias;
            }
        }

        return array_values(array_unique($states));
    }

    /**
     * @return array<string, string> estado Azure => columna del tablero
     */
    public function stateToColumnMap(string $boardId): array
    {
        $map = [];
        foreach ($this->columns($boardId) as $columnLabel) {
            foreach ($this->statesForColumn($columnLabel) as $azureState) {
                $map[$azureState] = $columnLabel;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function columns(string $boardId): array
    {
        return $this->boards[$boardId]['columns'] ?? [];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public function boardSummaries(): array
    {
        $out = [];
        foreach ($this->boards as $id => $def) {
            $out[] = [
                'id' => $id,
                'label' => $def['label'],
            ];
        }

        return $out;
    }

    /**
     * @return array{id: string, label: string}
     */
    public function boardMeta(string $boardId): array
    {
        $resolved = $this->hasBoard($boardId) ? $boardId : $this->defaultBoardId;

        return [
            'id' => $resolved,
            'label' => $this->label($resolved),
        ];
    }

    public function resolveBoardId(?string $boardId): string
    {
        if ($boardId !== null && $this->hasBoard($boardId)) {
            return $boardId;
        }

        return $this->defaultBoardId;
    }
}
