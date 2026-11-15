<?php

namespace AssetMapperUpdate\Importmap;

final readonly class ArrayItem
{
    /**
     * @param string|null $quote quote character of the value if it is a string literal
     */
    public function __construct(
        public string|int|null $key,
        public ?Span $keySpan,
        public string|int|float|bool|PhpArray|null $value,
        public Span $valueSpan,
        public Span $span,
        public ?string $quote = null,
    ) {
    }
}
