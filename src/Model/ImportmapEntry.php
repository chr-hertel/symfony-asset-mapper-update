<?php

namespace AssetMapperUpdate\Model;

/**
 * One entry of importmap.php, as written in the file.
 */
final readonly class ImportmapEntry
{
    public function __construct(
        public string $key,
        public ?string $version = null,
        public ?string $path = null,
        public ?string $type = null,
        public bool $entrypoint = false,
        public ?string $packageSpecifier = null,
    ) {
    }

    public function specifier(): string
    {
        return $this->packageSpecifier ?? $this->key;
    }
}
