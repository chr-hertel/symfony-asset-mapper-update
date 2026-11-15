<?php

namespace AssetMapperUpdate\Reconciler;

use AssetMapperUpdate\PullRequest\PullRequestState;

/**
 * A closed pull request that carries the action's marker.
 */
final readonly class ClosedPullRequest
{
    public function __construct(
        public int $number,
        public string $branch,
        public PullRequestState $state,
        public bool $merged,
    ) {
    }
}
