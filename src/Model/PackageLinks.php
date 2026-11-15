<?php

namespace AssetMapperUpdate\Model;

final readonly class PackageLinks
{
    public function __construct(
        public string $npm,
        public ?string $repository = null,
        public ?string $releaseNotes = null,
    ) {
    }
}
