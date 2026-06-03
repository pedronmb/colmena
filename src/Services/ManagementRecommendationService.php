<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ManagementRecommendationRepository;
use App\Repositories\PersonManagementRecommendationRepository;
use App\Repositories\TeamRepository;
use App\Support\ManagementPeriod;

final class ManagementRecommendationService
{
    private const MAX_CONTEXT_CHARS = 12000;
    private const MAX_FOCUS_CONTEXT_CHARS = 8000;
    private const MAX_PRIOR_REPORT_CHARS = 3500;
    private const MAX_PERSON_CONTEXT_CHARS = 8000;
    private const MAX_TEAM_REPORT_CHARS = 12000;
    private const MAX_PERSON_REPORT_CHARS = 8000;

    /** @var OllamaClient */
    private $client;

    /** @var string */
    private $model;

    /** @var ManagementContextBuilder */
    private $contextBuilder;

    /** @var ManagementRecommendationRepository */
    private $teamRecRepo;

    /** @var PersonManagementRecommendationRepository */
    private $personRecRepo;

    /** @var TeamRepository */
    private $teamsRepo;

    public function __construct(
        OllamaClient $client,
        string $model,
        ManagementContextBuilder $contextBuilder,
        ManagementRecommendationRepository $teamRecRepo,
        PersonManagementRecommendationRepository $personRecRepo,
        TeamRepository $teamsRepo
    ) {
        $this->client = $client;
        $this->model = $model;
        $this->contextBuilder = $contextBuilder;
        $this->teamRecRepo = $teamRecRepo;
        $this->personRecRepo = $personRecRepo;
        $this->teamsRepo = $teamsRepo;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(
        array $config,
        ManagementContextBuilder $contextBuilder,
        ManagementRecommendationRepository $teamRecRepo,
        PersonManagementRecommendationRepository $personRecRepo,
        TeamRepository $teamsRepo
    ): self {
        $ollama = InvgateRecommendationService::parseOllamaConfig($config);
        $client = new OllamaClient(
            $ollama['base_url'],
            $ollama['model'],
            $ollama['timeout'],
            $ollama['num_predict']
        );

        return new self(
            $client,
            $ollama['model'],
            $contextBuilder,
            $teamRecRepo,
            $personRecRepo,
            $teamsRepo
        );
    }

    /**
     * @param array{team_id?: int, stale_days?: int} $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $filterTeamId = isset($options['team_id']) ? (int) $options['team_id'] : 0;
        $staleDays = max(1, (int) ($options['stale_days'] ?? 3));
        $period = ManagementPeriod::currentWeek();

        $teams = $this->teamsRepo->listAll();
        if ($filterTeamId > 0) {
            $teams = array_values(array_filter(
                $teams,
                static fn (array $t): bool => (int) $t['id'] === $filterTeamId
            ));
        }

        $result = [
            'ok' => true,
            'teams_total' => count($teams),
            'teams_skipped' => 0,
            'teams_processed' => 0,
            'teams_ok' => 0,
            'teams_failed' => 0,
            'people_ok' => 0,
            'people_failed' => 0,
            'people_skipped' => 0,
            'errors' => [],
        ];

        foreach ($teams as $team) {
            $teamId = (int) $team['id'];
            if ($teamId < 1) {
                continue;
            }

            try {
                $snapshot = $this->contextBuilder->buildTeamSnapshot($teamId, [
                    'stale_days' => $staleDays,
                    'scope' => 'direct',
                ]);
                $hash = $this->contextBuilder->contextHash($snapshot);

                $existingHash = $this->teamRecRepo->getContextHash(
                    $teamId,
                    $period['period_start']
                );
                $existing = $this->teamRecRepo->findForTeamPeriod(
                    $teamId,
                    $period['period_start']
                );
                if (
                    $existing !== null
                    && ($existing['status'] ?? '') === 'ok'
                    && $existingHash === $hash
                ) {
                    $result['teams_skipped']++;
                    continue;
                }

                $result['teams_processed']++;
                $this->generateTeam($teamId, $period, $snapshot, $hash, $result);
                $this->generatePeopleForTeam($teamId, $period, $snapshot, $result);
            } catch (\Throwable $e) {
                $result['teams_failed']++;
                $result['errors'][] = [
                    'team_id' => $teamId,
                    'scope' => 'team',
                    'error' => $e->getMessage(),
                ];
                try {
                    $this->teamRecRepo->upsertError(
                        $teamId,
                        $period['period_start'],
                        $period['period_end'],
                        $e->getMessage(),
                        $this->model
                    );
                } catch (\Throwable $_) {
                }
            }
        }

        if ($result['teams_failed'] > 0 && $result['teams_ok'] === 0 && $result['teams_skipped'] === 0) {
            $result['ok'] = false;
        }

        return $result;
    }

    /**
     * @param array{period_start: string, period_end: string} $period
     * @param array<string, mixed> $result
     */
    private function generateTeam(
        int $teamId,
        array $period,
        array $snapshot,
        string $hash,
        array &$result
    ): void {
        $overview = $this->generateTeamOverview($snapshot, $teamId);
        $focus = $this->generateTeamFocus($snapshot, $overview, $teamId);

        $fullReport = trim($overview['body']);
        $focusBody = trim($focus['body']);
        if ($focusBody !== '') {
            $fullReport = $fullReport !== ''
                ? $fullReport . "\n\n---\n\n" . $focusBody
                : $focusBody;
        }

        $this->teamRecRepo->upsertOk($teamId, $period['period_start'], $period['period_end'], [
            'summary' => $this->cutText($fullReport, self::MAX_TEAM_REPORT_CHARS),
            'executive_bullets_json' => '[]',
            'risks_json' => '[]',
            'actions_json' => '[]',
            'people_focus_json' => '[]',
            'topics_focus_json' => '[]',
            'delegations_json' => '[]',
            'one_on_one_json' => '[]',
            'context_hash' => $hash,
        ], $this->model);

        $result['teams_ok']++;
    }

    /**
     * @param array{period_start: string, period_end: string} $period
     * @param array<string, mixed> $teamSnapshot
     * @param array<string, mixed> $result
     */
    private function generatePeopleForTeam(
        int $teamId,
        array $period,
        array $teamSnapshot,
        array &$result
    ): void {
        foreach ($this->contextBuilder->personIdsForGeneration($teamSnapshot) as $personId) {
            try {
                $personSnapshot = $this->contextBuilder->buildPersonSnapshot($teamSnapshot, $personId);
                $personHash = hash('sha256', json_encode($personSnapshot, JSON_UNESCAPED_UNICODE) ?: '');

                $existingHash = $this->personRecRepo->getContextHash(
                    $teamId,
                    $personId,
                    $period['period_start']
                );
                $existing = $this->personRecRepo->findForPersonPeriod(
                    $teamId,
                    $personId,
                    $period['period_start']
                );
                if (
                    $existing !== null
                    && ($existing['status'] ?? '') === 'ok'
                    && $existingHash === $personHash
                ) {
                    $result['people_skipped']++;
                    continue;
                }

                $prompt = $this->buildPersonPrompt($personSnapshot);
                $raw = $this->client->generate(
                    $prompt,
                    false,
                    'management-team-' . $teamId . '-person-' . $personId
                );
                $parsed = $this->parsePersonResponse($raw);

                $this->personRecRepo->upsertOk(
                    $teamId,
                    $personId,
                    $period['period_start'],
                    $period['period_end'],
                    [
                        'summary' => $this->cutText($parsed['body'], self::MAX_PERSON_REPORT_CHARS),
                        'risk_level' => $parsed['risk_level'],
                        'situation_json' => '{}',
                        'risks_json' => '[]',
                        'suggested_actions_json' => '[]',
                        'one_on_one_questions_json' => '[]',
                        'blockers_json' => '[]',
                        'pentagon_note' => null,
                        'context_hash' => $personHash,
                    ],
                    $this->model
                );
                $result['people_ok']++;
            } catch (\Throwable $e) {
                $result['people_failed']++;
                $result['errors'][] = [
                    'team_id' => $teamId,
                    'person_id' => $personId,
                    'scope' => 'person',
                    'error' => $e->getMessage(),
                ];
                try {
                    $this->personRecRepo->upsertError(
                        $teamId,
                        $personId,
                        $period['period_start'],
                        $period['period_end'],
                        $e->getMessage(),
                        $this->model
                    );
                } catch (\Throwable $_) {
                }
            }
        }
    }

    /**
     * Llamada 1/2: resumen ejecutivo, riesgos y acciones (markdown).
     *
     * @param array<string, mixed> $snapshot
     * @return array{body: string}
     */
    private function generateTeamOverview(array $snapshot, int $teamId): array
    {
        $raw = $this->client->generate(
            $this->buildTeamOverviewPrompt($snapshot),
            false,
            'management-team-' . $teamId . '-overview'
        );

        return $this->parseProseResponse($raw);
    }

    /**
     * Llamada 2/2: personas, temas y delegaciones (markdown).
     *
     * @param array<string, mixed> $snapshot
     * @param array{body: string} $overview
     * @return array{body: string}
     */
    private function generateTeamFocus(array $snapshot, array $overview, int $teamId): array
    {
        $raw = $this->client->generate(
            $this->buildTeamFocusPrompt($snapshot, $overview),
            false,
            'management-team-' . $teamId . '-focus'
        );

        return $this->parseProseResponse($raw);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function buildTeamOverviewPrompt(array $snapshot): string
    {
        $contextJson = $this->encodeContext(
            $this->snapshotForPrompt($snapshot),
            self::MAX_CONTEXT_CHARS
        );

        return "Sos un copiloto de management de un equipo técnico. Te paso datos del equipo directo en JSON.\n"
            . $this->audienceInstruction($snapshot)
            . "Respondé en español con markdown. Esta es la PRIMERA parte del informe semanal.\n"
            . "Incluí estas secciones con encabezados ## exactos:\n"
            . "## Resumen ejecutivo\n"
            . "(bullets o párrafo corto con lo más importante de la semana para el equipo directo)\n"
            . "## Panorama operativo\n"
            . "(síntesis cruzada: tickets InvGate, work items DevOps y temas Colmena; correlacioná fuentes)\n"
            . "## Riesgos detectados\n"
            . "(cada riesgo con título en negrita y línea **Por qué:** citando métricas del JSON)\n"
            . "## Acciones recomendadas\n"
            . "(acciones concretas con **Por qué:** auditable)\n\n"
            . "Reglas:\n"
            . "- Analizá ÚNICAMENTE el equipo directo del JSON; los colaboradores quedaron fuera del alcance.\n"
            . "- Priorizá el análisis cruzado entre topics, tickets_open_sample/tickets_sample y work_items_sample.\n"
            . "- No inventar datos; si falta una fuente (invgate/devops/temas), indicarlo.\n"
            . "- Cada riesgo y acción DEBE incluir **Por qué:** con métricas del JSON.\n"
            . "- Priorizá recomendaciones accionables.\n"
            . "- No uses bloques de código ni JSON en la respuesta.\n\n"
            . "Datos del equipo:\n"
            . $contextJson;
    }

    /**
     * @param array{body: string} $overview
     * @param array<string, mixed> $snapshot
     */
    private function buildTeamFocusPrompt(array $snapshot, array $overview): string
    {
        $contextJson = $this->encodeContext(
            $this->snapshotForPrompt($snapshot),
            self::MAX_FOCUS_CONTEXT_CHARS
        );
        $prior = $this->cutText(trim($overview['body']), self::MAX_PRIOR_REPORT_CHARS);

        return "Sos un copiloto de management de un equipo técnico. Te paso datos del equipo directo en JSON.\n"
            . $this->audienceInstruction($snapshot)
            . "Respondé en español con markdown. Esta es la SEGUNDA parte del informe semanal.\n"
            . "NO repitas el resumen, panorama operativo, riesgos ni acciones de la primera parte; mantené coherencia.\n"
            . "Incluí estas secciones con encabezados ## exactos:\n"
            . "## Personas a revisar\n"
            . "(agrupá por rama usando org_by_encargado: bajo cada encargado, nombre y señales con **Por qué:**; "
            . "si hay direct_team_unassigned, mencionarlo y sugerir completar organigrama)\n"
            . "## Temas críticos\n"
            . "(título o id del tema si aplica, **Por qué:** vinculando temas con tickets/devops de la persona)\n"
            . "## Delegaciones sugeridas\n"
            . "(opcional si no hay candidatos; incluir **Por qué:**)\n\n"
            . "Reglas:\n"
            . "- Analizá ÚNICAMENTE el equipo directo; no menciones colaboradores externos.\n"
            . "- Usá org_by_encargado para reflejar cómo se reparte el equipo según el organigrama.\n"
            . "- Priorizá correlación entre topics, tickets y work_items_sample por persona.\n"
            . "- No inventar datos; si falta una fuente, indicarlo.\n"
            . "- Cada ítem DEBE incluir **Por qué:** con métricas del JSON del equipo.\n"
            . "- No uses bloques de código ni JSON en la respuesta.\n\n"
            . "Primera parte del informe (solo contexto, no repetir):\n"
            . $prior . "\n\n"
            . "Datos del equipo:\n"
            . $contextJson;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function buildPersonPrompt(array $snapshot): string
    {
        $contextJson = $this->encodeContext(
            $this->personSnapshotForPrompt($snapshot),
            self::MAX_PERSON_CONTEXT_CHARS
        );
        $name = is_array($snapshot['person'] ?? null)
            ? (string) (($snapshot['person']['name'] ?? '') ?: 'Persona')
            : 'Persona';

        return "Sos un copiloto de management para la persona {$name}. Te paso sus datos en JSON.\n"
            . "Respondé en español con markdown como informe de lectura para el encargado que la supervisa.\n"
            . "Comenzá con una línea: Nivel de riesgo: bajo|medio|alto\n"
            . "Luego un párrafo resumen breve.\n"
            . "Incluí estas secciones con encabezados ##:\n"
            . "## Situación actual\n"
            . "## Riesgos\n"
            . "## Posibles bloqueos\n\n"
            . "Reglas:\n"
            . "- No inventar datos.\n"
            . "- Cruzá temas, tickets y work_items del JSON en el análisis.\n"
            . "- Cada sección debe citar métricas del JSON con **Por qué:** cuando aplique.\n"
            . "- No uses bloques de código ni JSON en la respuesta.\n\n"
            . "Datos de la persona:\n"
            . $contextJson;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function audienceInstruction(array $snapshot): string
    {
        $encargados = is_array($snapshot['encargados'] ?? null) ? $snapshot['encargados'] : [];
        if ($encargados === []) {
            return "El informe es para el líder del equipo (no hay encargados marcados en la ficha; indicá que conviene designarlos).\n";
        }

        $names = [];
        foreach ($encargados as $enc) {
            if (!is_array($enc)) {
                continue;
            }
            $name = trim((string) ($enc['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        if ($names === []) {
            return "El informe es para los encargados del equipo directo.\n";
        }

        return 'Redactá en segunda persona plural dirigido a: ' . implode(', ', $names) . ".\n";
    }

    /**
     * Contexto reducido para Ollama (mismo criterio que InvGate: payload compacto).
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function snapshotForPrompt(array $snapshot): array
    {
        $health = is_array($snapshot['health'] ?? null) ? $snapshot['health'] : [];
        $people = [];
        foreach ($snapshot['people'] ?? [] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $people[] = [
                'person_id' => $person['person_id'] ?? null,
                'name' => $person['name'] ?? '',
                'role' => $person['role'] ?? null,
                'is_direct_team' => $person['is_direct_team'] ?? false,
                'load_score' => $person['load_score'] ?? null,
                'health_score' => $person['health_score'] ?? null,
                'status' => $person['status'] ?? null,
                'load_share_percent' => $person['load_share_percent'] ?? null,
                'metrics' => $person['metrics'] ?? [],
            ];
        }

        return [
            'team_id' => $snapshot['team_id'] ?? null,
            'scope' => $snapshot['scope'] ?? 'direct',
            'historical_note' => $snapshot['historical_note'] ?? '',
            'encargados' => $snapshot['encargados'] ?? [],
            'org_by_encargado' => $snapshot['org_by_encargado'] ?? [],
            'direct_team_unassigned' => $snapshot['direct_team_unassigned'] ?? [],
            'team' => $health['team'] ?? [],
            'meta' => $health['meta'] ?? [],
            'people' => $people,
            'people_profiles' => $snapshot['people_profiles'] ?? [],
            'topics' => $snapshot['topics'] ?? [],
            'stale_topics' => $snapshot['stale_topics'] ?? [],
            'alerts' => $snapshot['alerts'] ?? [],
            'tickets_sample' => $snapshot['tickets_sample'] ?? [],
            'tickets_open_sample' => $snapshot['tickets_open_sample'] ?? [],
            'work_items_sample' => $snapshot['work_items_sample'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function personSnapshotForPrompt(array $snapshot): array
    {
        $person = is_array($snapshot['person'] ?? null) ? $snapshot['person'] : [];
        if ($person !== []) {
            $person = [
                'person_id' => $person['person_id'] ?? null,
                'name' => $person['name'] ?? '',
                'role' => $person['role'] ?? null,
                'is_direct_team' => $person['is_direct_team'] ?? false,
                'load_score' => $person['load_score'] ?? null,
                'health_score' => $person['health_score'] ?? null,
                'status' => $person['status'] ?? null,
                'load_share_percent' => $person['load_share_percent'] ?? null,
                'metrics' => $person['metrics'] ?? [],
            ];
        }

        return [
            'team_id' => $snapshot['team_id'] ?? null,
            'person_id' => $snapshot['person_id'] ?? null,
            'encargados' => $snapshot['encargados'] ?? [],
            'historical_note' => $snapshot['historical_note'] ?? '',
            'team_summary' => $snapshot['team_summary'] ?? [],
            'person' => $person,
            'profile' => $snapshot['profile'] ?? null,
            'topics' => $snapshot['topics'] ?? [],
            'tickets' => $snapshot['tickets'] ?? [],
            'work_items' => $snapshot['work_items'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function encodeContext(array $snapshot, int $maxChars): string
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return '{}';
        }
        if (strlen($json) > $maxChars) {
            $json = substr($json, 0, $maxChars - 20) . '...(truncado)';
        }

        return $json;
    }

    /**
     * @return array{body: string}
     */
    private function parseProseResponse(string $raw): array
    {
        $body = trim($raw);
        if ($body === '') {
            throw new \RuntimeException('Ollama devolvió una respuesta vacía.');
        }

        return ['body' => $body];
    }

    /**
     * @return array{body: string, risk_level: ?string}
     */
    private function parsePersonResponse(string $raw): array
    {
        $parsed = $this->parseProseResponse($raw);

        return [
            'body' => $parsed['body'],
            'risk_level' => $this->extractRiskLevel($parsed['body']),
        ];
    }

    private function extractRiskLevel(string $body): ?string
    {
        $head = substr($body, 0, 500);
        if (preg_match('/Nivel de riesgo:\s*(bajo|medio|alto)/iu', $head, $m) !== 1) {
            return null;
        }

        $level = mb_strtolower(trim($m[1]));
        $map = [
            'bajo' => 'low',
            'medio' => 'medium',
            'alto' => 'high',
        ];

        return $map[$level] ?? null;
    }

    private function cutText(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) {
            return $text;
        }

        return rtrim(substr($text, 0, $maxLen - 1)) . '…';
    }
}
