<?php

namespace App\Http\Support;

use App\Database\Db;
use App\Support\InvalidInput;
use App\Support\Js;
use Illuminate\Http\Request;

/**
 * The JSON body of a server-function call, read with the same rules the
 * original input schemas applied. Anything that does not fit is refused
 * before the handler runs.
 */
final class Input
{
    private const EMAIL = '/^(?!\.)(?!.*\.\.)([A-Za-z0-9_\'+\-\.]*)[A-Za-z0-9_+-]@([A-Za-z0-9][A-Za-z0-9\-]*\.)+[A-Za-z]{2,}$/';

    private function __construct(private readonly object $data) {}

    public static function from(Request $request): self
    {
        $data = json_decode($request->getContent());
        if (! is_object($data)) {
            throw new InvalidInput('body', 'must be a JSON object');
        }

        return new self($data);
    }

    public function has(string $key): bool
    {
        return property_exists($this->data, $key);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(strval(...), array_keys(get_object_vars($this->data)));
    }

    public function guid(string $key): string
    {
        $value = $this->data->{$key} ?? null;
        if (! Db::isUuid($value)) {
            throw new InvalidInput($key, 'must be an id');
        }

        return $value;
    }

    public function string(string $key, int $min = 0, ?int $max = null): string
    {
        $value = $this->data->{$key} ?? null;
        if (! is_string($value)) {
            throw new InvalidInput($key, 'must be text');
        }
        $length = Js::length($value);
        if ($length < $min || ($max !== null && $length > $max)) {
            throw new InvalidInput($key, $max === null ? "must be at least {$min} characters" : "must be {$min} to {$max} characters");
        }

        return $value;
    }

    /** Text, or null when the field is missing or null. */
    public function optionalString(string $key, int $min = 0, ?int $max = null): ?string
    {
        return ($this->data->{$key} ?? null) === null ? null : $this->string($key, $min, $max);
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->data->{$key} ?? null;
        if ($value === null && $default !== null && ! $this->hasNull($key)) {
            return $default;
        }
        if (! is_bool($value)) {
            throw new InvalidInput($key, 'must be true or false');
        }

        return $value;
    }

    /** @param  list<string>  $allowed */
    public function enum(string $key, array $allowed, ?string $default = null): string
    {
        $value = $this->data->{$key} ?? null;
        if ($value === null && $default !== null && ! $this->hasNull($key)) {
            return $default;
        }
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidInput($key, 'must be one of '.implode(', ', $allowed));
        }

        return $value;
    }

    public function int(string $key, int $min, int $max): int
    {
        $value = $this->data->{$key} ?? null;
        if (is_float($value) && floor($value) === $value && abs($value) < 9.0e15) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidInput($key, "must be a whole number from {$min} to {$max}");
        }

        return $value;
    }

    public function email(string $key, bool $normalize = false, ?int $max = null): string
    {
        $value = $this->string($key);
        if ($normalize) {
            $value = mb_strtolower(Js::trim($value), 'UTF-8');
        }
        if (preg_match(self::EMAIL, $value) !== 1 || ($max !== null && Js::length($value) > $max)) {
            throw new InvalidInput($key, 'must be an email address');
        }

        return $value;
    }

    /** A JSON object whose values are all text, numbers or true/false. */
    public function scalarRecord(string $key): object
    {
        $value = $this->data->{$key} ?? null;
        if (! is_object($value)) {
            throw new InvalidInput($key, 'must be an object');
        }
        foreach (get_object_vars($value) as $name => $item) {
            if (! is_string($item) && ! is_int($item) && ! is_float($item) && ! is_bool($item)) {
                throw new InvalidInput("{$key}.{$name}", 'must be text, a number or true/false');
            }
        }

        return $value;
    }

    /** Present with an explicit null (a default only applies to a missing field). */
    private function hasNull(string $key): bool
    {
        return property_exists($this->data, $key) && $this->data->{$key} === null;
    }
}
