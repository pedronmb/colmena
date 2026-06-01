<?php

declare(strict_types=1);

namespace App\Support;

final class AzureDevOpsFinalStates
{
    /** @var list<string> */
    public const DEFAULT_FINAL_STATES = [
        'Complete',
        'Done',
        'Removed',
        'Closed',
        'Completed',
    ];

    /** @var list<string> */
    private $finalStates;

    /**
     * @param list<string>|null $override null = lista por defecto
     */
    public function __construct(?array $override = null)
    {
        if ($override === null || $override === []) {
            $this->finalStates = self::DEFAULT_FINAL_STATES;

            return;
        }

        $normalized = [];
        foreach ($override as $state) {
            if (!is_string($state)) {
                continue;
            }
            $trimmed = trim($state);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }
        $this->finalStates = $normalized !== [] ? $normalized : self::DEFAULT_FINAL_STATES;
    }

    public function isFinal(?string $state): bool
    {
        if ($state === null || trim($state) === '') {
            return false;
        }
        $needle = strtolower(trim($state));
        foreach ($this->finalStates as $final) {
            if (strtolower($final) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return $this->finalStates;
    }

    /**
     * Placeholders para SQL: LOWER(state) NOT IN (?,?,...)
     */
    public function sqlNotInPlaceholders(): string
    {
        return implode(',', array_fill(0, count($this->finalStates), '?'));
    }

    /**
     * Valores en minúsculas para bind en consultas case-insensitive.
     *
     * @return list<string>
     */
    public function namesLower(): array
    {
        return array_map(
            static fn (string $s): string => strtolower($s),
            $this->finalStates
        );
    }
}
