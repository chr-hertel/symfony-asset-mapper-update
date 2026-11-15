<?php

namespace AssetMapperUpdate\Tests;

use AssetMapperUpdate\Adapter\CommandResult;
use AssetMapperUpdate\Adapter\CommandRunner;
use AssetMapperUpdate\Adapter\Git;
use AssetMapperUpdate\Inputs;
use AssetMapperUpdate\Log;
use AssetMapperUpdate\PullRequest\PullRequestState;
use AssetMapperUpdate\Runner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The whole run against a real repository with a local bare origin. The console and the HTTP APIs
 * (GitHub, npm registry) are faked.
 */
final class RunnerTest extends TestCase
{
    use GitRepositories;

    private const BRANCH = 'importmap-updates/major/bootstrap';

    private Git $git;
    /** @var list<string> */
    private array $requests = [];
    /** @var array<string, string> */
    private array $bodies = [];
    /** @var list<array<string, mixed>> */
    private array $openPullRequests = [];
    /** @var list<array<string, mixed>> */
    private array $closedPullRequests = [];
    private BufferedOutput $output;
    private bool $registryDown = false;
    public bool $auditDown = false;

    protected function setUp(): void
    {
        $this->git = $this->cloneWithCommits(1, ['importmap.php' => Factory::fixture('importmap-compact.php')]);
    }

    public function testCreatesThePullRequestWithoutLocalBranches(): void
    {
        $result = $this->runAction();

        $this->assertSame([], $result->errors);
        $this->assertSame(['created'], array_map(static fn ($r): string => $r->action, $result->results));
        $this->assertContains('POST /repos/o/r/pulls', $this->requests);
        $this->assertNotNull($this->git->remoteSha(self::BRANCH));
        $diff = $this->git->run(['diff', '--unified=0', 'origin/main', 'origin/'.self::BRANCH, '--', 'importmap.php']);
        $this->assertStringContainsString("+    'bootstrap' => ['version' => '5.3.9'],", $diff);
        $this->assertSame('', $this->git->run(['branch', '--list', 'importmap-updates/*']), 'no local branch is left behind');
        $this->assertFalse($this->git->hasUncommittedChanges());
    }

    public function testDryRunPushesNothingAndLeavesNoBranches(): void
    {
        $result = $this->runAction(dryRun: true);

        $this->assertSame([], $result->errors);
        $this->assertSame(['created'], array_map(static fn ($r): string => $r->action, $result->results));
        $this->assertTrue($result->results[0]->dryRun);
        $this->assertNull($this->git->remoteSha(self::BRANCH));
        $this->assertSame([], $this->writeRequests());
        $this->assertSame('', $this->git->run(['branch', '--list', 'importmap-updates/*']));
    }

    public function testKeepsThePullRequestWhenTheRegistryFails(): void
    {
        $this->runAction();
        $sha = $this->git->remoteSha(self::BRANCH);
        $this->openPullRequests = [[
            'number' => 1,
            'html_url' => 'https://github.com/o/r/pull/1',
            'body' => $this->bodies['POST /repos/o/r/pulls'],
            'head' => ['ref' => self::BRANCH, 'sha' => $sha, 'repo' => ['full_name' => 'o/r']],
            'base' => ['ref' => 'main'],
            'merged_at' => null,
        ]];
        $this->requests = [];
        $this->registryDown = true;

        $result = $this->runAction();

        $this->assertSame([], $result->errors);
        $this->assertSame([['unchanged', 'kept as it is: bootstrap: could not load versions from the npm registry']], array_map(static fn ($r): array => [$r->action, $r->detail], $result->results));
        $this->assertSame([], $this->writeRequests(), 'not closed, not updated');
        $this->assertSame($sha, $this->git->remoteSha(self::BRANCH));
    }

    public function testDoesNotProposeAnUpdateAgainThatWasClosedWithoutMerging(): void
    {
        $this->runAction();
        $this->closedPullRequests = [[
            'number' => 1,
            'html_url' => 'https://github.com/o/r/pull/1',
            'body' => $this->bodies['POST /repos/o/r/pulls'],
            'head' => ['ref' => self::BRANCH, 'sha' => $this->git->remoteSha(self::BRANCH), 'repo' => ['full_name' => 'o/r']],
            'base' => ['ref' => 'main'],
            'merged_at' => null,
        ]];
        $this->requests = [];

        $result = $this->runAction();

        $this->assertSame([['skipped', 'the same update was closed without merging in #1']], array_map(static fn ($r): array => [$r->action, $r->detail], $result->results));
        $this->assertSame([], $this->writeRequests());
        $this->assertStringContainsString(self::BRANCH.': not proposed again, the same update was closed without merging in #1.', $this->output->fetch());
    }

    public function testKeepsSecurityPullRequestsWhenTheAuditFails(): void
    {
        $branch = 'importmap-updates/security/bootstrap';
        $state = new PullRequestState('security', [['name' => 'bootstrap', 'from' => '4.6.0', 'to' => '5.3.9']]);
        $this->openPullRequests = [[
            'number' => 1,
            'html_url' => 'https://github.com/o/r/pull/1',
            'body' => $state->render(),
            'head' => ['ref' => $branch, 'sha' => str_repeat('a', 40), 'repo' => ['full_name' => 'o/r']],
            'base' => ['ref' => 'main'],
            'merged_at' => null,
        ]];
        $this->auditDown = true;

        $result = $this->runAction();

        $this->assertSame([], $result->errors);
        $this->assertSame([['unchanged', 'kept as it is: bootstrap: the security audit failed']], array_map(static fn ($r): array => [$r->action, $r->detail], $result->results));
        $this->assertSame([], $this->writeRequests(), 'bootstrap is not proposed elsewhere, the PR is not closed');
        $this->assertStringContainsString('The security audit failed, so this run makes no security updates', $this->output->fetch());
    }

