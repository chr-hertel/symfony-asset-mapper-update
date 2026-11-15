<?php

namespace AssetMapperUpdate\Model;

final readonly class Advisory
{
    /**
     * @param string $url human readable page, e.g. https://github.com/advisories/GHSA-xxxx
     */
    public function __construct(
        public string $ghsaId,
        public ?string $cveId,
        public Severity $severity,
        public string $summary,
        public string $url,
        public string $vulnerableVersionRange,
        public ?string $firstPatchedVersion,
    ) {
    }
}
