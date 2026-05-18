<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\BirthdayNormalizer;
use PDO;

final class TeamPersonRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function create(
        int $teamId,
        string $displayName,
        ?string $email,
        ?string $role,
        ?string $birthday,
        ?string $extraInfo,
        ?int $axisAutonomyProblemSolving = null,
        ?int $axisImpactScope = null,
        ?int $axisInfluenceMentorship = null,
        ?int $axisBusinessCommunication = null,
        ?int $axisTechnicalCompetence = null,
        bool $isDirectTeam = false
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_people (
                team_id, display_name, email, role, birthday, extra_info,
                axis_autonomy_problem_solving, axis_impact_scope, axis_influence_mentorship,
                axis_business_communication, axis_technical_competence, is_direct_team
             ) VALUES (
                :tid, :name, :email, :role, :birthday, :extra,
                :axis_ap, :axis_is, :axis_im, :axis_bc, :axis_tc, :direct
             )'
        );
        $stmt->execute([
            'tid' => $teamId,
            'name' => trim($displayName),
            'email' => $email !== null && trim($email) !== '' ? trim($email) : null,
            'role' => $role !== null && trim($role) !== '' ? trim($role) : null,
            'birthday' => $birthday,
            'extra' => $extraInfo !== null && trim($extraInfo) !== '' ? trim($extraInfo) : null,
            'axis_ap' => $axisAutonomyProblemSolving,
            'axis_is' => $axisImpactScope,
            'axis_im' => $axisInfluenceMentorship,
            'axis_bc' => $axisBusinessCommunication,
            'axis_tc' => $axisTechnicalCompetence,
            'direct' => $isDirectTeam ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $displayName,
        ?string $email,
        ?string $role,
        ?string $birthday,
        ?string $extraInfo,
        ?int $axisAutonomyProblemSolving,
        ?int $axisImpactScope,
        ?int $axisInfluenceMentorship,
        ?int $axisBusinessCommunication,
        ?int $axisTechnicalCompetence,
        bool $isDirectTeam = false
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE team_people SET
                display_name = :name,
                email = :email,
                role = :role,
                birthday = :birthday,
                extra_info = :extra,
                axis_autonomy_problem_solving = :axis_ap,
                axis_impact_scope = :axis_is,
                axis_influence_mentorship = :axis_im,
                axis_business_communication = :axis_bc,
                axis_technical_competence = :axis_tc,
                is_direct_team = :direct
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => trim($displayName),
            'email' => $email !== null && trim($email) !== '' ? trim($email) : null,
            'role' => $role !== null && trim($role) !== '' ? trim($role) : null,
            'birthday' => $birthday,
            'extra' => $extraInfo !== null && trim($extraInfo) !== '' ? trim($extraInfo) : null,
            'axis_ap' => $axisAutonomyProblemSolving,
            'axis_is' => $axisImpactScope,
            'axis_im' => $axisInfluenceMentorship,
            'axis_bc' => $axisBusinessCommunication,
            'axis_tc' => $axisTechnicalCompetence,
            'direct' => $isDirectTeam ? 1 : 0,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'display_name' => (string) $row['display_name'],
            'email' => isset($row['email']) && $row['email'] !== null && $row['email'] !== ''
                ? (string) $row['email']
                : null,
            'role' => isset($row['role']) && $row['role'] !== null && trim((string) $row['role']) !== ''
                ? trim((string) $row['role'])
                : null,
            'birthday' => BirthdayNormalizer::canonicalMonthDay($row['birthday'] ?? null),
            'extra_info' => isset($row['extra_info']) && $row['extra_info'] !== null && $row['extra_info'] !== ''
                ? (string) $row['extra_info']
                : null,
            'axis_autonomy_problem_solving' => $this->mapAxisColumn($row['axis_autonomy_problem_solving'] ?? null),
            'axis_impact_scope' => $this->mapAxisColumn($row['axis_impact_scope'] ?? null),
            'axis_influence_mentorship' => $this->mapAxisColumn($row['axis_influence_mentorship'] ?? null),
            'axis_business_communication' => $this->mapAxisColumn($row['axis_business_communication'] ?? null),
            'axis_technical_competence' => $this->mapAxisColumn($row['axis_technical_competence'] ?? null),
            'is_direct_team' => $this->mapDirectTeamColumn($row['is_direct_team'] ?? null),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param mixed $raw */
    private function mapAxisColumn($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return max(0, min(10, (int) $raw));
    }

    /** @param mixed $raw */
    private function mapDirectTeamColumn($raw): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }

        return (int) $raw !== 0;
    }

    /**
     * @return array{
     *   id:int,
     *   team_id:int,
     *   display_name:string,
     *   email:?string,
     *   role:?string,
     *   birthday:?string,
     *   extra_info:?string,
     *   axis_autonomy_problem_solving:?int,
     *   axis_impact_scope:?int,
     *   axis_influence_mentorship:?int,
     *   axis_business_communication:?int,
     *   axis_technical_competence:?int,
     *   is_direct_team:bool,
     *   created_at:string
     * }|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, display_name, email, role, birthday, extra_info,
                    axis_autonomy_problem_solving, axis_impact_scope, axis_influence_mentorship,
                    axis_business_communication, axis_technical_competence, is_direct_team, created_at
             FROM team_people WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByTeam(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, display_name, email, role, birthday, extra_info,
                    axis_autonomy_problem_solving, axis_impact_scope, axis_influence_mentorship,
                    axis_business_communication, axis_technical_competence, is_direct_team, created_at
             FROM team_people
             WHERE team_id = :tid
             ORDER BY display_name COLLATE NOCASE ASC'
        );
        $stmt->execute(['tid' => $teamId]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[] = $this->mapRow($row);
        }

        return $out;
    }

    public function belongsToTeam(int $personId, int $teamId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM team_people WHERE id = :id AND team_id = :tid LIMIT 1'
        );
        $stmt->execute(['id' => $personId, 'tid' => $teamId]);

        return $stmt->fetch() !== false;
    }
}
