<?php

namespace AssetMapperUpdate\Model;

final readonly class SkippedPackage
{
    /**
     * @param bool $unknown the package could not be planned because data is missing (e.g. the npm
     *                      registry failed). Its existing pull requests are kept as they are.
     */
    public function __construct(
        public string $name,
        public string $current,
        public string $reason,
        public bool $unknown = false,
    ) {
    }
}
