<?php

namespace AssetMapperUpdate\Importmap;

use AssetMapperUpdate\Model\ImportmapEntry;

/**
 * Parses importmap.php without executing it.
 *
 * It understands what importmap.php files contain: a preamble (`<?php`, `declare`, comments, `use`
 * statements…) followed by `return <array>;` where the array only holds literals. It keeps byte
 * offsets, so the patcher can edit the original text without reformatting it.
 */
final class ImportmapParser
{
    /** @var list<array{int|string, string, int}> token id (or the character), text, start offset */
    private array $tokens = [];
    private int $pos = 0;
    private string $text = '';

    public static function parse(string $text): ParsedImportmap
    {
        return (new self())->parseText($text);
    }

    private function parseText(string $text): ParsedImportmap
    {
        if (!str_starts_with($text, '<?php')) {
            throw new ImportmapParseException('the file does not start with "<?php"', $text, 0);
        }
        $this->text = $text;
        $this->tokens = [];
        $this->pos = 0;
        $offset = 0;
        foreach (token_get_all($text) as $token) {
            [$id, $value] = \is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            if (!\in_array($id, [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG], true)) {
                $this->tokens[] = [$id, $value, $offset];
            }
            $offset += \strlen($value);
        }

        $root = $this->parseFile();
        $entries = [];
        foreach ($root->items as $item) {
            if (!\is_string($item->key)) {
                throw new ImportmapParseException('importmap entries must have string keys', $text, $item->span->start);
            }
            if (!$item->value instanceof PhpArray) {
                throw new ImportmapParseException(\sprintf('entry "%s" must be an array', $item->key), $text, $item->valueSpan->start);
            }
            $attributes = [];
            foreach ($item->value->items as $attribute) {
                if (\is_string($attribute->key)) {
                    $attributes[$attribute->key] = $attribute;
                }
            }
            $entries[] = new ParsedEntry(self::toEntry($item->key, $attributes), $item, $item->value, $attributes);
        }

        return new ParsedImportmap($text, $root, $entries);
    }

    private function parseFile(): PhpArray
    {
        // Skip the preamble up to the top-level `return`.
        while (isset($this->tokens[$this->pos]) && \T_RETURN !== $this->tokens[$this->pos][0]) {
            ++$this->pos;
        }
        if (!isset($this->tokens[$this->pos])) {
            throw new ImportmapParseException('no "return" statement found', $this->text, \strlen($this->text));
        }
        ++$this->pos;
        [$value, $span] = $this->parseValue();
        if (!$value instanceof PhpArray) {
            throw new ImportmapParseException('"return" must be followed by an array', $this->text, $span->start);
        }
        $this->expect(';');

        return $value;
    }

    /**
     * @return array{string|int|float|bool|PhpArray|null, Span, string|null} value, span, quote
     */
    private function parseValue(): array
    {
        $token = $this->tokens[$this->pos++] ?? throw new ImportmapParseException('unexpected end of file', $this->text, \strlen($this->text));
        [$id, $raw, $start] = $token;
        $span = new Span($start, $start + \strlen($raw));

        switch (true) {
            case \T_CONSTANT_ENCAPSED_STRING === $id:
                return [self::decodeString($raw), $span, $raw[0]];
            case '"' === $id:
                throw new ImportmapParseException('string interpolation is not supported', $this->text, $start);
            case \T_LNUMBER === $id:
                return [(int) $raw, $span, null];
            case \T_DNUMBER === $id:
                return [(float) $raw, $span, null];
            case '-' === $id && \in_array($this->peek(), [\T_LNUMBER, \T_DNUMBER], true):
                [$number, $numberSpan] = $this->parseValue();
                \assert(\is_int($number) || \is_float($number));

                return [-$number, new Span($start, $numberSpan->end), null];
            case '[' === $id:
                return $this->parseArrayBody($start, ']', false);
            case \T_ARRAY === $id && '(' === $this->peek():
                ++$this->pos;

                return $this->parseArrayBody($start, ')', true);
            case \T_STRING === $id && \in_array(strtolower($raw), ['true', 'false', 'null'], true):
                return [match (strtolower($raw)) {
                    'true' => true,
                    'false' => false,
                    default => null,
                }, $span, null];
        }

        throw new ImportmapParseException(\sprintf('unsupported expression "%s" (only literals are supported in importmap.php)', $raw), $this->text, $start);
    }

