<?php

namespace AssetMapperUpdate\Reconciler;

use AssetMapperUpdate\Model\PlannedPullRequest;

final readonly class Action
{
    /**
     * @param string|null $reason       why a PR is updated or closed
     * @param string|null $wanted       for blocked PRs: what the action would have done without the foreign commits
     * @param bool        $notify       for blocked PRs: comment on the PR
     * @param int|null    $closedNumber for skip-closed: the PR a human closed
     */
    public function __construct(
        public ActionType $type,
        public ?PlannedPullRequest $pr = null,
        public ?OpenPullRequest $existing = null,
        public ?string $reason = null,
        public ?string $wanted = null,
        public bool $notify = false,
        public ?int $closedNumber = null,
    ) {
    }

    public function branch(): string
    {
        return $this->pr->branch ?? $this->existing->branch ?? '?';
    }
}
