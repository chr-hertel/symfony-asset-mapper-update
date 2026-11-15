<?php

namespace AssetMapperUpdate\Importmap;

final readonly class PhpArray
{
    /**
     * @param list<ArrayItem> $items
     * @param bool            $long  `array(…)` instead of `[…]`
     */
    public function __construct(
        public array $items,
        public Span $span,
        public bool $long,
    ) {
    }

    /**
     * Plain PHP value, for comparisons.
     *
     * @return array<array-key, mixed>
     */
    public function toPlain(): array
    {
        $out = [];
        foreach ($this->items as $item) {
            $value = $item->value instanceof self ? $item->value->toPlain() : $item->value;
            if (null === $item->key) {
                $out[] = $value;
            } else {
                $out[$item->key] = $value;
            }
        }

        return $out;
    }
}
