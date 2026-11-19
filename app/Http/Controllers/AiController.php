<?php

namespace App\Http\Controllers;

use App\Ai\AiRequest;
use App\Ai\ChoiceHandler;
use App\Ai\OpenAi;
use App\Ai\Quota;
use App\Ai\Reconciliation;
use App\Ai\SafeCatalog;
use App\Ai\SelectionValidation;
use App\Ai\Sessions;
use App\Ai\TurnHandler;
use App\Http\Support\ServerFn;
use App\Support\Js;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The AI assistant endpoints (port of src/lib/ai.functions.ts). The browser
 * never talks to OpenAI directly and never receives pricing of any kind from
 * these endpoints.
 */
final class AiController
{
    public function getAiAvailability(OpenAi $openAi): Response
    {
        return ServerFn::respond(function () use ($openAi) {
            if (! $openAi->hasKey()) {
                return ['available' => false, 'reason' => 'The AI assistant is not configured yet.'];
            }
            if ($openAi->activeConfig() === null) {
                return ['available' => false, 'reason' => 'The AI assistant is not configured yet. Manual Mode remains available.'];
            }

            return ['available' => true, 'reason' => null];
        });
    }

    public function sendAiMessage(Request $request, TurnHandler $turns): Response
    {
        return $this->answer($request, fn (AiRequest $ctx, array $input) => $turns->handle($ctx, [
            'sessionId' => $input['sessionId'],
            // Laboratory order chosen on the cards; sanitised against the catalog server-side.
            'plan' => $input['plan'],
            // Hard ceiling before anything else; the configurable limit is applied by the handler.
            'message' => Js::slice(Js::string($input['raw']['message'] ?? ''), 0, 20001),
        ]));
    }

    /** Clickable cards for the current step. Catalog names and IDs only; no model call. */
    public function getAiPicker(Request $request, ChoiceHandler $choices): Response
    {
        return $this->answer($request, fn (AiRequest $ctx, array $input) => $choices->picker($ctx, [
            'sessionId' => $input['sessionId'], 'plan' => $input['plan'],
        ]));
    }

    /** A clicked card or checklist: applied deterministically, never sent to OpenAI. */
    public function sendAiChoice(Request $request, ChoiceHandler $choices): Response
    {
        return $this->answer($request, fn (AiRequest $ctx, array $input) => $choices->choose($ctx, [
            'sessionId' => $input['sessionId'], 'choice' => $input['raw']['choice'] ?? null, 'plan' => $input['plan'],
        ]));
    }

    public function resetAiSession(Request $request, Quota $quota, Sessions $sessions): Response
    {
        return $this->answer($request, function (AiRequest $ctx, array $input) use ($quota, $sessions) {
            try {
                $caller = $quota->identifyCaller($ctx);
                if ($input['sessionId']) {
                    $sessions->clear($input['sessionId'], $caller['visitorHash']);
                }
            } catch (\Throwable) {
                // nothing to reset
            }

            return ['ok' => true];
        });
    }

    /**
     * Confirmed handoff: revalidates against the currently published dataset
     * and returns the authoritative calculator selection. No calculation happens.
     */
    public function finalizeAiSelection(Request $request, Quota $quota, Sessions $sessions, SafeCatalog $catalogs): Response
    {
        return $this->answer($request, function (AiRequest $ctx, array $input) use ($quota, $sessions, $catalogs) {
            $sessionId = Js::slice(Js::string($input['raw']['sessionId'] ?? ''), 0, 64);
            try {
                $caller = $quota->identifyCaller($ctx);
                $session = $sessions->get($sessionId, $caller['visitorHash']);
            } catch (\Throwable) {
                return ['ok' => false, 'message' => 'The configuration could not be validated. Please continue in Manual Mode.'];
            }
            if ($session === null) {
                return ['ok' => false, 'message' => 'This session has expired. Please start over.'];
            }

            $state = SelectionValidation::validate($session['proposed'], $catalogs->load());
            if (count($state['labs']) === 0) {
                return ['ok' => false, 'message' => 'No valid selections have been gathered yet.'];
            }
            if (! $state['complete'] || count($state['rejected']) > 0) {
                return ['ok' => false, 'message' => 'Please complete the selected assays, annual section volumes and equipment choices before reviewing this configuration.'];
            }

            try {
                return ['ok' => true, 'selection' => Reconciliation::confirmedHandoff($state)];
            } catch (\Throwable) {
                return ['ok' => false, 'message' => 'The configuration could not be validated. Please continue in Manual Mode.'];
            }
        });
    }

    /**
     * Runs one handler with the normalised input and answers with its result,
     * the status and headers it chose, and a visitor cookie when one was issued.
     *
     * @param  callable(AiRequest, array{raw: array, sessionId: ?string, plan: array}): mixed  $handler
     */
    private function answer(Request $request, callable $handler): Response
    {
        $ctx = AiRequest::from($request);
        $body = $request->getContent();
        $raw = json_decode($body, true);
        $raw = is_array($raw) ? $raw : [];
        // Only a JSON array counts as a plan (an object with numeric keys does not).
        $shape = json_decode($body);
        $plan = $shape instanceof \stdClass && is_array($shape->plan ?? null) ? array_slice($raw['plan'], 0, 10) : [];
        $input = [
            'raw' => $raw,
            'sessionId' => is_string($raw['sessionId'] ?? null) ? Js::slice($raw['sessionId'], 0, 64) : null,
            'plan' => $plan,
        ];

        $response = ServerFn::respond(function () use ($handler, $ctx, $input) {
            return $handler($ctx, $input);
        });
        if ($response->getStatusCode() === 200 && $ctx->status !== 200) {
            $response->setStatusCode($ctx->status);
        }
        foreach ($ctx->headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        if ($ctx->issuedVisitorCookie !== null) {
            $response->headers->setCookie(new Cookie(
                Quota::VISITOR_COOKIE, $ctx->issuedVisitorCookie, time() + Quota::VISITOR_MAX_AGE, '/', null, true, true, true, Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
