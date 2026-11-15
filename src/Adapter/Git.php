<?php

namespace AssetMapperUpdate\Adapter;

final class Git
{
    public const BOT_NAME = 'github-actions[bot]';
    public const BOT_EMAIL = '41898282+github-actions[bot]@users.noreply.github.com';

    /** @var array<string, string> */
    private array $authEnv = [];

    public function __construct(
        public readonly string $cwd,
        private readonly CommandRunner $runner = new ProcessRunner(),
    ) {
    }

    /**
     * Uses the token for fetch and push. The config comes from `GIT_CONFIG_COUNT`/`_KEY_n`/`_VALUE_n`
     * (like `-c`, but not visible in the process list). It is applied after the repository config,
     * and an empty `http.<url>.extraheader` resets the list, so this replaces the credentials that
     * actions/checkout stored without touching the repository config. Entries that the
     * environment already defines are kept.
     *
     * Everything git starts inherits that environment, so these commands run no hooks (tools like
     * CaptainHook or GrumPHP install them with the app's dependencies), no credential helpers and
     * no automatic maintenance.
     *
     * @return string the encoded credentials, to mask them in the log
     */
    public function useToken(string $serverUrl, string $token): string
    {
        $basic = base64_encode('x-access-token:'.$token);
        $key = 'http.'.rtrim($serverUrl, '/').'/.extraheader';
        $config = [
            [$key, ''],
            [$key, 'AUTHORIZATION: basic '.$basic],
            ['core.hooksPath', '/dev/null'],
            ['credential.helper', ''],
            ['maintenance.auto', 'false'],
            ['gc.auto', '0'],
        ];
        $n = (int) getenv('GIT_CONFIG_COUNT');
        $this->authEnv = ['GIT_CONFIG_COUNT' => (string) ($n + \count($config)), 'GIT_TERMINAL_PROMPT' => '0'];
        foreach ($config as $i => [$name, $value]) {
            $this->authEnv['GIT_CONFIG_KEY_'.($n + $i)] = $name;
            $this->authEnv['GIT_CONFIG_VALUE_'.($n + $i)] = $value;
        }

        return $basic;
    }

    /**
     * @param list<string> $args
     */
    public function run(array $args, bool $auth = false, bool $allowFailure = false): string
    {
        $result = $this->runner->run(['git', ...$args], $this->cwd, $auth ? $this->authEnv : []);
        if (0 !== $result->exitCode) {
            if ($allowFailure) {
                return '';
            }
            throw new GitException(\sprintf("git %s failed:\n%s", implode(' ', $args), $result->tail()));
        }

        return trim($result->stdout);
    }

    public function toplevel(): string
    {
        return $this->run(['rev-parse', '--show-toplevel']);
    }

    public function head(): string
    {
        return $this->run(['rev-parse', 'HEAD']);
    }

    /**
     * The current branch, or the commit if HEAD is detached.
     */
    public function currentRef(): string
    {
        $branch = $this->run(['symbolic-ref', '--quiet', '--short', 'HEAD'], allowFailure: true);

        return '' !== $branch ? $branch : $this->head();
    }

    public function hasUncommittedChanges(): bool
    {
        return '' !== $this->run(['status', '--porcelain', '--untracked-files=no']);
    }

    /**
     * Fetches the branch without --depth: a depth would make a full clone shallow, or cut down a
     * deeper shallow clone. A plain fetch keeps both as they are.
     */
    public function fetchBranch(string $branch): void
    {
        $this->run(['fetch', '--no-tags', 'origin', \sprintf('+refs/heads/%1$s:refs/remotes/origin/%1$s', $branch)], auth: true);
    }

    /**
     * The commit a branch points to on the remote, or null if it does not exist.
     */
    public function remoteSha(string $branch): ?string
    {
        $out = $this->run(['ls-remote', 'origin', 'refs/heads/'.$branch], auth: true);

        return '' === $out ? null : explode("\t", $out)[0];
    }

    public function revParse(string $ref): string
    {
        return $this->run(['rev-parse', $ref]);
    }

    public function checkoutDetached(string $ref): void
    {
        $this->run(['checkout', '--quiet', '--force', '--detach', $ref]);
    }

    public function checkout(string $ref): void
    {
        $this->run(['checkout', '--quiet', '--force', $ref]);
    }

    /**
     * Tracked files with changes in the working tree, relative to the repository root.
     *
     * @return list<string>
     */
    public function modifiedFiles(): array
    {
        $out = $this->run(['diff', '--name-only', 'HEAD']);

        return '' === $out ? [] : explode("\n", $out);
    }

    /**
     * Reverts tracked files to HEAD, e.g. after `importmap:require` wrote to them.
     *
     * @param list<string> $files
     */
    public function restore(array $files): void
    {
        if ([] !== $files) {
            $this->run(['checkout', 'HEAD', '--', ...$files]);
        }
    }

    public function commit(string $file, string $message): void
    {
        $this->run(['add', '--', $file]);
        $this->run(['-c', 'user.name='.self::BOT_NAME, '-c', 'user.email='.self::BOT_EMAIL, '-c', 'commit.gpgsign=false', 'commit', '--quiet', '--no-verify', '-m', $message]);
    }

    /**
     * Pushes HEAD to the branch, but only if the branch is still at $expectedSha (null: the branch
     * must not exist). Someone who pushed in the meantime is never overwritten.
     */
    public function pushWithLease(string $branch, ?string $expectedSha): void
    {
        $ref = 'refs/heads/'.$branch;
        try {
            $this->run(['push', '--force-with-lease='.$ref.':'.($expectedSha ?? ''), 'origin', 'HEAD:'.$ref], auth: true);
        } catch (GitException $e) {
            if (str_contains($e->getMessage(), 'stale info')) {
                throw new GitException(\sprintf('%s changed on the remote while the update was prepared; not overwriting it. The next run tries again.', $branch), 0, $e);
            }
            throw $e;
        }
    }
}
