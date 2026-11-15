<?php

namespace AssetMapperUpdate\Importmap;

final readonly class ParsedImportmap
{
    /**
     * @param list<ParsedEntry> $entries
     */
    public function __construct(
        public string $text,
        public PhpArray $root,
        public array $entries,
    ) {
    }

    public function entry(string $key): ?ParsedEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The importmap as plain data: key => attributes.
     *
     * @return array<string, array<array-key, mixed>>
     */
    public function toPlainMap(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            $out[$entry->entry->key] = $entry->value->toPlain();
        }

        return $out;
    }
}
