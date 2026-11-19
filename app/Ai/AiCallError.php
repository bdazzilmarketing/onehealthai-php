<?php

namespace App\Ai;

/** A failed generation call, carrying provider-reported usage (when known) so quota can reconcile. */
final class AiCallError extends \RuntimeException
{
    /**
     * @param  'AI_UNAVAILABLE'|'AI_TIMEOUT'|'AI_NOT_CONFIGURED'  $message
     * @param  array{input: mixed, output: mixed}|null  $usage
     */
    public function __construct(string $message, public readonly string $category, public readonly ?array $usage = null)
    {
        parent::__construct($message);
    }
}
