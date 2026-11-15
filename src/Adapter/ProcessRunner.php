<?php

namespace AssetMapperUpdate\Adapter;

use Symfony\Component\Process\Process;

final class ProcessRunner implements CommandRunner
{
    /**
     * Child processes (the app's console with its bundles and dependencies, git) do not inherit the
     * token. Git gets the credentials through `$env` for the commands that need them (see Git::useToken()).
     */
    public const STRIP_ENV = ['GITHUB_TOKEN' => false];

    public function __construct(
        private readonly float $timeout = 600,
    ) {
    }

    public function run(array|string $command, string $cwd, array $env = []): CommandResult
    {
        $env = [...self::STRIP_ENV, ...$env];
        $process = \is_string($command)
            ? Process::fromShellCommandline($command, $cwd, $env, null, $this->timeout)
            : new Process($command, $cwd, $env, null, $this->timeout);
        $process->run();

        return new CommandResult($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }
}
