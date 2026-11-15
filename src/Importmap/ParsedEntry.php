<?php

namespace AssetMapperUpdate\Importmap;

use AssetMapperUpdate\Model\ImportmapEntry;

final readonly class ParsedEntry
{
    /**
     * @param array<string, ArrayItem> $attributes attribute items of the entry's array, by name
     */
    public function __construct(
        public ImportmapEntry $entry,
        public ArrayItem $item,
        public PhpArray $value,
        public array $attributes,
    ) {
    }
}
