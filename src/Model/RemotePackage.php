<?php

namespace AssetMapperUpdate\Model;

/**
 * A remote package of the importmap, with all entries (JS and CSS sub-paths) that belong to it.
 */
final readonly class RemotePackage
{
    /**
     * @param string       $version version of the main entry, or of the first entry if there is no main entry
     * @param list<string> $entries importmap keys of the package, e.g. `bootstrap`, `bootstrap/dist/css/bootstrap.min.css`
     */
    public function __construct(
        public string $name,
        public string $version,
        public array $entries,
    ) {
    }
}
