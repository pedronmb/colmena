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
        bool $isDirectTeam = false,
        ?int $invgateId = null,
        ?int $reportsToId = null,
        bool $isEncargado = false
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO team_people (
                team_id, display_name, email, role, invgate_id, birthday, extra_info,
                axis_autonomy_problem_solving, axis_impact_scope, axis_influence_mentorship,
                axis_business_communication, axis_technical_competence, is_direct_team,
                is_encargado, reports_to_id
             ) VALUES (
                :tid, :name, :email, :role, :invgate_id, :birthday, :extra,
                :axis_ap, :axis_is, :axis_im, :axis_bc, :axis_tc, :direct, :encargado, :reports_to
             )'
        );
        $stmt->execute([
            'tid' => $teamId,
            'name' => trim($displayName),
            'email' => $email !== null && trim($email) !== '' ? trim($email) : null,
            'role' => $role !== null && trim($role) !== '' ? trim($role) : null,
            'invgate_id' => $invgateId,
            'birthday' => $birthday,
            'extra' => $extraInfo !== null && trim($extraInfo) !== '' ? trim($extraInfo) : null,
            'axis_ap' => $axisAutonomyProblemSolving,
            'axis_is' => $axisImpactScope,
            'axis_im' => $axisInfluenceMentorship,
            'axis_bc' => $axisBusinessCommunication,
            'axis_tc' => $axisTechnicalCompetence,
            'direct' => $isDirectTeam ? 1 : 0,
            'encargado' => $isEncargado ? 1 : 0,
            'reports_to' => $reportsToId,
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
        bool $isDirectTeam = false,
        ?int $invgateId = null,
        ?int $reportsToId = null,
        bool $isEncargado = false
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE team_people SET
                display_name = :name,
                email = :email,
                role = :role,
                invgate_id = :invgate_id,
                birthday = :birthday,
                extra_info = :extra,
                axis_autonomy_problem_solving = :axis_ap,
                axis_impact_scope = :axis_is,
                axis_influence_mentorship = :axis_im,
                axis_business_communication = :axis_bc,
                axis_technical_competence = :axis_tc,
                is_direct_team = :direct,
                is_encargado = :encargado,
                reports_to_id = :reports_to
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => trim($displayName),
            'email' => $email !== null && trim($email) !== '' ? trim($email) : null,
            'role' => $role !== null && trim($role) !== '' ? trim($role) : null,
            'invgate_id' => $invgateId,
            'birthday' => $birthday,
            'extra' => $extraInfo !== null && trim($extraInfo) !== '' ? trim($extraInfo) : null,
            'axis_ap' => $axisAutonomyProblemSolving,
            'axis_is' => $axisImpactScope,
            'axis_im' => $axisInfluenceMentorship,
            'axis_bc' => $axisBusinessCommunication,
            'axis_tc' => $axisTechnicalCompetence,
            'direct' => $isDirectTeam ? 1 : 0,
            'encargado' => $isEncargado ? 1 : 0,
            'reports_to' => $reportsToId,
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
            'invgate_id' => $this->mapOptionalIntColumn($row['invgate_id'] ?? null),
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
            'is_encargado' => $this->mapDirectTeamColumn($row['is_encargado'] ?? null),
            'reports_to_id' => $this->mapOptionalIntColumn($row['reports_to_id'] ?? null),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param mixed $raw */
    private function mapOptionalIntColumn($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
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
     *   invgate_id:?int,
     *   birthday:?string,
     *   extra_info:?string,
     *   axis_autonomy_problem_solving:?int,
     *   axis_impact_scope:?int,
     *   axis_influence_mentorship:?int,
     *   axis_business_communication:?int,
     *   axis_technical_competence:?int,
     *   is_direct_team:bool,
     *   is_encargado:bool,
     *   reports_to_id:?int,
     *   created_at:string
     * }|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, team_id, display_name, email, role, invgate_id, birthday, extra_info,
                    axis_autonomy_problem_solving, axis_impact_scope, axis_influence_mentorship,
                    axis_business_communication, axis_technical_competence, is_direct_team,
                    is_encargado, reports_to_id, created_at
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
            'SELECT id, team_id, display_name, email, role, invgate_id, birthday, extra_info,
                    axis_autonomy_problem_solving, axis_impact_scope, axis_influence_mentorship,
                    axis_business_communication, axis_technical_competence, is_direct_team,
                    is_encargado, reports_to_id, created_at
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

    /**
     * Personas con ID de agente InvGate para sincronización de tickets.
     *
     * @return list<array{id:int, invgate_id:int, display_name:string}>
     */
    public function listWithInvgateId(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, invgate_id, display_name
             FROM team_people
             WHERE invgate_id IS NOT NULL
             ORDER BY display_name COLLATE NOCASE ASC'
        );
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $invgateId = isset($row['invgate_id']) ? (int) $row['invgate_id'] : 0;
            if ($invgateId <= 0) {
                continue;
            }
            $out[] = [
                'id' => (int) $row['id'],
                'invgate_id' => $invgateId,
                'display_name' => (string) ($row['display_name'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Devuelve el ID de persona local cuyo email coincide (sin distinguir mayúsculas).
     * Si hay varias coincidencias, devuelve la primera por id ascendente.
     */
    public function findPersonIdByEmail(string $email): ?int
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM team_people
             WHERE email IS NOT NULL
               AND LOWER(TRIM(email)) = LOWER(TRIM(:email))
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $personId = (int) $id;

        return $personId > 0 ? $personId : null;
    }

    /**
     * Coincidencia de email/UPN limitada a un equipo (para listados DevOps).
     */
    public function findPersonIdByEmailInTeam(int $teamId, string $email): ?int
    {
        if ($teamId <= 0) {
            return null;
        }
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM team_people
             WHERE team_id = :team_id
               AND email IS NOT NULL
               AND LOWER(TRIM(email)) = LOWER(TRIM(:email))
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute(['team_id' => $teamId, 'email' => $email]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $personId = (int) $id;

        return $personId > 0 ? $personId : null;
    }

    /**
     * Devuelve el ID de persona local dado un ID de agente InvGate.
     */
    public function findPersonIdByInvgateId(int $invgateId): ?int
    {
        if ($invgateId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id
             FROM team_people
             WHERE invgate_id = :invgate_id
             LIMIT 1'
        );
        $stmt->execute(['invgate_id' => $invgateId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        $personId = (int) $id;

        return $personId > 0 ? $personId : null;
    }

    /**
     * Valida reports_to_id para crear o actualizar una persona.
     * Devuelve mensaje de error o null si es válido.
     */
    public function validateReportsTo(int $teamId, int $personId, ?int $reportsToId): ?string
    {
        if ($reportsToId === null) {
            return null;
        }

        if ($personId > 0 && $reportsToId === $personId) {
            return 'Una persona no puede reportar a sí misma.';
        }

        if (!$this->belongsToTeam($reportsToId, $teamId)) {
            return 'El superior debe pertenecer al mismo equipo.';
        }

        if ($personId > 0 && $this->wouldCreateCycle($personId, $reportsToId)) {
            return 'La dependencia crearía un ciclo en el organigrama.';
        }

        return null;
    }

    private function wouldCreateCycle(int $personId, int $reportsToId): bool
    {
        $current = $reportsToId;
        $visited = [];

        while ($current !== null) {
            if ($current === $personId) {
                return true;
            }
            if (isset($visited[$current])) {
                return true;
            }
            $visited[$current] = true;
            $current = $this->findReportsToId($current);
        }

        return false;
    }

    private function findReportsToId(int $personId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT reports_to_id FROM team_people WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $personId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
