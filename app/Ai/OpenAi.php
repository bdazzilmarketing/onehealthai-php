<?php

namespace App\Ai;

use App\Database\Db;
use App\Database\Tx;
use App\Support\Js;

/**
 * Direct server-side OpenAI integration (port of src/lib/ai-openai.server.ts).
 *
 * SECURITY: this class may only ever be given data produced by SafeCatalog
 * (non-pricing columns) and the visitor's own messages. It must never use the
 * calculation engine, the priced dataset loader, or anything that can resolve
 * a monetary value.
 */
final class OpenAi
{
    public const TIMEOUT_MS = 30_000;

    /** @param  \Closure(string): mixed  $env */
    public function __construct(private readonly Db $db, private readonly \Closure $env) {}

    public function hasKey(): bool
    {
        return (bool) ($this->env)('OPENAI_API_KEY');
    }

    /**
     * Active AI configuration: model and system prompt are never hard-coded.
     * An unreadable configuration means "no active prompt" (AI mode unavailable).
     *
     * @return array{promptVersionId: string, promptVersionNumber: int, promptText: string, model: string}|null
     */
    public function activeConfig(): ?array
    {
        try {
            $row = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                "select id, version_number, prompt_text, model from public.ai_prompt_versions
                 where status = 'active' order by version_number desc limit 1"));
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            return null;
        }

        return [
            'promptVersionId' => $row['id'],
            'promptVersionNumber' => (int) $row['version_number'],
            'promptText' => $row['prompt_text'],
            'model' => $row['model'],
        ];
    }

    public static function guardrails(): string
    {
        return Data::get('GUARDRAILS');
    }

