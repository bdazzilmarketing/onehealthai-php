<?php

namespace App\Ai;

/** The patterns, word lists and fixed texts generated from the original AI modules. */
final class Data
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @var array<string, array<string, true>> */
    private static array $sets = [];

    public static function get(string $name): mixed
    {
        self::$data ??= require __DIR__.'/Data/generated.php';

        return self::$data[$name];
    }

    /** A word list as a lookup table. */
    public static function has(string $list, string $word): bool
    {
        self::$sets[$list] ??= array_fill_keys(self::get($list), true);

        return isset(self::$sets[$list][$word]);
    }
}