    /**
     * @return array{PhpArray, Span, null}
     */
    private function parseArrayBody(int $start, string $close, bool $long): array
    {
        $items = [];
        while (true) {
            $token = $this->tokens[$this->pos] ?? throw new ImportmapParseException('unterminated array', $this->text, $start);
            if ($close === $token[0]) {
                ++$this->pos;
                $span = new Span($start, $token[2] + 1);

                return [new PhpArray($items, $span, $long), $span, null];
            }

            [$first, $firstSpan, $firstQuote] = $this->parseValue();
            if (\T_DOUBLE_ARROW === $this->peek()) {
                ++$this->pos;
                if (!\is_string($first) && !\is_int($first)) {
                    throw new ImportmapParseException('array keys must be strings or integers', $this->text, $firstSpan->start);
                }
                [$value, $valueSpan, $quote] = $this->parseValue();
                $items[] = new ArrayItem($first, $firstSpan, $value, $valueSpan, new Span($firstSpan->start, $valueSpan->end), $quote);
            } else {
                $items[] = new ArrayItem(null, null, $first, $firstSpan, $firstSpan, $firstQuote);
            }

            $separator = $this->tokens[$this->pos] ?? null;
            if (null !== $separator && ',' === $separator[0]) {
                ++$this->pos;
                continue;
            }
            if (null === $separator || $close !== $separator[0]) {
                throw new ImportmapParseException(\sprintf('expected "," or "%s"', $close), $this->text, $separator[2] ?? \strlen($this->text));
            }
        }
    }

    private function peek(): int|string|null
    {
        return isset($this->tokens[$this->pos]) ? $this->tokens[$this->pos][0] : null;
    }

    private function expect(string $char): void
    {
        $token = $this->tokens[$this->pos++] ?? null;
        if ($char !== ($token[0] ?? null)) {
            throw new ImportmapParseException(\sprintf('expected "%s"', $char), $this->text, $token[2] ?? \strlen($this->text));
        }
    }

    private static function decodeString(string $raw): string
    {
        $body = substr($raw, 1, -1);
        if ("'" === $raw[0]) {
            return preg_replace_callback('/\\\\([\\\\\'])/', static fn (array $m): string => $m[1], $body) ?? $body;
        }

        return preg_replace_callback(
            '/\\\\(?:[nrtvef\\\\$"]|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})/',
            static function (array $m): string {
                $sequence = substr($m[0], 1);

                return match (true) {
                    'x' === $sequence[0] => \chr((int) hexdec(substr($sequence, 1))),
                    'u' === $sequence[0] => mb_chr((int) hexdec(substr($sequence, 2, -1)), 'UTF-8') ?: '',
                    ctype_digit($sequence[0]) => \chr(octdec($sequence) & 0xFF),
                    default => ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\e", 'f' => "\f"][$sequence] ?? $sequence,
                };
            },
            $body,
        ) ?? $body;
    }

    /**
     * @param array<string, ArrayItem> $attributes
     */
    private static function toEntry(string $key, array $attributes): ImportmapEntry
    {
        $string = static fn (string $name): ?string => \is_string($attributes[$name]->value ?? null) ? $attributes[$name]->value : null;

        return new ImportmapEntry(
            key: $key,
            version: $string('version'),
            path: $string('path'),
            type: $string('type'),
            entrypoint: true === ($attributes['entrypoint']->value ?? null),
            packageSpecifier: $string('package_specifier'),
        );
    }
}
