<?php

namespace AssetMapperUpdate\PullRequest;

use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\Model\PlannedUpdate;

/**
 * The state embedded in the PR body as a hidden marker. It tells a later run whether a pull request
 * still matches the plan.
 */
final readonly class PullRequestState
{
    public const MARKER = 'asset-mapper-update';
    public const MANUAL_COMMITS_MARKER = '<!-- asset-mapper-update:manual-commits -->';
    private const MARKER_PATTERN = '/<!--\s*asset-mapper-update\s+(\{.*?\})\s*-->/s';

    /**
     * @param list<array{name: string, from: string, to: string}> $updates
     * @param bool                                                $closedByAction set when the action closed the PR itself, so a later run knows a human did not
     */
    public function __construct(
        public string $bucket,
        public array $updates,
        public bool $closedByAction = false,
    ) {
    }

    public static function of(PlannedPullRequest $pr): self
    {
        $updates = array_map(static fn (PlannedUpdate $u): array => ['name' => $u->name, 'from' => $u->from, 'to' => $u->to], $pr->updates);
        usort($updates, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return new self($pr->bucket->value, $updates);
    }

    public function withClosedByAction(): self
    {
        return new self($this->bucket, $this->updates, true);
    }

    /**
     * Same bucket and the same updates, in any order. `closedByAction` is not compared.
     */
    public function equals(self $other): bool
    {
        return $this->normalized() === $other->normalized();
    }

    /**
     * @return array{v: 1, bucket: string, updates: list<array{name: string, from: string, to: string}>, closedByAction?: true}
     */
    public function toArray(): array
    {
        $data = ['v' => 1, 'bucket' => $this->bucket, 'updates' => $this->updates];
        if ($this->closedByAction) {
            $data['closedByAction'] = true;
        }

        return $data;
    }

    public function render(): string
    {
        return \sprintf('<!-- %s %s -->', self::MARKER, json_encode($this->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    public static function fromBody(?string $body): ?self
    {
        if (null === $body || 1 !== preg_match(self::MARKER_PATTERN, $body, $m)) {
            return null;
        }
        $data = json_decode($m[1], true);
        if (!\is_array($data) || 1 !== ($data['v'] ?? null) || !\is_string($data['bucket'] ?? null) || !\is_array($data['updates'] ?? null)) {
            return null;
        }
        $updates = [];
        foreach ($data['updates'] as $u) {
            if (!\is_array($u) || !\is_string($u['name'] ?? null) || !\is_string($u['from'] ?? null) || !\is_string($u['to'] ?? null)) {
                return null;
            }
            $updates[] = ['name' => $u['name'], 'from' => $u['from'], 'to' => $u['to']];
        }

        return new self($data['bucket'], $updates, true === ($data['closedByAction'] ?? false));
    }

    /**
     * Replaces the marker in a body, e.g. to flag the PR as closed by the action.
     */
    public function replaceIn(string $body): string
    {
        if (1 !== preg_match(self::MARKER_PATTERN, $body)) {
            return $body."\n\n".$this->render();
        }

        return (string) preg_replace_callback(self::MARKER_PATTERN, fn (): string => $this->render(), $body, 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function updatesByName(): array
    {
        $out = [];
        foreach ($this->updates as $u) {
            $out[$u['name']] = [$u['from'], $u['to']];
        }

        return $out;
    }

    private function normalized(): string
    {
        $updates = $this->updates;
        usort($updates, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return json_encode([$this->bucket, array_map(static fn (array $u): array => [$u['name'], $u['from'], $u['to']], $updates)], \JSON_THROW_ON_ERROR);
    }
}
