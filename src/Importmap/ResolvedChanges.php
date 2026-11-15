<?php

namespace AssetMapperUpdate\Importmap;

final readonly class ResolvedChanges
{
    /**
     * @param array<string, string>                  $versionChanges     key → new version, for the planned entries
     * @param array<string, array<array-key, mixed>> $additions          entries that `importmap:require` added (new dependencies)
     * @param list<string>                           $ignoredSideEffects changes `importmap:require` made to other entries; they are not applied
     */
    public function __construct(
        public array $versionChanges,
        public array $additions,
        public array $ignoredSideEffects,
    ) {
    }
}
