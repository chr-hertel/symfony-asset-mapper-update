<?php

namespace AssetMapperUpdate\Model;

final readonly class PlannedPullRequest
{
    /**
     * @param list<PlannedUpdate> $updates
     */
    public function __construct(
        public string $branch,
        public Bucket $bucket,
        public Strategy $strategy,
        public array $updates,
    ) {
    }

    public function single(): ?PlannedUpdate
    {
        return 1 === \count($this->updates) ? $this->updates[0] : null;
    }

    public function hasUpdateFor(string $name): bool
    {
        return null !== $this->updateFor($name);
    }

    public function updateFor(string $name): ?PlannedUpdate
    {
        foreach ($this->updates as $update) {
            if ($update->name === $name) {
                return $update;
            }
        }

        return null;
    }
}
