<?php

namespace AssetMapperUpdate\Config;

use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\Strategy;

final readonly class Config
{
    /**
     * @param array<value-of<Bucket>, Strategy> $grouping
     * @param list<IgnoreRule>                  $ignore
     * @param list<string>                      $labels
     * @param list<string>                      $reviewers
     * @param list<string>                      $assignees
     */
    public function __construct(
        public array $grouping = [
            'security' => Strategy::Individual,
            'patch' => Strategy::Grouped,
            'minor' => Strategy::Grouped,
            'major' => Strategy::Individual,
        ],
        public array $ignore = [],
        public int $openPullRequestsLimit = 10,
        public array $labels = ['dependencies', 'javascript'],
        public string $branchPrefix = 'importmap-updates',
        public string $commitMessagePrefix = 'chore(deps):',
        public array $reviewers = [],
        public array $assignees = [],
    ) {
    }

    public function strategy(Bucket $bucket): Strategy
    {
        return $this->grouping[$bucket->value];
    }

    /**
     * @return list<IgnoreRule>
     */
    public function ignoreRulesFor(string $package): array
    {
        return array_values(array_filter($this->ignore, static fn (IgnoreRule $rule): bool => $rule->matches($package)));
    }
}
