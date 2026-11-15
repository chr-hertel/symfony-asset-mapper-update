<?php

namespace AssetMapperUpdate\Adapter;

use AssetMapperUpdate\Log;
use AssetMapperUpdate\PullRequest\PullRequestState;
use AssetMapperUpdate\Reconciler\ClosedPullRequest;
use AssetMapperUpdate\Reconciler\OpenPullRequest;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The few GitHub REST API calls the action needs.
 */
final class GitHubClient
{
    public const PERMISSION_HINT = 'GitHub Actions is not allowed to create pull requests in this repository. Enable "Allow GitHub Actions to create and approve pull requests" under Settings → Actions → General, or pass a GitHub App token or a personal access token as "github-token".';

    private const WEB_FLOW_EMAIL = 'noreply@github.com';

    private readonly HttpClientInterface $http;

    /**
     * @param string $repository `owner/repo`
     */
    public function __construct(
        string $token,
        private readonly string $repository,
        private readonly Log $log = new Log(),
        string $apiUrl = 'https://api.github.com',
        ?HttpClientInterface $http = null,
        private readonly int $mergeableRetryDelayMs = 1500,
    ) {
        $this->http = ($http ?? new RetryableHttpClient(HttpClient::create(['timeout' => 30])))->withOptions([
            'base_uri' => rtrim($apiUrl, '/').'/',
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer '.$token,
                'User-Agent' => 'asset-mapper-update',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
        ]);
    }

    public function defaultBranch(): string
    {
        $repo = $this->request('GET', $this->repo(''));

        return \is_string($repo['default_branch'] ?? null) ? $repo['default_branch'] : throw new GitHubException('The repository has no default branch.');
    }

    /**
     * Whether an advisory was withdrawn; `importmap:audit` reports withdrawn advisories too. Falls
     * back to the summary ("Withdrawn Advisory: …") if the API call fails.
     */
    public function isWithdrawn(string $ghsaId, string $summary): bool
    {
        try {
            return null !== ($this->request('GET', 'advisories/'.rawurlencode($ghsaId))['withdrawn_at'] ?? null);
        } catch (GitHubException $e) {
            $this->log->debug(\sprintf('Could not load advisory %s: %s', $ghsaId, $e->getMessage()));

            return 1 === preg_match('/^\s*withdrawn\b/i', $summary);
        }
    }

    /**
     * Open PRs that carry the marker, with what the reconciler needs to know about them.
     *
     * @return list<OpenPullRequest>
     */
    public function openPullRequests(string $prefix, string $base): array
    {
        $result = [];
        foreach ($this->paginate($this->repo('/pulls'), ['state' => 'open', 'base' => $base]) as $data) {
            $pr = $this->pullRequest($data, $prefix, $base);
            if (null === $pr) {
                continue;
            }
            $state = PullRequestState::fromBody($pr['body']);
            if (null === $state) {
                $this->log->info(\sprintf('Ignoring #%d (%s): it has no asset-mapper-update marker.', $pr['number'], $pr['branch']));
                continue;
            }
            $foreignCommits = $this->hasForeignCommits($pr['number']);
            $result[] = new OpenPullRequest(
                number: $pr['number'],
                url: $pr['url'],
                branch: $pr['branch'],
                state: $state,
                conflicting: $this->isConflicting($pr['number']),
                foreignCommits: $foreignCommits,
                manualCommitsNoticePosted: $foreignCommits && $this->hasComment($pr['number'], PullRequestState::MANUAL_COMMITS_MARKER),
                headSha: $pr['headSha'],
            );
        }

        return $result;
    }

    /**
     * Closed PRs with the marker for the given branches, so an update a human closed is not
     * proposed again.
     *
     * @param list<string> $branches
     *
     * @return list<ClosedPullRequest>
     */
    public function closedPullRequests(string $prefix, string $base, array $branches): array
    {
        $owner = explode('/', $this->repository, 2)[0];
        $result = [];
        foreach ($branches as $branch) {
            // Filtered by branch, so old closed PRs are found however many other PRs were closed since.
            foreach ($this->paginate($this->repo('/pulls'), ['state' => 'closed', 'base' => $base, 'head' => $owner.':'.$branch]) as $data) {
                $pr = $this->pullRequest($data, $prefix, $base);
                $state = PullRequestState::fromBody($pr['body'] ?? null);
                if (null !== $pr && null !== $state) {
                    $result[] = new ClosedPullRequest($pr['number'], $pr['branch'], $state, $pr['merged']);
                }
            }
        }

        return $result;
    }

