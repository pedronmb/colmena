<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Agrupa equipo directo bajo encargados según organigrama (reports_to_id).
 */
final class ManagementOrgGrouping
{
    /**
     * @param list<array{
     *   id: int,
     *   display_name: string,
     *   role: ?string,
     *   is_direct_team: bool,
     *   is_encargado: bool,
     *   reports_to_id: ?int
     * }> $people
     * @return array{
     *   encargados: list<array{person_id: int, name: string, role: ?string}>,
     *   org_by_encargado: list<array{
     *     encargado_id: int,
     *     encargado_name: string,
     *     members: list<array{person_id: int, name: string, role: ?string}>
     *   }>,
     *   direct_team_unassigned: list<array{person_id: int, name: string, role: ?string}>
     * }
     */
    public static function build(array $people): array
    {
        /** @var array<int, array{id: int, display_name: string, role: ?string, is_direct_team: bool, is_encargado: bool, reports_to_id: ?int}> */
        $byId = [];
        foreach ($people as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $byId[$id] = [
                'id' => $id,
                'display_name' => (string) ($row['display_name'] ?? ''),
                'role' => isset($row['role']) && $row['role'] !== '' ? (string) $row['role'] : null,
                'is_direct_team' => !empty($row['is_direct_team']),
                'is_encargado' => !empty($row['is_encargado']),
                'reports_to_id' => isset($row['reports_to_id']) && $row['reports_to_id'] !== null
                    ? (int) $row['reports_to_id']
                    : null,
            ];
        }

        $encargados = [];
        foreach ($byId as $person) {
            if (!$person['is_encargado']) {
                continue;
            }
            $encargados[] = [
                'person_id' => $person['id'],
                'name' => $person['display_name'],
                'role' => $person['role'],
            ];
        }

        usort(
            $encargados,
            static fn (array $a, array $b): int => strcmp($a['name'], $b['name'])
        );

        /** @var array<int, true> */
        $assignedDirect = [];
        $orgByEncargado = [];

        foreach ($encargados as $enc) {
            $encId = (int) $enc['person_id'];
            $members = [];
            foreach ($byId as $person) {
                if (!$person['is_direct_team'] || $person['id'] === $encId) {
                    continue;
                }
                if (!self::isDescendantOf($person['id'], $encId, $byId)) {
                    continue;
                }
                $assignedDirect[$person['id']] = true;
                $members[] = [
                    'person_id' => $person['id'],
                    'name' => $person['display_name'],
                    'role' => $person['role'],
                ];
            }

            usort(
                $members,
                static fn (array $a, array $b): int => strcmp($a['name'], $b['name'])
            );

            $orgByEncargado[] = [
                'encargado_id' => $encId,
                'encargado_name' => (string) $enc['name'],
                'members' => $members,
            ];
        }

        $unassigned = [];
        foreach ($byId as $person) {
            if (!$person['is_direct_team'] || isset($assignedDirect[$person['id']])) {
                continue;
            }
            if ($person['is_encargado']) {
                continue;
            }
            $unassigned[] = [
                'person_id' => $person['id'],
                'name' => $person['display_name'],
                'role' => $person['role'],
            ];
        }

        usort(
            $unassigned,
            static fn (array $a, array $b): int => strcmp($a['name'], $b['name'])
        );

        return [
            'encargados' => $encargados,
            'org_by_encargado' => $orgByEncargado,
            'direct_team_unassigned' => $unassigned,
        ];
    }

    /**
     * @param array<int, array{id: int, display_name: string, role: ?string, is_direct_team: bool, is_encargado: bool, reports_to_id: ?int}> $byId
     */
    private static function isDescendantOf(int $personId, int $ancestorId, array $byId): bool
    {
        $current = $personId;
        $guard = 0;
        while ($guard < 64) {
            $guard++;
            if (!isset($byId[$current])) {
                return false;
            }
            $parentId = $byId[$current]['reports_to_id'];
            if ($parentId === null || $parentId < 1) {
                return false;
            }
            if ($parentId === $ancestorId) {
                return true;
            }
            $current = $parentId;
        }

        return false;
    }
}
