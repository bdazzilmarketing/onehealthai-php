<?php

namespace App\Ai;

use App\Support\JsRegex;

/**
 * One clicked choice in the AI assistant (port of src/lib/ai-choice.server.ts).
 * No OpenAI call and no quota reservation: the click is applied
 * deterministically to the visitor-owned saved selection, revalidated against
 * the published catalog, and saved with the same compare-and-set as a model turn.
 */
final class ChoiceHandler
{
    public function __construct(
        private readonly Quota $quota,
        private readonly Sessions $sessions,
        private readonly SafeCatalog $catalogs,
    ) {}

    public static function toWorkingState(array $v): array
    {
        return [
            'labs' => array_map(fn (array $lab) => [
                'labName' => $lab['labName'],
                'sections' => array_map(fn (array $s) => [
                    'sectionName' => $s['sectionName'],
                    'hardwareOnly' => $s['hardwareOnly'],
                    'annualTestVolume' => $s['annualTestVolume'],
                    'includeHardware' => $s['includeHardware'],
                    'hardwareNames' => $s['hardwareNames'],
                    'parameterNames' => $s['parameterNames'],
                ], $lab['sections']),
            ], $v['labs']),
            'missingFields' => $v['missingFields'],
            'conversationStatus' => 'collecting',
        ];
    }

    /** The conversation continues in Arabic when the last answer was in Arabic. */
    private static function language(array $history): string
    {
        $last = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'assistant') {
                $last = $history[$i]['content'];
                break;
            }
        }

        return JsRegex::test('[\u0600-\u06ff]', '', $last) ? 'ar' : 'en';
    }

    /**
     * Opening cards (laboratory list) or the next step of an existing conversation.
     *
     * @param  array{sessionId: ?string, plan?: mixed}  $data
     * @return array{picker: ?array}
     */
    public function picker(AiRequest $ctx, array $data): array
    {
        try {
            $catalog = $this->catalogs->load();
        } catch (\Throwable) {
            return ['picker' => null];
        }
        $state = SelectionValidation::validate(null, $catalog);
        $lang = 'en';
        if ($data['sessionId']) {
            try {
                $caller = $this->quota->identifyCaller($ctx);
                $session = $this->sessions->get($data['sessionId'], $caller['visitorHash']);
                if ($session !== null) {
                    $state = SelectionValidation::validate($session['proposed'], $catalog);
                    $lang = self::language($session['history']);
                }
            } catch (\Throwable) {
                // fall back to the opening cards
            }
        }

        return ['picker' => Picker::build($state, $catalog, $lang, null, Picker::sanitizePlan($data['plan'] ?? null, $catalog))];
    }

    /** @param  array{sessionId: ?string, choice: mixed, plan?: mixed}  $data */
    public function choose(AiRequest $ctx, array $data): array
    {
        $unavailable = function () use ($ctx): array {
            $ctx->status = 503;

            return ['ok' => false, 'code' => 'unavailable', 'message' => TurnHandler::UNAVAILABLE_MESSAGE, 'retryAfterSeconds' => null];
        };
        $invalid = function () use ($ctx): array {
            $ctx->status = 400;

            return ['ok' => false, 'code' => 'invalid', 'message' => 'That option is no longer available. Please choose again.'];
        };

        $choice = Picker::parseChoice($data['choice']);
        if ($choice === null) {
            return $invalid();
        }

        try {
            $caller = $this->quota->identifyCaller($ctx);
            $catalog = $this->catalogs->load();
            $session = $this->sessions->getOrCreate($data['sessionId'], $caller['visitorHash']);
        } catch (\Throwable) {
            return $unavailable();
        }
        if (count($catalog['labs']) === 0) {
            return $unavailable();
        }

        $lang = self::language($session['history']);
        $prior = SelectionValidation::validate($session['proposed'], $catalog);
        $applied = Picker::apply($prior, $catalog, $choice, $lang, Picker::sanitizePlan($data['plan'] ?? null, $catalog));
        if ($applied === null) {
            return $invalid();
        }

        $picker = Picker::build($applied['state'], $catalog, $lang, $applied['focus'], $applied['plan']);
        $reply = $picker['prompt'] ?? '';
        // The click and the canned next question enter history so a typed follow-up
        // has context; neither carries a pending-volume line, so no stale volume binds.
        $session['proposed'] = Reconciliation::rawState($applied['state']);
        $session['history'][] = ['role' => 'user', 'content' => $applied['echo']];
        if ($reply !== '') {
            $session['history'][] = ['role' => 'assistant', 'content' => $reply];
        }
        try {
            $this->sessions->save($session, $caller['visitorHash']);
        } catch (SessionConflict) {
            $ctx->status = 409;

            return ['ok' => false, 'code' => 'session', 'message' => 'This conversation was updated by another request. Please choose again.'];
        } catch (\Throwable) {
            return $unavailable();
        }

        return [
            'ok' => true,
            'sessionId' => $session['id'],
            'echo' => $applied['echo'],
            'reply' => $reply,
            'state' => self::toWorkingState($applied['state']),
            'picker' => $picker,
            'plan' => $applied['plan'],
        ];
    }
}
