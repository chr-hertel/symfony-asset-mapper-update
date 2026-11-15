<?php

namespace AssetMapperUpdate\Model;

/**
 * What the planner needs to know about a package from the npm registry.
 */
final readonly class RegistryPackage
{
    /**
     * @param list<string> $versions   all published versions
     * @param list<string> $deprecated versions marked as deprecated (bad releases, or whole end-of-life lines like bootstrap 4)
     * @param string       $latest     the `latest` dist-tag
     */
    public function __construct(
        public string $name,
        public array $versions,
        public array $deprecated,
        public string $latest,
    ) {
    }
}
