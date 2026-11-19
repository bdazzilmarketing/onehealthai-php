<?php

namespace Tests\Unit;

use App\Ai\ActionParser;
use App\Ai\CatalogMatching;
use App\Ai\Classifier;
use App\Ai\ExactSelection;
use App\Ai\Guard;
use App\Ai\LabContract;
use App\Ai\Language;
use App\Ai\OpenAi;
use App\Ai\Picker;
use App\Ai\QaDiagnostics;
use App\Ai\Quota;
use App\Ai\Reconciliation;
use App\Ai\Reviewer;
use App\Ai\SelectionValidation;
use App\Ai\TurnAccounting;
use App\Ai\VolumeParser;
use App\Support\Js;
use PHPUnit\Framework\TestCase;

/**
 * Results recorded from the original TypeScript AI modules
 * (tests/Fixtures/ai-parity.json.gz): every string in the original AI tests,
 * plus extra edge cases, run through the scope guard, catalog matching, the
 * action and volume parsers, reconciliation, reply rendering and the cards.
 * The PHP port must give the same answer for each.
 */
final class AiParityTest extends TestCase
{
    private static array $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = json_decode(gzdecode(file_get_contents(dirname(__DIR__).'/Fixtures/ai-parity.json.gz')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function fixture(): array
    {
        return self::$fixture;
    }

    private function same(mixed $expected, mixed $actual, string $what): void
    {
        $this->assertSame(Js::stringify($expected), Js::stringify($actual), $what);
    }

    public function test_scope_guard_matches_the_original_for_every_recorded_message(): void
    {
        $ctx = self::$fixture['ctx'];
        $this->assertGreaterThan(1500, count(self::$fixture['pure']));
        foreach (self::$fixture['pure'] as $case) {
            $m = $case['m'];
            $label = json_encode(mb_substr($m, 0, 80), JSON_UNESCAPED_UNICODE);
            $this->assertSame($case['classify'], Guard::classifyInputDeterministic($m), "classify {$label}");
            $this->same($case['detect'], Guard::detectLanguage($m), "detectLanguage {$label}");
            $this->same($case['output'], Guard::validateAiOutput($m, $ctx, null), "validateAiOutput {$label}");
            $this->assertSame($case['med'], Guard::medicalAdviceHit($m), "medicalAdviceHit {$label}");
            $this->assertSame($case['creative'], Guard::looksCreative($m), "looksCreative {$label}");
            $this->assertSame($case['code'], Guard::codeRuleHit($m), "codeRuleHit {$label}");
            $this->assertSame($case['leaked'], Guard::leakedWordCount($m, $ctx['confidential']), "leakedWordCount {$label}");
            $this->assertSame($case['normalise'], Guard::normalise($m), "normalise {$label}");
            $this->assertSame($case['analytes'], Guard::analytesIn($m), "analytesIn {$label}");
        }
        foreach (self::$fixture['leak'] as $case) {
            $this->assertSame($case['n'], Guard::leakedWordCount($case['r'], $ctx['confidential']));
            $this->same($case['v'], Guard::validateAiOutput($case['r'], $ctx, null), 'leak verdict');
        }
        foreach (self::$fixture['scopes'] as $case) {
            $this->same($case['v'], Guard::validateAiOutput($case['r'], $ctx, $case['s']), 'scope verdict');
        }
    }

    public function test_catalog_matching_and_parsers_match_the_original(): void
    {
        $catalog = self::$fixture['catalog'];
        $names = self::$fixture['allNames'];
        foreach (self::$fixture['pure'] as $case) {
            $m = $case['m'];
            $label = json_encode(mb_substr($m, 0, 80), JSON_UNESCAPED_UNICODE);
            $this->assertSame($case['norm'], CatalogMatching::normalizeLabText($m), "normalizeLabText {$label}");
            $this->assertSame($case['terms'], CatalogMatching::laboratoryTerms($m), "laboratoryTerms {$label}");
            $this->same($case['actions'], ActionParser::parse($m), "parseLabActions {$label}");
            $this->same($case['volume'], VolumeParser::parse($m), "parseRequestedVolume {$label}");
            $this->same($case['exact'], ExactSelection::resolve($m, $catalog), "resolveExactSelection {$label}");
            $this->assertSame($case['named'], CatalogMatching::exactlyNamedItems($m, $names), "exactlyNamedItems {$label}");
            $this->assertSame($case['matches'], array_map(fn (string $n) => CatalogMatching::matchesCatalogName($m, $n), $names), "matchesCatalogName {$label}");
            $targeted = CatalogMatching::targetedCatalog($catalog, $m, []);
            $this->same($case['targeted'], array_map(array_keys(...), $targeted), "targetedCatalog {$label}");
            $this->assertSame($case['lang'], [
                Language::conversationLanguage($m, '', $names),
                Language::conversationLanguage($m, 'هل تريد متابعة هذا الإعداد أم تغيير شيء؟', $names),
            ], "conversationLanguage {$label}");
        }
    }

    public function test_selection_validation_and_the_answer_contract_match_the_original(): void
    {
        $catalog = self::$fixture['catalog'];
        foreach (self::$fixture['validation'] as $index => $case) {
            $this->same($case['out'], SelectionValidation::validate($case['raw'], $catalog), "validateAiSelection case {$index}");
        }
        foreach (self::$fixture['contract'] as $index => $case) {
            $parsed = LabContract::parseJson($case['json']);
            $this->assertSame($case['ok'], $parsed !== null, "contract case {$index}: {$case['json']}");
            if ($case['ok']) {
                $this->same($case['data'], $parsed, "contract case {$index}");
            }
        }
        $this->assertNull(LabContract::parseJson('not json'));
        foreach (self::$fixture['pending'] as $case) {
            $this->same($case['v'], Reconciliation::pendingVolumeFrom($case['r']), 'pendingVolumeFrom');
        }
    }

    public function test_reconciliation_and_replies_match_the_original(): void
    {
        $catalog = self::$fixture['catalog'];
        $names = self::$fixture['allNames'];
        $this->assertGreaterThan(3000, count(self::$fixture['reconcile']));
        foreach (self::$fixture['reconcile'] as $index => $case) {
            $label = "reconcile {$index} ".json_encode(mb_substr($case['m'], 0, 80), JSON_UNESCAPED_UNICODE);
            $prior = SelectionValidation::validate($case['prior'], $catalog);
            $turn = $case['turn'];
            $r = Reconciliation::reconcileTurn($prior, $turn, $catalog, $case['m'], $case['ref']);
            $this->same($case['out'], $r, $label);
            $this->assertSame($case['kind'], Reconciliation::deriveResponseKind($r, $turn), "{$label}: kind");
            $this->assertSame($case['lang'], Language::conversationLanguage($case['m'], $case['ref'], $names), "{$label}: language");
            $this->assertSame($case['reply'], Reconciliation::renderLaboratoryReply($prior, $r, $turn, $case['lang']), "{$label}: reply");
            $this->assertSame($case['replyOther'], Reconciliation::renderLaboratoryReply($prior, $r, $turn, $case['lang'] === 'ar' ? 'en' : 'ar'), "{$label}: other language");
            $this->assertSame($case['confirmed'], Reconciliation::hasConfirmedConfiguration($prior, $r['state'], $case['m'], $case['ref']), "{$label}: confirmed");
        }
    }

    public function test_a_whole_conversation_matches_the_original_turn_by_turn(): void
    {
        $catalog = self::$fixture['catalog'];
        $names = self::$fixture['allNames'];
        $state = ['labs' => []];
        $last = '';
        foreach (self::$fixture['conversation'] as $index => $step) {
            $prior = SelectionValidation::validate($state, $catalog);
            $turn = ['scope' => 'laboratory', 'status' => 'collecting', 'selection' => Reconciliation::rawState($prior),
                'response' => ['kind' => 'configuration_summary', 'question' => 'none', 'parameterIds' => [], 'hardwareIds' => []]];
            $r = Reconciliation::reconcileTurn($prior, $turn, $catalog, $step['m'], $last);
            $lang = Language::conversationLanguage($step['m'], $last, $names);
            $reply = Reconciliation::renderLaboratoryReply($prior, $r, $turn, $lang);
            $this->assertSame($step['lang'], $lang, "turn {$index}: language");
            $this->assertSame($step['reply'], $reply, "turn {$index}: reply");
            $this->same($step['state'], Reconciliation::rawState($r['state']), "turn {$index}: state");
            $this->same([$step['issues'], $step['options'], $step['pendingVolume']], [$r['issues'], $r['options'], $r['pendingVolume']], "turn {$index}");
            $this->same($step['verdict'], Guard::validateAiOutput($reply, self::$fixture['ctx'], null), "turn {$index}: verdict");
            $state = Reconciliation::rawState($r['state']);
            $last = $reply;
        }
        $this->assertNotSame(['labs' => []], $state);
    }

    public function test_cards_match_the_original_on_every_recorded_walk(): void
    {
        $catalog = self::$fixture['rich'];
        foreach (self::$fixture['walks'] as $w => $walk) {
            foreach ($walk['steps'] as $k => $step) {
                $label = "walk {$w} step {$k}";
                $state = SelectionValidation::validate($step['raw'], $catalog);
                $this->same($step['picker'], Picker::build($state, $catalog, $walk['lang'], $step['focus'], $step['plan']), "{$label}: picker");
                $parsed = Picker::parseChoice($step['choice']);
                $this->same($step['parsed'], $parsed, "{$label}: parsed choice");
                $applied = $parsed === null ? null : Picker::apply($state, $catalog, $parsed, $walk['lang'], $step['plan']);
                $this->same($step['applied'], $applied, "{$label}: applied ".json_encode($step['choice']));
            }
        }
        foreach (self::$fixture['choiceShapes'] as $case) {
            $this->same($case['parsed'], Picker::parseChoice($case['c']), 'parseChoice '.json_encode($case['c']));
        }
        foreach (self::$fixture['plans'] as $case) {
            $this->same($case['out'], Picker::sanitizePlan($case['p'], $catalog), 'sanitizePlan');
        }
        $section = $catalog['labs'][0]['sections'][0];
        $this->assertSame(self::$fixture['hw']['all'], Picker::testsForHardware($section, array_column($section['hardware'], 'id')));
        $this->assertSame(self::$fixture['hw']['one'], Picker::testsForHardware($section, [$section['hardware'][1]['id']]));
        $this->assertNull(Picker::build(SelectionValidation::validate(null, ['labs' => []]), ['labs' => []], 'en'));
    }

    public function test_openai_payloads_and_safety_context_match_the_original(): void
    {
        $f = self::$fixture;
        $args = $f['payloadArgs'];
        $rich = $f['rich'];
        $richState = SelectionValidation::validate($args['richRaw'], $rich);
        $payloads = [
            OpenAi::buildPayload(['config' => $args['config'], 'catalog' => $rich, 'history' => $args['history'], 'state' => $richState, 'expandedSectionKeys' => [], 'maxOutputTokens' => 1500]),
            OpenAi::buildPayload(['config' => $args['config'], 'catalog' => $rich, 'history' => array_slice($args['history'], 0, 3), 'state' => SelectionValidation::validate(null, $rich), 'expandedSectionKeys' => [$rich['labs'][0]['sections'][0]['key'] => true]]),
            OpenAi::buildPayload(['config' => $args['config'], 'catalog' => $f['catalog'], 'history' => [['role' => 'user', 'content' => $args['message5']]], 'state' => SelectionValidation::validate($args['prior2'], $f['catalog']), 'expandedSectionKeys' => []]),
        ];
        foreach ($payloads as $index => $payload) {
            $this->assertSame($f['payloads'][$index], Js::stringify($payload), "payload {$index}");
            $this->assertDoesNotMatchRegularExpression('/price|cost_|variance|"total"/i', Js::stringify($payload['input']));
        }

        $this->assertSame($f['safety']['context'][0], Classifier::buildSafetyContext($args['history']));
        $this->assertSame($f['safety']['context'][1], Classifier::buildSafetyContext($args['longHistory']));
        $this->assertSame($f['safety']['context'][2], Classifier::buildSafetyContext([]));
        $this->assertSame($f['safety']['classifier'], Js::stringify(Classifier::buildPayload('gpt-test-model', $args['history'], 'final 😀 message')));
        $this->assertSame($f['safety']['classifierEmpty'], Js::stringify(Classifier::buildPayload('m', [], 'x')));
        $this->assertSame($f['safety']['reviewer'], Js::stringify(Reviewer::buildPayload('gpt-test-model', $args['longHistory'], str_repeat('u', 2100), str_repeat('r', 8100))));
        foreach ($f['reviewerOut'] as $case) {
            $this->same($case['out'], Reviewer::parseOutput($case['t']), "reviewer output {$case['t']}");
        }
    }

    public function test_diagnostics_limits_and_accounting_match_the_original(): void
    {
        $f = self::$fixture;
        foreach ($f['diag'] as $index => $case) {
            $this->assertSame($case['json'], Js::stringify(QaDiagnostics::buildRecord($case['input'])), "diagnostic {$index}");
        }
        foreach ($f['qa']['cases'] as $index => $case) {
            $env = $case['env'];
            $this->assertSame($case['active'], QaDiagnostics::active($env['token'] ?? null, $env['expiresAt'] ?? null, $env['header'] ?? null, (float) $f['qa']['now']), "qa {$index}");
        }
        foreach ($f['limits'] as $case) {
            $this->assertSame($case['m'], Quota::friendlyLimitMessage($case['s']));
        }
        foreach ($f['estimates'] as $case) {
            $this->assertSame($case['e'], Quota::estimateTokens($case['c'], $case['o']));
        }
        $a = new TurnAccounting;
        $a->add('classifier', ['input' => 10, 'output' => 2], 50);
        $a->add('generation', null, null);
        $a->add('reviewer', null, 70);
        $this->same($f['acct'], ['total' => $a->total(), 'input' => $a->inputTokens(), 'output' => $a->outputTokens(), 'summary' => $a->summary()], 'accounting');
        $b = new TurnAccounting;
        $b->add('classifier', ['input' => -5], 50);
        $b->add('generation', ['output' => 30], null);
        $b->add('reviewer', [], 70);
        $this->same($f['acct2'], ['total' => $b->total(), 'input' => $b->inputTokens(), 'output' => $b->outputTokens(), 'summary' => $b->summary()], 'accounting 2');
    }
}
