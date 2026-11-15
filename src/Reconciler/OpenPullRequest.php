<?php

namespace AssetMapperUpdate\Reconciler;

use AssetMapperUpdate\PullRequest\PullRequestState;

/**
 * An open pull request that carries the action's marker.
 */
final readonly class OpenPullRequest
{
    /**
     * @param bool        $conflicting               GitHub reports merge conflicts with the base branch
     * @param bool        $foreignCommits            the branch has commits the action did not create
     * @param bool        $manualCommitsNoticePosted the action already commented about the foreign commits
     * @param string|null $headSha                   the head commit when the PR was listed; pushes only replace this commit
     */
    public function __construct(
        public int $number,
        public string $url,
        public string $branch,
        public PullRequestState $state,
        public bool $conflicting = false,
        public bool $foreignCommits = false,
        public bool $manualCommitsNoticePosted = false,
        public ?string $headSha = null,
    ) {
    }
}
