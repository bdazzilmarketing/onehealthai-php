<?php

namespace App\Ai;

use App\Support\Js;

/** Content-free server log lines (enums, counts, ids), as the original wrote them. */
final class AiLog
{
    public static function write(string $tag, array $fields = []): void
    {
        error_log($fields === [] ? $tag : $tag.' '.Js::stringify($fields));
    }
}
