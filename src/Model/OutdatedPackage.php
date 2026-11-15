<?php

namespace AssetMapperUpdate\Model;

final readonly class OutdatedPackage
{
    public function __construct(
        public string $name,
        public string $current,
        public string $latest,
        public string $latestStatus,
    ) {
    }
}
