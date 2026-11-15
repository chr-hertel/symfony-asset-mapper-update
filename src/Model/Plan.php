<?php

namespace AssetMapperUpdate\Model;

final readonly class Plan
{
    /**
     * @param list<PlannedPullRequest> $pullRequests
     * @param list<SkippedPackage>     $skipped
     */
    public function __construct(
        public array $pullRequests,
        public array $skipped,
    ) {
    }

    /**
     * @return list<PlannedUpdate>
     */
    public function updates(): array
    {
        return array_merge(...array_map(static fn (PlannedPullRequest $pr): array => $pr->updates, $this->pullRequests));
    }
}
