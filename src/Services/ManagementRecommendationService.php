<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ManagementRecommendationRepository;
use App\Repositories\PersonManagementRecommendationRepository;
use App\Repositories\TeamRepository;
use App\Support\ManagementPeriod;
use App\Support\OllamaJsonParser;

final class ManagementRecommendationService
{
    private const MAX_CONTEXT_CHARS = 18000;
    private const MAX_PERSON_CONTEXT_CHARS = 10000;

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
            $ollama['timeout']
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
                    'scope' => 'all',
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
        $parsed = array_merge($overview, $focus);

        $this->teamRecRepo->upsertOk($teamId, $period['period_start'], $period['period_end'], [
            'summary' => $parsed['summary'],
            'executive_bullets_json' => json_encode($parsed['executive_bullets'], JSON_UNESCAPED_UNICODE),
            'risks_json' => json_encode($parsed['risks'], JSON_UNESCAPED_UNICODE),
            'actions_json' => json_encode($parsed['actions'], JSON_UNESCAPED_UNICODE),
            'people_focus_json' => json_encode($parsed['people_focus'], JSON_UNESCAPED_UNICODE),
            'topics_focus_json' => json_encode($parsed['topics_focus'], JSON_UNESCAPED_UNICODE),
            'delegations_json' => json_encode($parsed['delegations'], JSON_UNESCAPED_UNICODE),
            'one_on_one_json' => json_encode($parsed['one_on_one'], JSON_UNESCAPED_UNICODE),
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
                    true,
                    'management-team-' . $teamId . '-person-' . $personId
                );
                $parsed = $this->parsePersonResponse($raw);

                $this->personRecRepo->upsertOk(
                    $teamId,
                    $personId,
                    $period['period_start'],
                    $period['period_end'],
                    [
                        'summary' => $parsed['summary'],
                        'risk_level' => $parsed['risk_level'],
                        'situation_json' => json_encode($parsed['situation'], JSON_UNESCAPED_UNICODE),
                        'risks_json' => json_encode($parsed['risks'], JSON_UNESCAPED_UNICODE),
                        'suggested_actions_json' => json_encode($parsed['suggested_actions'], JSON_UNESCAPED_UNICODE),
                        'one_on_one_questions_json' => json_encode($parsed['one_on_one_questions'], JSON_UNESCAPED_UNICODE),
                        'blockers_json' => json_encode($parsed['blockers'], JSON_UNESCAPED_UNICODE),
                        'pentagon_note' => $parsed['pentagon_note'],
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
     * Llamada 1/2: resumen ejecutivo, riesgos y acciones.
     *
     * @param array<string, mixed> $snapshot
     * @return array{
     *   summary: string,
     *   executive_bullets: list<string>,
     *   risks: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>
     * }
     */
    private function generateTeamOverview(array $snapshot, int $teamId): array
    {
        $raw = $this->client->generate(
            $this->buildTeamOverviewPrompt($snapshot),
            true,
            'management-team-' . $teamId . '-overview'
        );

        return $this->parseTeamOverviewResponse($raw);
    }

    /**
     * Llamada 2/2: personas, temas, delegaciones y 1:1.
     *
     * @param array<string, mixed> $snapshot
     * @param array{summary: string, executive_bullets: list<string>, risks: list<array<string, mixed>>, actions: list<array<string, mixed>>} $overview
     * @return array{
     *   people_focus: list<array<string, mixed>>,
     *   topics_focus: list<array<string, mixed>>,
     *   delegations: list<array<string, mixed>>,
     *   one_on_one: list<array<string, mixed>>
     * }
     */
    private function generateTeamFocus(array $snapshot, array $overview, int $teamId): array
    {
        $raw = $this->client->generate(
            $this->buildTeamFocusPrompt($snapshot, $overview),
            true,
            'management-team-' . $teamId . '-focus'
        );

        return $this->parseTeamFocusResponse($raw);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function buildTeamOverviewPrompt(array $snapshot): string
    {
        $contextJson = $this->encodeContext($snapshot, self::MAX_CONTEXT_CHARS);

        return "Actuá como copiloto de management de un equipo técnico.\n"
            . "Analizá la información JSON del equipo y respondé ÚNICAMENTE con JSON válido (sin markdown ni texto extra).\n\n"
            . "Estructura exacta de respuesta:\n"
            . "{\n"
            . "  \"executive_bullets\": [\"...\"],\n"
            . "  \"summary\": \"...\",\n"
            . "  \"risks\": [{\"title\":\"...\",\"rationale\":\"...\",\"severity\":\"high|medium|low\"}],\n"
            . "  \"actions\": [{\"title\":\"...\",\"rationale\":\"...\",\"suggested_person_id\":null,\"priority\":\"high|medium|low\"}]\n"
            . "}\n\n"
            . "Reglas:\n"
            . "- Escribir en español.\n"
            . "- executive_bullets: máximo 8 ítems.\n"
            . "- No inventar datos; si falta una fuente (invgate/devops), indicarlo.\n"
            . "- Cada riesgo y acción DEBE incluir rationale con métricas del JSON (auditable).\n"
            . "- Priorizá recomendaciones accionables.\n\n"
            . "Datos del equipo:\n"
            . $contextJson;
    }

    /**
     * @param array{summary: string, executive_bullets: list<string>, risks: list<array<string, mixed>>, actions: list<array<string, mixed>>} $overview
     * @param array<string, mixed> $snapshot
     */
    private function buildTeamFocusPrompt(array $snapshot, array $overview): string
    {
        $contextJson = $this->encodeContext($snapshot, self::MAX_CONTEXT_CHARS);
        $prior = json_encode([
            'summary' => $overview['summary'],
            'executive_bullets' => $overview['executive_bullets'],
            'risks_count' => count($overview['risks']),
            'actions_count' => count($overview['actions']),
        ], JSON_UNESCAPED_UNICODE);
        if ($prior === false) {
            $prior = '{}';
        }

        return "Actuá como copiloto de management de un equipo técnico.\n"
            . "Completá la segunda parte del análisis. Ya existe un borrador de resumen/riesgos/acciones (JSON abajo); mantené coherencia.\n"
            . "Respondé ÚNICAMENTE con JSON válido (sin markdown ni texto extra).\n\n"
            . "Estructura exacta de respuesta:\n"
            . "{\n"
            . "  \"people_focus\": [{\"person_id\":1,\"name\":\"...\",\"rationale\":\"...\",\"signals\":[\"...\"]}],\n"
            . "  \"topics_focus\": [{\"topic_id\":1,\"title\":\"...\",\"rationale\":\"...\"}],\n"
            . "  \"delegations\": [{\"topic_id\":1,\"from_person_id\":1,\"to_person_id\":5,\"rationale\":\"...\"}],\n"
            . "  \"one_on_one\": [{\"person_id\":1,\"questions\":[\"...\"]}]\n"
            . "}\n\n"
            . "Reglas:\n"
            . "- Escribir en español.\n"
            . "- No inventar datos; si falta una fuente, indicarlo.\n"
            . "- Cada ítem DEBE incluir rationale con métricas del JSON del equipo (auditable).\n"
            . "- people_focus: personas que requieren atención del líder.\n"
            . "- topics_focus: temas críticos o sin avance.\n\n"
            . "Borrador previo (solo contexto, no repetir en la respuesta):\n"
            . $prior . "\n\n"
            . "Datos del equipo:\n"
            . $contextJson;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function buildPersonPrompt(array $snapshot): string
    {
        $contextJson = $this->encodeContext($snapshot, self::MAX_PERSON_CONTEXT_CHARS);
        $name = is_array($snapshot['person'] ?? null)
            ? (string) (($snapshot['person']['name'] ?? '') ?: 'Persona')
            : 'Persona';

        return "Actuá como copiloto de management para la persona {$name}.\n"
            . "Analizá el JSON y respondé ÚNICAMENTE con JSON válido (sin markdown).\n\n"
            . "Estructura exacta:\n"
            . "{\n"
            . "  \"summary\": \"...\",\n"
            . "  \"risk_level\": \"low|medium|high\",\n"
            . "  \"situation\": {\"current\":\"...\",\"rationale\":\"...\"},\n"
            . "  \"risks\": [{\"title\":\"...\",\"rationale\":\"...\"}],\n"
            . "  \"blockers\": [{\"title\":\"...\",\"rationale\":\"...\"}],\n"
            . "  \"one_on_one_questions\": [\"...\"],\n"
            . "  \"suggested_actions\": [{\"title\":\"...\",\"rationale\":\"...\"}],\n"
            . "  \"pentagon_reading\": \"...\"\n"
            . "}\n\n"
            . "Reglas:\n"
            . "- Español; no inventar datos.\n"
            . "- rationale obligatorio citando métricas del JSON.\n"
            . "- pentagon_reading: basado en ejes actuales; sin histórico, indicar que no hay evolución temporal.\n\n"
            . "Datos:\n"
            . $contextJson;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function encodeContext(array $snapshot, int $maxChars): string
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return '{}';
        }
        if (strlen($json) > $maxChars) {
            $json = substr($json, 0, $maxChars - 20) . "\n...(truncado)";
        }

        return $json;
    }

    /**
     * @return array{
     *   summary: string,
     *   executive_bullets: list<string>,
     *   risks: list<array<string, mixed>>,
     *   actions: list<array<string, mixed>>
     * }
     */
    private function parseTeamOverviewResponse(string $raw): array
    {
        $decoded = OllamaJsonParser::decodeToArray($raw);

        $bullets = $this->pickStringList($decoded, [['executive_bullets']]);
        $summary = $this->pickString($decoded, [['summary'], ['resumen']]);
        if ($summary === '' && $bullets !== []) {
            $summary = implode(' ', array_slice($bullets, 0, 3));
        }

        return [
            'summary' => $this->cutText($summary, 3000),
            'executive_bullets' => array_slice($bullets, 0, 8),
            'risks' => $this->pickObjectList($decoded, [['risks'], ['riesgos']]),
            'actions' => $this->pickObjectList($decoded, [['actions'], ['acciones']]),
        ];
    }

    /**
     * @return array{
     *   people_focus: list<array<string, mixed>>,
     *   topics_focus: list<array<string, mixed>>,
     *   delegations: list<array<string, mixed>>,
     *   one_on_one: list<array<string, mixed>>
     * }
     */
    private function parseTeamFocusResponse(string $raw): array
    {
        $decoded = OllamaJsonParser::decodeToArray($raw);

        return [
            'people_focus' => $this->pickObjectList($decoded, [['people_focus'], ['personas']]),
            'topics_focus' => $this->pickObjectList($decoded, [['topics_focus'], ['temas']]),
            'delegations' => $this->pickObjectList($decoded, [['delegations'], ['delegaciones']]),
            'one_on_one' => $this->pickObjectList($decoded, [['one_on_one'], ['one_on_ones']]),
        ];
    }

    /**
     * @return array{
     *   summary: string,
     *   risk_level: ?string,
     *   situation: array<string, mixed>,
     *   risks: list<array<string, mixed>>,
     *   blockers: list<array<string, mixed>>,
     *   one_on_one_questions: list<string>,
     *   suggested_actions: list<array<string, mixed>>,
     *   pentagon_note: ?string
     * }
     */
    private function parsePersonResponse(string $raw): array
    {
        $decoded = OllamaJsonParser::decodeToArray($raw);

        $riskLevel = $this->pickString($decoded, [['risk_level'], ['nivel_riesgo']]);
        if (!in_array($riskLevel, ['low', 'medium', 'high'], true)) {
            $riskLevel = null;
        }

        $situation = $this->pickObject($decoded, [['situation'], ['situacion']]);
        $questions = $this->pickStringList($decoded, [['one_on_one_questions'], ['preguntas_1_1']]);
        $pentagon = $this->pickString($decoded, [['pentagon_reading'], ['pentagon_note']]);

        return [
            'summary' => $this->cutText($this->pickString($decoded, [['summary'], ['resumen']]), 2500),
            'risk_level' => $riskLevel,
            'situation' => $situation,
            'risks' => $this->pickObjectList($decoded, [['risks'], ['riesgos']]),
            'blockers' => $this->pickObjectList($decoded, [['blockers'], ['bloqueos']]),
            'one_on_one_questions' => array_slice($questions, 0, 12),
            'suggested_actions' => $this->pickObjectList($decoded, [['suggested_actions'], ['acciones']]),
            'pentagon_note' => $pentagon !== '' ? $this->cutText($pentagon, 1500) : null,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<list<string>> $paths
     */
    private function pickString(array $data, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $this->valueByPath($data, $path);
            if ($value === null) {
                continue;
            }
            if (is_string($value) || is_numeric($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @param list<list<string>> $paths
     * @return list<string>
     */
    private function pickStringList(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $this->valueByPath($data, $path);
            if (!is_array($value)) {
                continue;
            }
            $out = [];
            foreach ($value as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $t = trim((string) $item);
                    if ($t !== '') {
                        $out[] = $t;
                    }
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<list<string>> $paths
     * @return list<array<string, mixed>>
     */
    private function pickObjectList(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $this->valueByPath($data, $path);
            if (!is_array($value)) {
                continue;
            }
            $out = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<list<string>> $paths
     * @return array<string, mixed>
     */
    private function pickObject(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = $this->valueByPath($data, $path);
            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     * @return mixed
     */
    private function valueByPath(array $data, array $path)
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    private function cutText(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) {
            return $text;
        }

        return rtrim(substr($text, 0, $maxLen - 1)) . '…';
    }
}
