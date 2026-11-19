<?php

namespace App\Import;

/**
 * Reads the cell values of an .xlsx workbook the way the original application's
 * spreadsheet library did for the importer: sheet names in workbook order and,
 * per sheet, the non-empty rows of the used range as plain values (text,
 * numbers, booleans, null). Row and column positions are relative to the
 * sheet's used range, exactly as before, because the importer's row numbers
 * and column meanings depend on it.
 *
 * Only what the importer needs is read: no styles, dates stay serial numbers,
 * formulas contribute their stored result.
 */
final class XlsxReader
{
    /** @var list<string> */
    public array $sheetNames = [];

    /** @var array<string, list<list<string|float|bool|null>>> */
    private array $rows = [];

    /** @throws \RuntimeException when the bytes are not a readable .xlsx workbook */
    public static function read(string $bytes): self
    {
        $files = self::unzip($bytes);
        $get = function (string $path) use ($files): ?string {
            $key = strtolower(ltrim($path, '/'));

            return $files[$key] ?? null;
        };

        $workbookPath = 'xl/workbook.xml';
        foreach (self::relationships($get('_rels/.rels') ?? '', '') as $relation) {
            if (str_ends_with($relation['type'], '/officeDocument')) {
                $workbookPath = $relation['target'];
            }
        }
        $workbook = $get($workbookPath);
        if ($workbook === null) {
            throw new \RuntimeException('Could not find the workbook part');
        }
        $directory = str_contains($workbookPath, '/') ? substr($workbookPath, 0, strrpos($workbookPath, '/') + 1) : '';
        $relations = self::relationships($get($directory.'_rels/'.basename($workbookPath).'.rels') ?? '', $directory);

        $stringsPath = $directory.'sharedStrings.xml';
        foreach ($relations as $relation) {
            if (str_ends_with($relation['type'], '/sharedStrings')) {
                $stringsPath = $relation['target'];
            }
        }
        $strings = self::sharedStrings($get($stringsPath) ?? '');

        $reader = new self;
        preg_match_all('/<(?:\w+:)?sheet\s[^<>]*>/', $workbook, $tags);
        foreach ($tags[0] as $index => $tag) {
            $attributes = self::attributes($tag);
            $name = self::unescape($attributes['name'] ?? '');
            $path = isset($attributes['id'], $relations[$attributes['id']])
                ? $relations[$attributes['id']]['target']
                : $directory.'worksheets/sheet'.($index + 1).'.xml';
            $reader->sheetNames[] = $name;
            try {
                $data = $get($path);
                $reader->rows[$name] = $data === null ? [] : self::sheetRows($data, $strings);
            } catch (\Throwable) {
                // As before, a sheet that cannot be parsed reads as empty.
                $reader->rows[$name] = [];
            }
        }

        return $reader;
    }

    /**
     * The rows of one sheet that hold at least one value. Every row spans the
     * columns of the used range; cells without a value are null.
     *
     * @return list<list<string|float|bool|null>>
     */
    public function rows(string $sheetName): array
    {
        return $this->rows[$sheetName] ?? [];
    }

    // ------------------------------------------------------------- worksheet