    /**
     * @param  array<string, true>  $expandedSectionKeys
     * @param  array<string, true>  $relevant
     */
    private static function sectionContext(array $catalog, array $expandedSectionKeys, array $relevant): string
    {
        $bool = fn (mixed $value) => $value ? 'true' : 'false';
        $lines = [];
        foreach ($catalog['labs'] as $lab) {
            $lines[] = "LAB {$lab['labName']} [labId={$lab['labId']}] type={$lab['labType']} flat={$bool($lab['flat'])}";
            foreach ($lab['sections'] as $section) {
                $lines[] = "  SECTION {$section['name']} [subLabId=".($section['subLabId'] ?? 'null')."] hardwareOnly={$bool($section['hardwareOnly'])} supportsHardware={$bool($section['supportsHardware'])}";
                foreach ($section['categories'] as $category) {
                    $lines[] = "    CATEGORY {$category['name']} [id={$category['id']}]";
                }
                foreach ($section['hardware'] as $hw) {
                    $lines[] = "    HARDWARE {$hw['name']} [id={$hw['id']}]"
                        .(! empty($hw['groupId']) ? " group={$hw['groupId']}" : '')
                        .(! empty($hw['categoryId']) ? " category={$hw['categoryId']}" : '');
                }
                foreach ($section['groups'] as $group) {
                    $lines[] = "    GROUP {$group['name']} [id={$group['id']}] parameters=".count($group['parameterIds']);
                }
                $total = count($section['parameters']);
                if ($total === 0) {
                    continue;
                }
                $anyRelevant = Reconciliation::any($section['parameters'], fn (array $p) => isset($relevant[$p['id']]));
                if (isset($expandedSectionKeys[$section['key']]) || $total <= 40 || $anyRelevant) {
                    foreach ($section['parameters'] as $p) {
                        if ($total <= 40 || isset($relevant[$p['id']])) {
                            $lines[] = '    PARAMETER '.Js::stringify($p['name'])." [id={$p['id']}]"
                                .(! empty($p['groupId']) ? " group={$p['groupId']}" : '')
                                .(($p['notes'] ?? '') !== '' && $p['notes'] !== null ? ' note='.Js::stringify($p['notes']) : '');
                        }
                    }
                } else {
                    $lines[] = "    PARAMETERS: {$total} available — ask the user about this section to see the full list.";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Builds the exact payload sent to OpenAI. Public so pricing-isolation
     * tests can assert that no monetary field can ever appear in it.
     *
     * @param  array{config: array, catalog: array, history: list<array{role: string, content: string}>, state: array, expandedSectionKeys: array<string, true>, maxOutputTokens?: ?int}  $args
     * @return array{model: string, instructions: string, input: list<array>, text: array, store: false, max_output_tokens: int}
     */
    public static function buildPayload(array $args): array
    {
        $state = $args['state'];
        $selected = [];
        $labs = [];
        foreach ($state['labs'] as $lab) {
            $sections = [];
            foreach ($lab['sections'] as $s) {
                $sections[] = [
                    'subLabId' => $s['subLabId'],
                    'sectionName' => $s['sectionName'],
                    'annualTestVolume' => $s['annualTestVolume'],
                    'includeHardware' => $s['includeHardware'],
                    'selectedHardwareIds' => $s['selectedHardwareIds'],
                    'selectedParameterIds' => $s['selectedParameterIds'],
                ];
                foreach ($s['selectedParameterIds'] as $id) {
                    $selected[$id] = true;
                }
            }
            $labs[] = ['labId' => $lab['labId'], 'labName' => $lab['labName'], 'sections' => $sections];
        }
        $stateSummary = [
            'labs' => $labs,
            'missingFields' => $state['missingFields'],
            'conversationStatus' => $state['complete'] ? 'ready' : 'collecting',
        ];
        $history = $args['history'];
        $last = $history === [] ? '' : $history[count($history) - 1]['content'];

        $instructions = implode("\n\n", [
            Js::trim($args['config']['promptText']),
            self::guardrails(),
            Data::get('LABORATORY_RESPONSE_INSTRUCTIONS'),
            'CATALOG (published data only — the sole source of valid IDs):',
            self::sectionContext(
                $args['catalog'],
                $args['expandedSectionKeys'],
                CatalogMatching::targetedCatalog($args['catalog'], $last, $selected)['parameterIds'],
            ),
            'CURRENT STATE (server-validated, authoritative):',
            Js::stringify($stateSummary),
        ]);

        return [
            'model' => $args['config']['model'],
            'instructions' => $instructions,
            'input' => array_map(fn (array $m) => [
                'role' => $m['role'],
                'content' => [['type' => $m['role'] === 'user' ? 'input_text' : 'output_text', 'text' => $m['content']]],
            ], $history),
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'lab_configuration_turn',
                    'strict' => true,
                    'schema' => LabContract::jsonSchema(),
                ],
            ],
            'store' => false,
            'max_output_tokens' => $args['maxOutputTokens'] ?? 4000,
        ];
    }

    /**
     * @return array{turn: array, latencyMs: int, usage: ?array{input: mixed, output: mixed}, responseId: ?string}
     *
     * @throws AiCallError
     */
    public function call(array $payload): array
    {
        $apiKey = ($this->env)('OPENAI_API_KEY');
        if (! $apiKey) {
            throw new AiCallError('AI_NOT_CONFIGURED', 'not_configured');
        }

        $started = microtime(true);
        $response = ResponsesApi::post($apiKey, $payload, intdiv(self::TIMEOUT_MS, 1000));
        if ($response['failure'] !== null) {
            $timedOut = $response['failure'] === 'timeout';
            throw new AiCallError($timedOut ? 'AI_TIMEOUT' : 'AI_UNAVAILABLE', $timedOut ? 'timeout' : 'network');
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            // Provider internals are never surfaced to the browser.
            AiLog::write('[ai] openai request failed', ['status' => $response['status'], 'stage' => 'generation_http']);
            throw new AiCallError('AI_UNAVAILABLE', "http_{$response['status']}");
        }

        $answer = ResponsesApi::read($response['body']);
        if ($answer === null) {
            throw new AiCallError('AI_UNAVAILABLE', 'invalid_response');
        }
        $turn = LabContract::parseJson($answer['text']);
        if ($turn === null) {
            // No candidate content, parser errors or provider text is retained/logged.
            AiLog::write('[ai] structured output rejected', ['stage' => 'local_schema']);
            throw new AiCallError('AI_UNAVAILABLE', 'invalid_output', $answer['usage']);
        }

        return [
            'turn' => $turn,
            'latencyMs' => (int) round((microtime(true) - $started) * 1000),
            'usage' => $answer['usage'],
            'responseId' => $answer['id'],
        ];
    }
}
