<?php

namespace AssetMapperUpdate;

final readonly class PullRequestResult
{
    /**
     * @param 'created'|'updated'|'unchanged'|'closed'|'blocked'|'deferred'|'skipped'|'failed' $action
     */
    public function __construct(
        public string $action,
        public string $branch,
        public string $detail,
        public bool $dryRun,
        public ?int $number = null,
        public ?string $url = null,
        public ?string $title = null,
    ) {
    }
}
