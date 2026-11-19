<?php

namespace App\Reports\Pdf;

/**
 * A small PDF writer: A4-style pages holding text in the two standard fonts,
 * filled rectangles and straight lines. Everything drawn is kept as a list of
 * operations per page (which the tests compare with the original layout) and
 * written out as a PDF 1.7 file. The same input always gives the same bytes.
 */
final class PdfCanvas
{
    /** @var list<list<array<string, mixed>>> operations per page */
    private array $pages = [];

    private int $current = -1;

    public function __construct(private readonly float $width, private readonly float $height) {}

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->current = count($this->pages) - 1;
    }

    /** Later drawing goes to this page (zero-based). */
    public function selectPage(int $index): void
    {
        $this->current = $index;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** @param  array{float, float, float}  $color */
    public function text(string $text, float $x, float $y, string $font, float|int $size, array $color): void
    {
        // Fails here, while drawing, for a character the font cannot show.
        StandardFont::encode($font, $text);
        $this->pages[$this->current][] = ['op' => 'text', 'page' => $this->current, 'text' => $text, 'x' => $x, 'y' => $y, 'font' => $font, 'size' => $size, 'color' => $color];
    }

    /** @param  array{float, float, float}  $color */
    public function rectangle(float $x, float $y, float $width, float $height, array $color): void
    {
        $this->pages[$this->current][] = ['op' => 'rect', 'page' => $this->current, 'x' => $x, 'y' => $y, 'width' => $width, 'height' => $height, 'color' => $color];
    }

    /** @param  array{float, float, float}  $color */
    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness, array $color): void
    {
        $this->pages[$this->current][] = ['op' => 'line', 'page' => $this->current, 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 'thickness' => $thickness, 'color' => $color];
    }

    /** Everything drawn so far, in drawing order per page. */
    public function operations(): array
    {
        return array_merge(...$this->pages ?: [[]]);
    }

    private static function n(float|int $value): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    private static function colour(array $color): string
    {
        return self::n($color[0]).' '.self::n($color[1]).' '.self::n($color[2]);
    }

    private static function content(array $operations): string
    {
        $fonts = [StandardFont::HELVETICA => 'F1', StandardFont::HELVETICA_BOLD => 'F2'];
        $out = '';
        foreach ($operations as $o) {
            if ($o['op'] === 'text') {
                $out .= 'BT '.self::colour($o['color']).' rg /'.$fonts[$o['font']].' '.self::n($o['size']).' Tf 1 0 0 1 '.self::n($o['x']).' '.self::n($o['y'])
                    .' Tm <'.strtoupper(bin2hex(StandardFont::encode($o['font'], $o['text'])))."> Tj ET\n";
            } elseif ($o['op'] === 'rect') {
                $out .= self::colour($o['color']).' rg '.self::n($o['x']).' '.self::n($o['y']).' '.self::n($o['width']).' '.self::n($o['height'])." re f\n";
            } else {
                $out .= self::colour($o['color']).' RG '.self::n($o['thickness']).' w '.self::n($o['x1']).' '.self::n($o['y1']).' m '.self::n($o['x2']).' '.self::n($o['y2'])." l S\n";
            }
        }

        return $out;
    }

    /** A text value as a PDF string (UTF-16 with a byte order mark, in hex). */
    private static function textString(string $value): string
    {
        return '<FEFF'.strtoupper(bin2hex(mb_convert_encoding($value, 'UTF-16BE', 'UTF-8'))).'>';
    }

    /**
     * @param  array{title: string, author: string, subject: string, created: \DateTimeInterface}  $info
     */
    public function render(array $info): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Title '.self::textString($info['title']).' /Author '.self::textString($info['author']).' /Subject '.self::textString($info['subject'])
                .' /Producer '.self::textString('One Health AI Lab Calculator')
                .' /CreationDate (D:'.gmdate('YmdHis', $info['created']->getTimestamp()).'Z) /ModDate (D:'.gmdate('YmdHis', $info['created']->getTimestamp()).'Z) >>',
        ];
        $kids = [];
        $next = 6;
        foreach ($this->pages as $operations) {
            $stream = gzcompress(self::content($operations), 9);
            $objects[$next] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.self::n($this->width).' '.self::n($this->height).'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($next + 1).' 0 R >>';
            $objects[$next + 1] = '<< /Length '.strlen($stream)." /Filter /FlateDecode >>\nstream\n".$stream."\nendstream";
            $kids[] = "{$next} 0 R";
            $next += 2;
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($out);
            $out .= "{$number} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R /Info 5 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