    /** @param  list<?string>  $strings */
    private static function sheetRows(string $data, array $strings): array
    {
        $before = $data;
        $body = null;
        if (preg_match('/<(?:\w+:)?sheetData(?:\s[^<>]*)?>(.*?)<\/(?:\w+:)?sheetData>/s', $data, $match, PREG_OFFSET_CAPTURE)) {
            $before = substr($data, 0, $match[0][1]);
            $body = $match[1][0];
        }

        $cells = [];
        $guess = ['sr' => 2000000, 'sc' => 2000000, 'er' => 0, 'ec' => 0];
        if ($body !== null) {
            self::readCells($body, $strings, $cells, $guess);
        }

        // The used range: the sheet's own dimension when it names a range, otherwise where the cells are.
        $range = null;
        if (preg_match('/<(?:\w*:)?dimension/', $before, $match, PREG_OFFSET_CAPTURE) && $match[0][1] > 0
            && preg_match('/"([A-Za-z0-9_]*:[A-Za-z0-9_]*)"/', substr($before, $match[0][1], 50), $ref)) {
            $decoded = self::decodeRange($ref[1]);
            if ($decoded['sr'] <= $decoded['er'] && $decoded['sc'] <= $decoded['ec'] && $decoded['sr'] >= 0 && $decoded['sc'] >= 0) {
                $range = $decoded;
            }
        }
        if ($range === null && $guess['ec'] >= $guess['sc'] && $guess['er'] >= $guess['sr']) {
            $range = $guess;
        }
        if ($range === null) {
            return [];
        }

        $columns = [];
        for ($c = $range['sc']; $c <= $range['ec']; $c++) {
            $columns[] = self::columnName($c);
        }
        $rows = [];
        for ($r = $range['sr']; $r <= $range['er']; $r++) {
            $row = [];
            $empty = true;
            foreach ($columns as $column) {
                $value = $cells[$column.($r + 1)] ?? null;
                $row[] = $value;
                $empty = $empty && $value === null;
            }
            if (! $empty) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function readCells(string $body, array $strings, array &$cells, array &$guess): void
    {
        $rowNumber = 0;
        foreach (preg_split('/<\/(?:\w+:)?row>/', $body) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            // The row tag is the first tag of the chunk that is not self-closing.
            $start = 0;
            $end = null;
            for ($i = 0, $length = strlen($chunk); $i < $length; $i++) {
                if ($chunk[$i] === '<') {
                    $start = $i;
                } elseif ($chunk[$i] === '>' && ($i === 0 || $chunk[$i - 1] !== '/')) {
                    $end = $i + 1;
                    break;
                }
            }
            if ($end === null || $start >= $end) {
                break;
            }
            $rowTag = self::attributes(substr($chunk, $start, $end - $start));
            $rowNumber = isset($rowTag['r']) ? (int) $rowTag['r'] : $rowNumber + 1;
            $column = -1;
            $guess['sr'] = min($guess['sr'], $rowNumber - 1);
            $guess['er'] = max($guess['er'], $rowNumber - 1);

            foreach (preg_split('/<(?:\w+:)?c[ \/>]/', substr($chunk, $end)) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('/r=["\']([^"\']*)["\']/', $part, $ref)) {
                    $index = 0;
                    for ($i = 0, $length = strlen($ref[1]); $i < $length; $i++) {
                        $letter = ord($ref[1][$i]) - 64;
                        if ($letter < 1 || $letter > 26) {
                            break;
                        }
                        $index = 26 * $index + $letter;
                    }
                    $column = $index - 1;
                } else {
                    $column++;
                }
                $part = '<c '.($part[0] === '<' ? '>' : '').$part;
                $close = strpos($part, '>');
                $close = $close === false ? strlen($part) : $close + 1;
                $tag = self::attributes(substr($part, 0, $close));
                $content = substr($part, $close);
                $key = ($tag['r'] ?? '') !== '' ? $tag['r'] : self::columnName($column).$rowNumber;

                $raw = null;
                if (preg_match('/<(?:\w+:)?v(?:\s[^<>]*)?>(.*?)<\/(?:\w+:)?v>/s', $content, $v) && $v[1] !== '') {
                    $raw = self::unescape($v[1], false);
                }
                $type = $tag['t'] ?? null;
                if ($type === null && $raw === null) {
                    // A formula without a stored result reads as the number 0, which is then dropped as empty.
                    continue;
                }
                $type = $type === null || $type === '' ? 'n' : $type;
                $guess['sc'] = min($guess['sc'], $column);
                $guess['ec'] = max($guess['ec'], $column);

                switch ($type) {
                    case 'n':
                        if ($raw === null) {
                            continue 2;
                        }
                        $value = self::parseFloat($raw);
                        break;
                    case 's':
                        if ($raw === null) {
                            continue 2;
                        }
                        $position = (int) self::parseFloat($raw);
                        if (! array_key_exists($position, $strings)) {
                            throw new \RuntimeException('Shared string out of range');
                        }
                        $value = $strings[$position];
                        break;
                    case 'str':
                        $value = $raw !== null ? str_replace("\r\n", "\n", self::unescape($raw, false)) : '';
                        break;
                    case 'inlineStr':
                        $value = preg_match('/<(?:\w+:)?is(?:\s[^<>]*)?>(.*?)<\/(?:\w+:)?is>/s', $content, $is) ? (self::stringItem($is[1]) ?? null) : '';
                        break;
                    case 'b':
                        $value = $raw === '1' || $raw === 'true';
                        break;
                    case 'e':
                        // Error cells (#N/A, #DIV/0!, ...) carry no value.
                        $value = null;
                        break;
                    case 'd':
                        $time = $raw === null ? false : strtotime($raw.(preg_match('/(Z|[+-]\d\d:?\d\d)$/', $raw) ? '' : ' UTC'));
                        $value = $time === false ? NAN : ($time + 2209161600) / 86400;
                        break;
                    default:
                        throw new \RuntimeException("Unrecognized cell type {$type}");
                }
                $cells[$key] = $value;
            }
        }
    }

    // -------------------------------------------------------- shared strings

    /** @return list<?string> */
    private static function sharedStrings(string $data): array
    {
        if (! preg_match('/<(?:\w+:)?sst(?:\s[^<>]*)?>(.*?)<\/(?:\w+:)?sst>/s', $data, $sst)) {
            return [];
        }
        $items = preg_split('/<\/(?:\w+:)?(?:si|sstItem)>/', preg_replace('/<(?:\w+:)?(?:si|sstItem)>/', '', $sst[1]));

        return array_map(fn (string $item) => self::stringItem(trim($item)), $items);
    }

    /** The text of one string item: plain text, or the runs of rich text joined (phonetic guides left out). */
    private static function stringItem(string $item): ?string
    {
        if ($item === '') {
            return '';
        }
        if (preg_match('/^\s*<(?:\w+:)?t[^>]*>/', $item)) {
            $text = preg_split('/<\/(?:\w+:)?t>/', substr($item, strpos($item, '>') + 1))[0];

            return self::unescape($text);
        }
        if (preg_match('/<(?:\w+:)?r\b[^<>]*>/', $item)) {
            $item = preg_replace('/<(?:\w+:)?rPh\b.*?<\/(?:\w+:)?rPh>/s', '', $item);
            preg_match_all('/<(?:\w+:)?t\b[^<>]*>([^<]*)<\/(?:\w+:)?t>/', $item, $runs);

            return self::unescape(implode('', $runs[1]));
        }

        return null;
    }

    // ----------------------------------------------------------------- parts

    /** @return array<string, array{type: string, target: string}> by relationship id */
    private static function relationships(string $data, string $directory): array
    {
        $relations = [];
        preg_match_all('/<(?:\w+:)?Relationship\s[^<>]*>/', $data, $tags);
        foreach ($tags[0] as $tag) {
            $attributes = self::attributes($tag);
            $target = self::unescape($attributes['Target'] ?? '', false);
            if (($attributes['TargetMode'] ?? '') !== 'External') {
                $target = self::resolve($target, $directory);
            }
            $relations[$attributes['Id'] ?? ''] = ['type' => $attributes['Type'] ?? '', 'target' => $target];
        }

        return $relations;
    }

    private static function resolve(string $target, string $directory): string
    {
        if (str_starts_with($target, '/')) {
            return substr($target, 1);
        }
        $parts = [];
        foreach (explode('/', $directory.$target) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /** @return array<string, string> attribute values by local name (namespace prefix removed) */
    private static function attributes(string $tag): array
    {
        $attributes = [];
        preg_match_all('/\s([^"\s?>\/]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\'">\s]+))/', $tag, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = $match[1];
            $colon = strpos($name, ':');
            if ($colon !== false) {
                $name = (str_starts_with($name, 'xmlns:') ? 'xmlns' : '').substr($name, $colon + 1);
            }
            if (($match[2] ?? '') !== '') {
                $attributes[$name] = $match[2];
            } elseif (($match[3] ?? '') !== '') {
                $attributes[$name] = $match[3];
            } else {
                $attributes[$name] = $match[4] ?? '';
            }
        }

        return $attributes;
    }

    /** XML entities, numeric references and the _xHHHH_ escapes spreadsheets use; CDATA is passed through. */
    private static function unescape(string $text, bool $spreadsheet = true): string
    {
        $start = strpos($text, '<![CDATA[');
        if ($start !== false) {
            $end = strpos($text, ']]>');
            $end = $end === false ? strlen($text) : $end;
            $out = self::unescape(substr($text, 0, $start), false).substr($text, $start + 9, $end - $start - 9).self::unescape((string) substr($text, $end + 3), false);
        } else {
            $out = preg_replace_callback('/&(?:quot|apos|gt|lt|amp|#x?([0-9a-fA-F]+));/i', function (array $m) {
                $named = ['&quot;' => '"', '&apos;' => "'", '&gt;' => '>', '&lt;' => '<', '&amp;' => '&'];
                if (isset($named[$m[0]])) {
                    return $named[$m[0]];
                }
                if (! isset($m[1])) {
                    return "\0";
                }
                $code = stripos($m[0], 'x') !== false ? hexdec($m[1]) : (int) $m[1];

                return mb_chr($code & 0xFFFF, 'UTF-8') ?: $m[0];
            }, $text);
            $out = preg_replace_callback('/_x([0-9a-fA-F]{4})_/i', fn (array $m) => mb_chr(hexdec($m[1]), 'UTF-8') ?: '', $out);
        }

        return $spreadsheet ? str_replace("\r\n", "\n", $out) : $out;
    }

    /** JavaScript parseFloat: the longest numeric prefix, or NaN. */
    private static function parseFloat(string $text): float
    {
        if (preg_match('/^\s*([+-]?(?:Infinity|(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?))/', $text, $m)) {
            if (str_ends_with($m[1], 'Infinity')) {
                return $m[1][0] === '-' ? -INF : INF;
            }

            return (float) $m[1];
        }

        return NAN;
    }

    /** @return array{sr: int, sc: int, er: int, ec: int} zero-based */
    private static function decodeRange(string $range): array
    {
        $read = function (int &$i) use ($range): array {
            $column = 0;
            for ($length = strlen($range); $i < $length; $i++) {
                $letter = ord($range[$i]) - 64;
                if ($letter < 1 || $letter > 26) {
                    break;
                }
                $column = 26 * $column + $letter;
            }
            $row = 0;
            for ($length = strlen($range); $i < $length; $i++) {
                $digit = ord($range[$i]) - 48;
                if ($digit < 0 || $digit > 9) {
                    break;
                }
                $row = 10 * $row + $digit;
            }

            return [$column - 1, $row - 1];
        };
        $i = 0;
        [$sc, $sr] = $read($i);
        if ($i >= strlen($range) || $range[$i] !== ':') {
            return ['sr' => $sr, 'sc' => $sc, 'er' => $sr, 'ec' => $sc];
        }
        $i++;
        [$ec, $er] = $read($i);

        return ['sr' => $sr, 'sc' => $sc, 'er' => $er, 'ec' => $ec];
    }

    private static function columnName(int $column): string
    {
        $name = '';
        for ($column++; $column > 0; $column = intdiv($column - 1, 26)) {
            $name = chr(65 + ($column - 1) % 26).$name;
        }

        return $name;
    }

    // -------------------------------------------------------------------- zip

    /**
     * The files of a zip archive, by lower-cased path. Stored and deflated
     * entries only, which is all .xlsx uses.
     *
     * @return array<string, string>
     */
    private static function unzip(string $bytes): array
    {
        $end = strrpos($bytes, "PK\x05\x06");
        if ($end === false || strlen($bytes) < $end + 22) {
            throw new \RuntimeException('Not a zip archive');
        }
        $directory = unpack('vdisk/vstart/vhere/vtotal/Vsize/Voffset', substr($bytes, $end + 4, 16));
        $files = [];
        $at = $directory['offset'];
        $budget = 200 * 1024 * 1024;
        for ($i = 0; $i < $directory['total']; $i++) {
            if (substr($bytes, $at, 4) !== "PK\x01\x02") {
                throw new \RuntimeException('Damaged zip directory');
            }
            $entry = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vpacked/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($bytes, $at + 4, 42));
            $name = substr($bytes, $at + 46, $entry['name']);
            $at += 46 + $entry['name'] + $entry['extra'] + $entry['comment'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            // Only the parts the importer reads are unpacked.
            $key = strtolower(ltrim(str_replace('\\', '/', $name), '/'));
            if (! preg_match('#(^|/)_rels/|\.xml$#', $key) || preg_match('#^xl/(theme|styles|drawings|media|charts|printersettings)|^docprops/#', $key)) {
                continue;
            }
            $local = unpack('vname/vextra', substr($bytes, $entry['offset'] + 26, 4));
            if ($local === false || substr($bytes, $entry['offset'], 4) !== "PK\x03\x04") {
                throw new \RuntimeException('Damaged zip entry');
            }
            $packed = substr($bytes, $entry['offset'] + 30 + $local['name'] + $local['extra'], $entry['packed']);
            $budget -= $entry['size'];
            if ($budget < 0) {
                throw new \RuntimeException('Workbook is too large');
            }
            $data = match ($entry['method']) {
                0 => $packed,
                8 => @gzinflate($packed, $entry['size'] + 1),
                default => false,
            };
            if ($data === false) {
                throw new \RuntimeException('Unsupported or damaged zip entry');
            }
            // A byte-order mark is not part of the document.
            $files[$key] = str_starts_with($data, "\xEF\xBB\xBF") ? substr($data, 3) : $data;
        }

        return $files;
    }
}
