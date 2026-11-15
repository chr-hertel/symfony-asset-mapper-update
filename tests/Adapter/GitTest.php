<?php

namespace AssetMapperUpdate\Tests\Adapter;

use AssetMapperUpdate\Adapter\CommandResult;
use AssetMapperUpdate\Adapter\CommandRunner;
use AssetMapperUpdate\Adapter\Git;
use AssetMapperUpdate\Adapter\GitException;
use AssetMapperUpdate\Tests\GitRepositories;
use PHPUnit\Framework\TestCase;

final class GitTest extends TestCase
{
    use GitRepositories;

    public function testFetchDoesNotMakeAFullCloneShallow(): void
    {
        $git = $this->cloneWithCommits(3);
        $git->fetchBranch('main');

        $this->assertSame('false', $git->run(['rev-parse', '--is-shallow-repository']));
        $this->assertSame('3', $git->run(['rev-list', '--count', 'HEAD']));
    }

    public function testTheTokenReplacesStoredCredentialsOnlyForAuthenticatedCommands(): void
    {
        $git = $this->cloneWithCommits(1);
        $key = 'http.https://github.com/.extraheader';
        $git->run(['config', $key, 'AUTHORIZATION: basic stored']);
        $basic = $git->useToken('https://github.com/', 'secret');

        $this->assertSame(base64_encode('x-access-token:secret'), $basic);
        $this->assertSame("AUTHORIZATION: basic stored\n\nAUTHORIZATION: basic ".$basic, $git->run(['config', '--get-all', $key], auth: true));
        $this->assertSame('AUTHORIZATION: basic stored', $git->run(['config', '--get-all', $key]));
    }

    public function testTheTokenIsNotOnTheCommandLineAndKeepsExistingEnvironmentConfig(): void
    {
        $runner = new class implements CommandRunner {
            /** @var list<array{list<string>|string, array<string, string>}> */
            public array $calls = [];

            public function run(array|string $command, string $cwd, array $env = []): CommandResult
            {
                $this->calls[] = [$command, $env];

                return new CommandResult(0, '', '');
            }
        };
        $git = new Git('/repo', $runner);
        putenv('GIT_CONFIG_COUNT=1');
        try {
            $basic = $git->useToken('https://github.com', 'secret');
        } finally {
            putenv('GIT_CONFIG_COUNT');
        }
        $git->run(['push', 'origin', 'x'], auth: true);

        [$command, $env] = $runner->calls[0];
        $this->assertSame(['git', 'push', 'origin', 'x'], $command);
        $this->assertSame('7', $env['GIT_CONFIG_COUNT'], 'the existing entry is kept');
        $this->assertArrayNotHasKey('GIT_CONFIG_KEY_0', $env);
        $this->assertSame(['http.https://github.com/.extraheader', ''], [$env['GIT_CONFIG_KEY_1'], $env['GIT_CONFIG_VALUE_1']]);
        $this->assertSame(['http.https://github.com/.extraheader', 'AUTHORIZATION: basic '.$basic], [$env['GIT_CONFIG_KEY_2'], $env['GIT_CONFIG_VALUE_2']]);
    }

    public function testHooksDoNotRunWithTheCredentials(): void
    {
        $git = $this->cloneWithCommits(1);
        $log = $git->cwd.'/../hooks.log';
        $hooks = $git->cwd.'/../hooks';
        mkdir($hooks);
        foreach (['pre-push', 'reference-transaction', 'pre-auto-gc'] as $hook) {
            file_put_contents($hooks.'/'.$hook, "#!/bin/sh\necho \"$hook \$(env | grep -c AUTHORIZATION)\" >> ".escapeshellarg($log)."\ncat > /dev/null\nexit 0\n");
            chmod($hooks.'/'.$hook, 0o755);
            copy($hooks.'/'.$hook, $git->cwd.'/.git/hooks/'.$hook);
            chmod($git->cwd.'/.git/hooks/'.$hook, 0o755);
        }
        $git->run(['config', 'core.hooksPath', $hooks]); // as husky or CaptainHook may set it
        $this->commitFile($git, 'a.txt', 'update');
        $this->assertFileExists($log, 'the hooks work without the token');
        unlink($log);

        $git->useToken('https://github.com', 'secret');
        $git->pushWithLease('importmap-updates/patch', null);
        $git->fetchBranch('main');
        $git->remoteSha('main');

        $this->assertFileDoesNotExist($log);
    }

    public function testRemoteSha(): void
    {
        $git = $this->cloneWithCommits(1);
        $this->assertSame($git->head(), $git->remoteSha('main'));
        $this->assertNull($git->remoteSha('importmap-updates/patch'));
    }

    public function testPushWithLeaseCreatesAndReplacesOnlyTheExpectedCommit(): void
    {
        $git = $this->cloneWithCommits(1);
        $base = $git->head();
        $this->commitFile($git, 'a.txt', 'update 1');
        $git->pushWithLease('importmap-updates/patch', null);
        $first = $git->remoteSha('importmap-updates/patch');
        $this->assertSame($git->head(), $first);

        // A new branch must not exist yet.
        $this->commitFile($git, 'a.txt', 'update 1b');
        try {
            $git->pushWithLease('importmap-updates/patch', null);
            $this->fail('Expected the push to be rejected');
        } catch (GitException $e) {
            $this->assertStringContainsString('changed on the remote', $e->getMessage());
        }

        // Someone else pushed in the meantime: their commit is not overwritten.
        $other = $this->cloneOf($git);
        $other->run(['checkout', '--quiet', 'origin/importmap-updates/patch']);
        $this->commitFile($other, 'b.txt', 'human');
        $other->run(['push', '--quiet', 'origin', 'HEAD:refs/heads/importmap-updates/patch']);
        $human = $other->head();

        $git->checkoutDetached($base);
        $this->commitFile($git, 'a.txt', 'update 2');
        try {
            $git->pushWithLease('importmap-updates/patch', $first);
            $this->fail('Expected the push to be rejected');
        } catch (GitException) {
        }
        $this->assertSame($human, $git->remoteSha('importmap-updates/patch'));

        $git->pushWithLease('importmap-updates/patch', $human);
        $this->assertSame($git->head(), $git->remoteSha('importmap-updates/patch'));
    }
}
