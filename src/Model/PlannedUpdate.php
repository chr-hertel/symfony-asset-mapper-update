<?php

namespace AssetMapperUpdate\Model;

final class PlannedUpdate
{
    /**
     * @param list<string>   $entries      importmap keys that are bumped together
     * @param string         $reason       human readable explanation of why this target version was chosen
     * @param list<Advisory> $advisories
     * @param bool           $crossesMajor set for security fixes that cross a major version because no fix exists in the current one
     */
    public function __construct(
        public readonly string $name,
        public readonly string $from,
        public readonly string $to,
        public readonly UpdateType $updateType,
        public readonly Bucket $bucket,
        public readonly array $entries,
        public readonly string $reason,
        public readonly array $advisories = [],
        public readonly bool $crossesMajor = false,
        public ?PackageLinks $links = null,
    ) {
    }
}
