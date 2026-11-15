<?php

namespace AssetMapperUpdate\PullRequest;

final readonly class ApplyDetails
{
    /**
     * @param array<string, array<array-key, mixed>> $additions          new entries that `importmap:require` added (new dependencies)
     * @param list<string>                           $ignoredSideEffects changes `importmap:require` wanted to make to other entries; not applied
     */
    public function __construct(
        public array $additions = [],
        public array $ignoredSideEffects = [],
    ) {
    }
}