    /**
     * Whether a branch exists with commits the action did not create (relevant when there is no open PR).
     */
    public function branchHasForeignCommits(string $branch, string $base): bool
    {
        try {
            $compare = $this->request('GET', $this->repo('/compare/'.rawurlencode($base).'...'.rawurlencode($branch)));
        } catch (GitHubException $e) {
            if (404 === $e->status) {
                return false;
            }
            throw $e;
        }

        return self::anyForeign(\is_array($compare['commits'] ?? null) ? $compare['commits'] : []);
    }

    /**
     * @param list<string> $labels
     * @param list<string> $reviewers users, or `org/team-slug` for teams
     * @param list<string> $assignees
     *
     * @return array{number: int, url: string}
     */
    public function createPullRequest(string $branch, string $base, string $title, string $body, array $labels, array $reviewers, array $assignees): array
    {
        try {
            $pr = $this->request('POST', $this->repo('/pulls'), ['head' => $branch, 'base' => $base, 'title' => $title, 'body' => $body, 'maintainer_can_modify' => true]);
        } catch (GitHubException $e) {
            if (1 === preg_match('/not permitted to create or approve pull requests/i', $e->getMessage())) {
                throw new GitHubException(self::PERMISSION_HINT, $e->status, $e);
            }
            throw $e;
        }
        $number = \is_int($pr['number'] ?? null) ? $pr['number'] : throw new GitHubException('GitHub did not return the number of the new pull request.');

        if ([] !== $labels) {
            $this->warnOnFailure('add labels', fn () => $this->request('POST', $this->repo('/issues/'.$number.'/labels'), ['labels' => $labels]));
        }
        if ([] !== $reviewers) {
            $users = array_values(array_filter($reviewers, static fn (string $r): bool => !str_contains($r, '/')));
            $teams = array_values(array_map(static fn (string $r): string => explode('/', $r, 2)[1], array_filter($reviewers, static fn (string $r): bool => str_contains($r, '/'))));
            $this->warnOnFailure('request reviewers', fn () => $this->request('POST', $this->repo('/pulls/'.$number.'/requested_reviewers'), ['reviewers' => $users, 'team_reviewers' => $teams]));
        }
        if ([] !== $assignees) {
            $this->warnOnFailure('add assignees', fn () => $this->request('POST', $this->repo('/issues/'.$number.'/assignees'), ['assignees' => $assignees]));
        }

        return ['number' => $number, 'url' => $pr['html_url'] ?? ''];
    }

    public function updatePullRequest(int $number, string $title, string $body): void
    {
        $this->request('PATCH', $this->repo('/pulls/'.$number), ['title' => $title, 'body' => $body]);
    }

    public function comment(int $number, string $body): void
    {
        $this->request('POST', $this->repo('/issues/'.$number.'/comments'), ['body' => $body]);
    }

    /**
     * Comments, closes the PR (flagged as closed by the action) and deletes the branch.
     */
    public function closePullRequest(int $number, string $branch, PullRequestState $state, string $comment): void
    {
        $this->comment($number, $comment);
        $pr = $this->request('GET', $this->repo('/pulls/'.$number));
        $this->request('PATCH', $this->repo('/pulls/'.$number), [
            'state' => 'closed',
            'body' => $state->withClosedByAction()->replaceIn($pr['body'] ?? ''),
        ]);
        $this->warnOnFailure('delete branch '.$branch, fn () => $this->request('DELETE', $this->repo('/git/refs/heads/'.implode('/', array_map(rawurlencode(...), explode('/', $branch))))));
    }

