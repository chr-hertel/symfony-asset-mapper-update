<?php

namespace AssetMapperUpdate\Importmap;

/**
 * Byte offsets in the importmap.php text; $end is exclusive.
 */
final readonly class Span
{
    public function __construct(
        public int $start,
        public int $end,
    ) {
    }
}
