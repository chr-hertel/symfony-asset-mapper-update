<?php

namespace AssetMapperUpdate\Tests\Adapter;

use AssetMapperUpdate\Adapter\Git;
use AssetMapperUpdate\Adapter\GitHubClient;
use AssetMapperUpdate\Adapter\GitHubException;
use AssetMapperUpdate\Log;
use AssetMapperUpdate\PullRequest\PullRequestState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubClientTest extends TestCase
{
    private const MARKER = '<!-- asset-mapper-update {"v":1,"bucket":"patch","updates":[{"name":"a","from":"1.0.0","to":"1.0.1"}]} -->';

    /** @var list<array{string, string, string}> method, url, body */
    private array $requests = [];

    public function testOpenPullRequestsFollowsPaginationAndOnlyReturnsOurs(): void
    {
        $output = new BufferedOutput();
        $client = $this->client([
            'GET /repos/o/r/pulls?state=open&base=main&per_page=100' => new MockResponse(json_encode([
                self::pr(1, 'importmap-updates/patch', self::MARKER),
                self::pr(2, 'feature/x', self::MARKER),
                self::pr(3, 'importmap-updates/minor', 'no marker'),
                self::pr(4, 'importmap-updates/fork', self::MARKER, repo: 'someone/r'),
            ], \JSON_THROW_ON_ERROR), ['response_headers' => ['link' => '<https://api.github.com/repos/o/r/pulls?state=open&base=main&per_page=100&page=2>; rel="next"']]),
            'GET /repos/o/r/pulls?state=open&base=main&per_page=100&page=2' => self::json([self::pr(5, 'importmap-updates/major/b', self::MARKER)]),
            'GET /repos/o/r/pulls/1/commits?per_page=100' => self::json([self::commit(Git::BOT_EMAIL)]),
            'GET /repos/o/r/pulls/1' => [self::json(['mergeable' => null]), self::json(['mergeable' => true, 'mergeable_state' => 'clean'])],
            'GET /repos/o/r/pulls/5/commits?per_page=100' => self::json([self::commit(Git::BOT_EMAIL), self::commit('human@example.com')]),
            'GET /repos/o/r/pulls/5' => self::json(['mergeable' => false]),
            'GET /repos/o/r/issues/5/comments?per_page=100' => self::json([['body' => 'hi'], ['body' => PullRequestState::MANUAL_COMMITS_MARKER]]),
        ], $output);

        $open = $client->openPullRequests('importmap-updates', 'main');

        $this->assertCount(2, $open);
        [$first, $second] = $open;
        $this->assertSame([1, 'importmap-updates/patch', false, false, false], [$first->number, $first->branch, $first->conflicting, $first->foreignCommits, $first->manualCommitsNoticePosted]);
        $this->assertSame([5, true, true, true], [$second->number, $second->conflicting, $second->foreignCommits, $second->manualCommitsNoticePosted]);
        $this->assertStringContainsString('Ignoring #3 (importmap-updates/minor): it has no asset-mapper-update marker.', $output->fetch());
    }

    public function testMergesMadeOnGitHubAreNotForeignCommits(): void
    {
        $webMerge = ['commit' => ['author' => ['email' => 'human@example.com'], 'committer' => ['email' => 'noreply@github.com']], 'parents' => [['sha' => 'a'], ['sha' => 'b']]];
        $localMerge = ['commit' => ['author' => ['email' => 'human@example.com'], 'committer' => ['email' => 'human@example.com']], 'parents' => [['sha' => 'a'], ['sha' => 'b']]];
        $client = $this->client([
            'GET /repos/o/r/pulls?state=open&base=main&per_page=100' => self::json([self::pr(1, 'importmap-updates/patch', self::MARKER), self::pr(2, 'importmap-updates/minor', self::MARKER)]),
            'GET /repos/o/r/pulls/1/commits?per_page=100' => self::json([self::commit(Git::BOT_EMAIL), $webMerge]),
            'GET /repos/o/r/pulls/2/commits?per_page=100' => self::json([self::commit(Git::BOT_EMAIL), $localMerge]),
            'GET /repos/o/r/pulls/1' => self::json(['mergeable' => true]),
            'GET /repos/o/r/pulls/2' => self::json(['mergeable' => true]),
            'GET /repos/o/r/issues/2/comments?per_page=100' => self::json([]),
        ]);
        $open = $client->openPullRequests('importmap-updates', 'main');
        $this->assertSame([[1, false, 'sha-1'], [2, true, 'sha-2']], array_map(static fn ($pr): array => [$pr->number, $pr->foreignCommits, $pr->headSha], $open));
    }

    public function testClosedPullRequests(): void
    {
        $merged = self::pr(7, 'importmap-updates/patch', self::MARKER);
        $merged['merged_at'] = '2026-11-01T00:00:00Z';
        $client = $this->client([
            'GET /repos/o/r/pulls?state=closed&base=main&head=o:importmap-updates/patch&per_page=100' => self::json([$merged, self::pr(8, 'importmap-updates/patch', self::MARKER)]),
            'GET /repos/o/r/pulls?state=closed&base=main&head=o:importmap-updates/major/@scope/x&per_page=100' => self::json([self::pr(9, 'importmap-updates/major/@scope/x', 'no marker')]),
        ]);
        $closed = $client->closedPullRequests('importmap-updates', 'main', ['importmap-updates/patch', 'importmap-updates/major/@scope/x']);
        $this->assertSame([[7, true], [8, false]], array_map(static fn ($c): array => [$c->number, $c->merged], $closed));
        $this->assertSame([], $client->closedPullRequests('importmap-updates', 'main', []));
    }

    public function testCreatePullRequestAddsLabelsReviewersAndAssignees(): void
    {
        $client = $this->client([
            'POST /repos/o/r/pulls' => self::json(['number' => 12, 'html_url' => 'https://github.com/o/r/pull/12'], 201),
            'POST /repos/o/r/issues/12/labels' => self::json([]),
            'POST /repos/o/r/pulls/12/requested_reviewers' => self::json([], 422),
            'POST /repos/o/r/issues/12/assignees' => self::json([]),
        ], $output = new BufferedOutput());

        $created = $client->createPullRequest('importmap-updates/patch', 'main', 'Bump a', 'body', ['dependencies'], ['octocat', 'org/team'], ['hubot']);

        $this->assertSame(['number' => 12, 'url' => 'https://github.com/o/r/pull/12'], $created);
        $this->assertSame(['head' => 'importmap-updates/patch', 'base' => 'main', 'title' => 'Bump a', 'body' => 'body', 'maintainer_can_modify' => true], json_decode($this->requests[0][2], true));
        $this->assertSame(['reviewers' => ['octocat'], 'team_reviewers' => ['team']], json_decode($this->requests[2][2], true));
        $this->assertStringContainsString('Could not request reviewers', $output->fetch());
    }

    public function testExplainsTheMissingActionsPermission(): void
    {
        $client = $this->client(['POST /repos/o/r/pulls' => self::json(['message' => 'GitHub Actions is not permitted to create or approve pull requests.'], 403)]);
        $this->expectExceptionMessage('Allow GitHub Actions to create and approve pull requests');
        $client->createPullRequest('b', 'main', 't', 'b', [], [], []);
    }

    public function testClosePullRequestFlagsTheMarkerAndDeletesTheBranch(): void
    {
        $client = $this->client([
            'POST /repos/o/r/issues/3/comments' => self::json([], 201),
            'GET /repos/o/r/pulls/3' => self::json(['body' => "Text\n\n".self::MARKER]),
            'PATCH /repos/o/r/pulls/3' => self::json([]),
            'DELETE /repos/o/r/git/refs/heads/importmap-updates/patch' => new MockResponse('', ['http_code' => 204]),
        ]);
        $state = PullRequestState::fromBody(self::MARKER);
        $this->assertNotNull($state);
        $client->closePullRequest(3, 'importmap-updates/patch', $state, 'Closing');

        $patch = json_decode($this->requests[2][2], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($patch);
        $this->assertSame('closed', $patch['state']);
        $this->assertIsString($patch['body']);
        $this->assertTrue(PullRequestState::fromBody($patch['body'])?->closedByAction);
    }

    public function testBranchHasForeignCommits(): void
    {
        $client = $this->client([
            'GET /repos/o/r/compare/main...importmap-updates%2Fpatch' => self::json(['commits' => [self::commit('human@example.com')]]),
            'GET /repos/o/r/compare/main...importmap-updates%2Fminor' => self::json(['message' => 'Not Found'], 404),
        ]);
        $this->assertTrue($client->branchHasForeignCommits('importmap-updates/patch', 'main'));
        $this->assertFalse($client->branchHasForeignCommits('importmap-updates/minor', 'main'));
    }

    public function testIsWithdrawnFallsBackToTheSummary(): void
    {
        $client = $this->client([
            'GET /advisories/GHSA-1' => self::json(['withdrawn_at' => '2024-01-01T00:00:00Z']),
            'GET /advisories/GHSA-2' => self::json(['withdrawn_at' => null]),
            'GET /advisories/GHSA-3' => self::json(['message' => 'Rate limited'], 403),
        ]);
        $this->assertTrue($client->isWithdrawn('GHSA-1', ''));
        $this->assertFalse($client->isWithdrawn('GHSA-2', 'Withdrawn Advisory: x'));
        $this->assertTrue($client->isWithdrawn('GHSA-3', 'Withdrawn Advisory: x'));
    }

    public function testErrorsIncludeTheGitHubMessage(): void
    {
        $client = $this->client(['GET /repos/o/r' => self::json(['message' => 'Bad credentials'], 401)]);
        try {
            $client->defaultBranch();
            $this->fail('Expected an exception');
        } catch (GitHubException $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('GET repos/o/r failed (HTTP 401): Bad credentials', $e->getMessage());
        }
    }

    /**
     * @param array<string, MockResponse|list<MockResponse>> $routes "METHOD /path?query" → response, or responses in order
     */
    private function client(array $routes, ?BufferedOutput $output = null): GitHubClient
    {
        $queues = array_map(static fn (MockResponse|array $r): array => \is_array($r) ? $r : [$r], $routes);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$queues): MockResponse {
            $this->assertContains('Authorization: Bearer secret', \is_array($options['headers'] ?? null) ? $options['headers'] : []);
            $key = $method.' '.substr($url, \strlen('https://api.github.com'));
            $this->requests[] = [$method, $url, $options['body'] ?? ''];
            $queue = $queues[$key] ?? throw new \LogicException('Unexpected request: '.$key);

            // The last response is repeated.
            return (\count($queue) > 1 ? array_shift($queues[$key]) : null) ?? $queue[0];
        });

        return new GitHubClient('secret', 'o/r', new Log($output ?? new BufferedOutput()), http: $http, mergeableRetryDelayMs: 0);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pr(int $number, string $branch, string $body, string $repo = 'o/r'): array
    {
        return [
            'number' => $number,
            'html_url' => 'https://github.com/o/r/pull/'.$number,
            'body' => $body,
            'head' => ['ref' => $branch, 'sha' => 'sha-'.$number, 'repo' => ['full_name' => $repo]],
            'base' => ['ref' => 'main'],
            'merged_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commit(string $email): array
    {
        return ['commit' => ['author' => ['email' => $email]]];
    }

    private static function json(mixed $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
