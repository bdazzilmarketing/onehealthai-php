<?php

namespace App\Ai;

/**
 * Per-turn provider-call accounting (Finding 4301). One admitted turn = one
 * reservation; each provider call is recorded separately so admitted turns,
 * provider calls and HTTP attempts are distinguishable in logs.
 *  - Known usage is added exactly.
 *  - Unknown usage with a fallback estimate is charged at that estimate.
 *  - Unknown usage without a fallback makes the turn total unknown (null), which
 *    keeps the FULL reservation charged when released. Never treated as zero.
 */
final class TurnAccounting
{
    private int|float $tokens = 0;

    private int|float $input = 0;

    private int|float $output = 0;

    private bool $unknown = false;

    /** @var list<string> */
    private array $calls = [];

    /** @param  array{input?: int|float|null, output?: int|float|null}|null  $usage */
    public function add(string $call, ?array $usage, int|float|null $fallbackEstimate): void
    {
        $number = fn (string $key) => is_int($usage[$key] ?? null) || is_float($usage[$key] ?? null);
        if ($usage !== null && ($number('input') || $number('output'))) {
            $i = max(0, $usage['input'] ?? 0);
            $o = max(0, $usage['output'] ?? 0);
            $this->input += $i;
            $this->output += $o;
            $this->tokens += $i + $o;
            $this->calls[] = "{$call}:known";
        } elseif ($fallbackEstimate !== null) {
            $this->tokens += $fallbackEstimate;
            $this->calls[] = "{$call}:estimated";
        } else {
            $this->unknown = true;
            $this->calls[] = "{$call}:unknown";
        }
    }

    /** Tokens to reconcile on release; null = keep the full reservation charged. */
    public function total(): int|float|null
    {
        return $this->unknown ? null : $this->tokens;
    }

    public function inputTokens(): int|float
    {
        return $this->input;
    }

    public function outputTokens(): int|float
    {
        return $this->output;
    }

    public function summary(): array
    {
        return ['providerCalls' => count($this->calls), 'calls' => $this->calls, 'totalUnknown' => $this->unknown];
    }
}