    public function testRefusesToRunWithUncommittedChangesEvenOnGitHubActions(): void
    {
        file_put_contents($this->git->cwd.'/importmap.php', "// local change\n", \FILE_APPEND);
        putenv('GITHUB_ACTIONS=true');
        try {
            $this->runAction();
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('uncommitted changes to tracked files: importmap.php.', $e->getMessage());
            $this->assertStringNotContainsString('--no-scripts', $e->getMessage());
        } finally {
            putenv('GITHUB_ACTIONS');
        }
        $this->assertStringContainsString('// local change', (string) file_get_contents($this->git->cwd.'/importmap.php'));
    }

    public function testExplainsAConfigReferenceRegeneratedBySymfony(): void
    {
        mkdir($this->git->cwd.'/config');
        $this->commitFile($this->git, 'config/reference.php', '<?php');
        file_put_contents($this->git->cwd.'/config/reference.php', "<?php\n// regenerated\n");

        try {
            $this->runAction();
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('uncommitted changes to tracked files: config/reference.php.', $e->getMessage());
            $this->assertStringContainsString('Symfony regenerates config/reference.php when the container is built in debug mode', $e->getMessage());
            $this->assertStringContainsString('--no-scripts', $e->getMessage());
        }
    }

    private function runAction(bool $dryRun = false): \AssetMapperUpdate\RunResult
    {
        $inputs = new Inputs('secret', 'o/r', workingDirectory: $this->git->cwd, configFile: '.github/importmap-updates.yml', dryRun: $dryRun, baseBranch: 'main');

        $this->output = new BufferedOutput();

        return (new Runner(new Log($this->output), $this->http(), $this->console()))->run($inputs);
    }

    /**
     * @return list<string>
     */
    private function writeRequests(): array
    {
        return array_values(array_filter($this->requests, static fn (string $r): bool => !str_starts_with($r, 'GET ')));
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $parts = parse_url($url);
            $path = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.urldecode($parts['query']) : '');
            if ('registry.npmjs.org' === ($parts['host'] ?? null)) {
                if ($this->registryDown) {
                    return new MockResponse('', ['http_code' => 503]);
                }

                return match ($path) {
                    '/bootstrap' => self::json(['dist-tags' => ['latest' => '5.3.9'], 'versions' => ['4.6.0' => [], '5.3.9' => []]]),
                    '/bootstrap/5.3.9' => self::json(['repository' => ['url' => 'git+https://github.com/twbs/bootstrap.git']]),
                    default => new MockResponse('', ['http_code' => 404]),
                };
            }

            $key = $method.' '.$path;
            $this->requests[] = $key;
            $this->bodies[$key] = json_decode($options['body'] ?? '', true)['body'] ?? '';

            return match ($key) {
                'GET /repos/o/r/pulls?state=open&base=main&per_page=100' => self::json($this->openPullRequests),
                'GET /repos/o/r/pulls?state=closed&base=main&head=o:'.self::BRANCH.'&per_page=100' => self::json($this->closedPullRequests),
                'GET /repos/o/r/pulls/1/commits?per_page=100' => self::json([['commit' => ['author' => ['email' => Git::BOT_EMAIL]], 'parents' => [[]]]]),
                'GET /repos/o/r/pulls/1' => self::json(['mergeable' => true]),
                'POST /repos/o/r/pulls' => self::json(['number' => 1, 'html_url' => 'https://github.com/o/r/pull/1', 'head' => ['ref' => self::BRANCH], 'base' => ['ref' => 'main']], 201),
                'POST /repos/o/r/issues/1/labels' => self::json([]),
                default => throw new \LogicException('Unexpected request: '.$key),
            };
        });
    }

    /**
     * Only bootstrap is outdated; `importmap:require` writes what the real command wrote in the spike.
     */
    private function console(): CommandRunner
    {
        return new class($this->git->cwd, $this) implements CommandRunner {
            public function __construct(private string $dir, private RunnerTest $test)
            {
            }

            public function run(array|string $command, string $cwd, array $env = []): CommandResult
            {
                \assert(\is_string($command));

                return match (true) {
                    str_contains($command, 'importmap:outdated') => new CommandResult(1, '[{"name":"bootstrap","current":"4.6.0","latest":"5.3.9","latest-status":"update-possible"}]', ''),
                    str_contains($command, 'importmap:audit') && $this->test->auditDown => new CommandResult(1, '', 'Error 403 auditing packages. Response: {"message":"API rate limit exceeded"}'),
                    str_contains($command, 'importmap:audit') => new CommandResult(0, '{"packages":[],"summary":{}}', ''),
                    str_contains($command, 'importmap:require') => $this->require(),
                    default => throw new \LogicException('Unexpected command: '.$command),
                };
            }

            private function require(): CommandResult
            {
                file_put_contents($this->dir.'/importmap.php', Factory::fixture('importmap-compact-after-update-bootstrap.php'));

                return new CommandResult(0, '', '');
            }
        };
    }

    private static function json(mixed $data, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($data, \JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
