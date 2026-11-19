<?php

namespace App\Support;

use RuntimeException;

/**
 * Runs a JavaScript regular expression (source text plus flags) with PCRE so
 * the original application's patterns can be kept character for character.
 *
 * Differences between the two engines are removed by translation:
 *   - \w, \d and \b stay ASCII-only, as in a JavaScript pattern without the
 *     u flag, even though the subject is UTF-8;
 *   - \s is the JavaScript white-space set;
 *   - ^, $ and . use the JavaScript line terminators;
 *   - \uXXXX escapes are understood;
 *   - with the i flag, the Kelvin sign and the long s do not match k and s.
 * A pattern PCRE rejects, or a matching failure, throws: callers are safety
 * checks and must never treat an engine error as "no match".
 */
final class JsRegex
{
    private const SPACE = '\t\n\x0B\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const NEWLINE = '\n\r\x{2028}\x{2029}';

    /** @var array<string, string> */
    private static array $compiled = [];

    public static function test(string $source, string $flags, string $subject): bool
    {
        $found = preg_match(self::compile($source, $flags), self::subject($subject, $flags));
        if ($found === false) {
            throw self::failure($source);
        }

        return $found === 1;
    }

    /**
     * The first match as String.prototype.match / RegExp.prototype.exec gives
     * it (index 0 the whole match, then the groups; an unmatched group is
     * null), or null.
     *
     * @return array{0: string, index: int}|array<int|string, string|int|null>|null
     */
    public static function exec(string $source, string $flags, string $subject): ?array
    {
        $found = preg_match(self::compile($source, $flags), self::subject($subject, $flags), $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        if ($found === false) {
            throw self::failure($source);
        }

        return $found === 1 ? self::shape($match, $subject) : null;
    }

    /**
     * Every match, as [...text.matchAll(re)]: each has its groups and the byte
     * offset of the match under "index".
     *
     * @return list<array<int|string, string|int|null>>
     */
    public static function matchAll(string $source, string $flags, string $subject): array
    {
        $found = preg_match_all(self::compile($source, $flags), self::subject($subject, $flags), $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        if ($found === false) {
            throw self::failure($source);
        }

        return array_map(fn (array $match) => self::shape($match, $subject), $matches);
    }

    /**
     * text.match(/.../g): the matched texts.
     *
     * @return list<string>
     */
    public static function all(string $source, string $flags, string $subject): array
    {
        return array_map(fn (array $match) => $match[0], self::matchAll($source, $flags, $subject));
    }

    public static function count(string $source, string $flags, string $subject): int
    {
        $found = preg_match_all(self::compile($source, $flags), self::subject($subject, $flags));
        if ($found === false) {
            throw self::failure($source);
        }

        return $found;
    }

    /**
     * text.replace(/.../g, replacement). A string replacement is literal (no
     * "$1" expansion); a callable receives the match as exec() shapes it.
     *
     * @param  string|callable(array): string  $replacement
     */
    public static function replace(string $source, string $flags, string $subject, string|callable $replacement): string
    {
        $out = '';
        $at = 0;
        foreach (self::matchAll($source, $flags, $subject) as $match) {
            $out .= substr($subject, $at, $match['index'] - $at);
            $out .= is_string($replacement) ? $replacement : $replacement($match);
            $at = $match['index'] + strlen($match[0]);
        }

        return $out.substr($subject, $at);
    }

    /**
     * text.split(/.../) for patterns without capturing groups.
     *
     * @return list<string>
     */
    public static function split(string $source, string $flags, string $subject): array
    {
        if ($subject === '') {
            return self::test($source, $flags, '') ? [] : [''];
        }
        $parts = [];
        $at = 0;
        foreach (self::matchAll($source, $flags, $subject) as $match) {
            if ($match[0] === '') {
                throw new RuntimeException('JsRegex::split does not support empty matches');
            }
            $parts[] = substr($subject, $at, $match['index'] - $at);
            $at = $match['index'] + strlen($match[0]);
        }
        $parts[] = substr($subject, $at);

        return $parts;
    }

    /** Escapes text for use inside a pattern: text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"). */
    public static function escape(string $text): string
    {
        return preg_replace('/[.*+?^${}()|[\]\\\\]/', '\\\\$0', $text);
    }

    /** @param  array<int, array{0: ?string, 1: int}>  $match */
    private static function shape(array $match, string $original): array
    {
        $out = [];
        foreach ($match as $i => [$text, $offset]) {
            // Read from the original subject: the matched copy may have had two characters masked.
            $out[$i] = $text === null || $offset < 0 ? null : substr($original, $offset, strlen($text));
        }
        $out['index'] = $match[0][1];

        return $out;
    }

    /**
     * JavaScript (without the u flag) never lets a non-ASCII character match
     * an ASCII letter case-insensitively; PCRE folds the Kelvin sign to k and
     * the long s to s. Both are swapped for caseless characters of the same
     * byte length, so offsets still apply to the original text.
     */
    private static function subject(string $subject, string $flags): string
    {
        if (! str_contains($flags, 'i') || (! str_contains($subject, "\u{212A}") && ! str_contains($subject, "\u{017F}"))) {
            return $subject;
        }

        return str_replace(["\u{212A}", "\u{017F}"], ["\u{2129}", "\u{0138}"], $subject);
    }

    private static function failure(string $source): RuntimeException
    {
        return new RuntimeException('Pattern could not be evaluated ('.preg_last_error_msg().'): '.substr($source, 0, 60));
    }

    private static function compile(string $source, string $flags): string
    {
        $key = $flags."\x01".$source;
        if (isset(self::$compiled[$key])) {
            return self::$compiled[$key];
        }
        if (preg_match('/^[imu]*$/D', $flags) !== 1) {
            throw new RuntimeException("Unsupported pattern flags: {$flags}");
        }
        $multiline = str_contains($flags, 'm');
        $out = '';
        $inClass = false;
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === "\x01") {
                throw new RuntimeException('Unsupported character in pattern');
            }
            if ($char === '\\') {
                $next = $source[++$i] ?? throw new RuntimeException('Pattern ends with a backslash');
                if ($next === 'u') {
                    if (preg_match('/\G[0-9a-fA-F]{4}/', $source, $hex, 0, $i + 1) !== 1) {
                        throw new RuntimeException('Unsupported \\u escape in pattern');
                    }
                    $out .= '\x{'.$hex[0].'}';
                    $i += 4;
                } elseif ($next === 's') {
                    $out .= $inClass ? self::SPACE : '['.self::SPACE.']';
                } elseif ($next === 'S') {
                    if ($inClass) {
                        throw new RuntimeException('Unsupported \\S inside a character class');
                    }
                    $out .= '[^'.self::SPACE.']';
                } elseif ($next === 'v') {
                    $out .= '\x0B';
                } elseif ($next === 'p' || $next === 'P') {
                    if (! str_contains($flags, 'u')) {
                        throw new RuntimeException('\\p needs the u flag');
                    }
                    $out .= '\\'.$next;
                } elseif (ctype_alnum($next) && ! str_contains('bBdDwWnrtfx0', $next)) {
                    // Back-references, named groups, control escapes: not used by the application.
                    throw new RuntimeException("Unsupported escape \\{$next} in pattern");
                } else {
                    $out .= '\\'.$next;
                }

                continue;
            }
            if ($inClass) {
                if ($char === ']') {
                    $inClass = false;
                } elseif ($char === '[') {
                    // A literal "[" inside a class; PCRE would read "[:" as a POSIX class.
                    $out .= '\\';
                }
                $out .= $char;

                continue;
            }
            $out .= match ($char) {
                '[' => (function () use (&$inClass) {
                    $inClass = true;

                    return '[';
                })(),
                '.' => '[^'.self::NEWLINE.']',
                '^' => $multiline ? '(?<![^'.self::NEWLINE.'])' : '\A',
                '$' => $multiline ? '(?![^'.self::NEWLINE.'])' : '\z',
                default => $char,
            };
        }
        if ($inClass) {
            throw new RuntimeException('Unterminated character class in pattern');
        }
        $compiled = "\x01(*UTF)".$out."\x01".(str_contains($flags, 'i') ? 'i' : '');
        if (@preg_match($compiled, '') === false) {
            throw new RuntimeException('Pattern does not compile: '.substr($source, 0, 60));
        }

        return self::$compiled[$key] = $compiled;
    }
}