    /**
     * The fields the action needs, if the pull request is one of ours: a branch with the prefix in
     * this repository (not a fork), targeting the base branch.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array{number: int, url: string, branch: string, body: string|null, merged: bool, headSha: string|null}|null
     */
    private function pullRequest(array $data, string $prefix, string $base): ?array
    {
        $number = $data['number'] ?? null;
        $branch = $data['head']['ref'] ?? null;
        if (!\is_int($number) || !\is_string($branch) || !str_starts_with($branch, $prefix.'/')
            || ($data['head']['repo']['full_name'] ?? null) !== $this->repository
            || ($data['base']['ref'] ?? null) !== $base) {
            return null;
        }

        return [
            'number' => $number,
            'url' => $data['html_url'] ?? '',
            'branch' => $branch,
            'body' => $data['body'] ?? null,
            'merged' => null !== ($data['merged_at'] ?? null),
            'headSha' => $data['head']['sha'] ?? null,
        ];
    }

    private function isConflicting(int $number): bool
    {
        // `mergeable` is computed lazily; it is null on the first request.
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $pr = $this->request('GET', $this->repo('/pulls/'.$number));
            if (null !== ($pr['mergeable'] ?? null)) {
                return false === $pr['mergeable'] || 'dirty' === ($pr['mergeable_state'] ?? null);
            }
            usleep($this->mergeableRetryDelayMs * 1000);
        }

        return false;
    }

    private function hasForeignCommits(int $number): bool
    {
        return self::anyForeign($this->paginate($this->repo('/pulls/'.$number.'/commits')));
    }

    private function hasComment(int $number, string $marker): bool
    {
        foreach ($this->paginate($this->repo('/issues/'.$number.'/comments')) as $comment) {
            if (str_contains($comment['body'] ?? '', $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $commits
     */
    private static function anyForeign(array $commits): bool
    {
        foreach ($commits as $commit) {
            if (!\is_array($commit)) {
                return true;
            }
            if (Git::BOT_EMAIL === ($commit['commit']['author']['email'] ?? null)) {
                continue;
            }
            // A merge made on github.com ("Update branch" or the web conflict editor) mostly brings in
            // the base branch. A conflict resolution written by hand in it is not kept when the branch
            // is rebuilt, which only redoes the action's version bumps. Local merges still count.
            $parents = $commit['parents'] ?? null;
            if (\is_array($parents) && \count($parents) > 1 && self::WEB_FLOW_EMAIL === ($commit['commit']['committer']['email'] ?? null)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return list<array<array-key, mixed>>
     */
    private function paginate(string $path, array $query = []): array
    {
        $items = [];
        $url = $path;
        $query += ['per_page' => 100];
        for ($page = 0; null !== $url && $page < 50; ++$page) {
            [$data, $headers] = $this->send('GET', $url, null, 0 === $page ? $query : []);
            foreach ($data as $item) {
                if (\is_array($item)) {
                    $items[] = $item;
                }
            }
            $url = null;
            foreach ($headers['link'] ?? [] as $link) {
                if (1 === preg_match('/<([^>]+)>;\s*rel="next"/', $link, $m)) {
                    $url = $m[1];
                }
            }
        }

        return $items;
    }

    private function repo(string $path): string
    {
        return 'repos/'.$this->repository.$path;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $query
     *
     * @return array<array-key, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        return $this->send($method, $path, $body, $query)[0];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string|int> $query
     *
     * @return array{array<array-key, mixed>, array<string, list<string>>}
     */
    private function send(string $method, string $path, ?array $body, array $query): array
    {
        $options = ['query' => $query];
        if (null !== $body) {
            $options['json'] = $body;
        }
        try {
            $response = $this->http->request($method, $path, $options);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new GitHubException(\sprintf('%s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if ($status >= 400) {
            $message = \is_array($data) && \is_string($data['message'] ?? null) ? $data['message'] : $content;
            if (\is_array($data) && \is_array($data['errors'] ?? null)) {
                foreach ($data['errors'] as $error) {
                    $message .= \is_array($error) && \is_string($error['message'] ?? null) ? ' '.$error['message'] : '';
                }
            }
            throw new GitHubException(\sprintf('%s %s failed (HTTP %d): %s', $method, $path, $status, $message), $status);
        }

        return [\is_array($data) ? $data : [], $headers];
    }

    private function warnOnFailure(string $what, \Closure $fn): void
    {
        try {
            $fn();
        } catch (GitHubException $e) {
            $this->log->warning(\sprintf('Could not %s: %s', $what, $e->getMessage()));
        }
    }
}
