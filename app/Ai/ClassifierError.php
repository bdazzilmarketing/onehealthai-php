<?php

namespace App\Ai;

final class ClassifierError extends \RuntimeException
{
    /** @param  array{input: mixed, output: mixed}|null  $usage */
    public function __construct(public readonly string $category, public readonly ?array $usage = null)
    {
        parent::__construct('CLASSIFIER_FAILED');
    }
}
