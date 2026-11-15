<?php

namespace AssetMapperUpdate\Command;

use AssetMapperUpdate\Adapter\ProcessRunner;
use AssetMapperUpdate\Inputs;
use AssetMapperUpdate\Log;
use AssetMapperUpdate\Runner;
use AssetMapperUpdate\RunResult;
use AssetMapperUpdate\Summary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'update', description: 'Opens and maintains pull requests for outdated and vulnerable importmap.php packages')]
final class UpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('console', null, InputOption::VALUE_REQUIRED, 'Command to call the Symfony console of the app', 'php bin/console')
            ->addOption('working-directory', 'd', InputOption::VALUE_REQUIRED, 'Directory of the Symfony app (the one with importmap.php)', '.')
            ->addOption('config-file', 'c', InputOption::VALUE_REQUIRED, 'Config file, relative to the repository root', '.github/importmap-updates.yml')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan, apply and verify the updates locally, but do not push, create, update or close anything')
            ->addOption('base-branch', 'b', InputOption::VALUE_REQUIRED, 'Branch the pull requests target (default: the default branch of the repository)')
            ->addOption('repository', 'r', InputOption::VALUE_REQUIRED, 'GitHub repository as owner/repo (default: $GITHUB_REPOSITORY, or the "origin" remote)')
            ->setHelp(<<<'HELP'
                The command reads <info>importmap.php</info>, runs <info>importmap:outdated</info> and <info>importmap:audit</info> in the app,
                plans the updates and creates, updates or closes pull requests on GitHub to match the plan.

                The GitHub token is read from the <info>GITHUB_TOKEN</info> environment variable:

                  <info>GITHUB_TOKEN=$(gh auth token) %command.full_name% --dry-run</info>

                On GitHub Actions, it also writes the <info>plan</info> and <info>pull-requests</info> outputs and the job summary.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $githubActions = 'true' === getenv('GITHUB_ACTIONS');
        $log = new Log($output, $githubActions);
        $dryRun = $input->getOption('dry-run');

        try {
            $token = (string) getenv('GITHUB_TOKEN');
            if ('' === $token) {
                throw new \RuntimeException('Set the GITHUB_TOKEN environment variable to a token with "contents: write" and "pull-requests: write", e.g. GITHUB_TOKEN=$(gh auth token).');
            }
            $log->mask($token);
            $workspace = (string) (getenv('GITHUB_WORKSPACE') ?: getcwd());
            $workingDirectory = self::option($input, 'working-directory');

            $result = (new Runner($log))->run(new Inputs(
                token: $token,
                repository: $this->repository($input, $workspace, $workingDirectory),
                console: self::option($input, 'console'),
                workingDirectory: $workingDirectory,
                configFile: self::option($input, 'config-file'),
                dryRun: $dryRun,
                baseBranch: self::option($input, 'base-branch'),
                serverUrl: (string) (getenv('GITHUB_SERVER_URL') ?: 'https://github.com'),
                apiUrl: (string) (getenv('GITHUB_API_URL') ?: 'https://api.github.com'),
                workspace: $workspace,
            ));
        } catch (\Throwable $e) {
            $log->error($e->getMessage());
            $log->debug($e->getTraceAsString());
            self::appendFile('GITHUB_STEP_SUMMARY', "## Importmap updates\n\n❌ ".$e->getMessage()."\n");

            return Command::FAILURE;
        }

        $this->report($result, $dryRun, $output);

        if ([] !== $result->errors) {
            $log->error(\sprintf("%d pull request(s) could not be processed:\n%s", \count($result->errors), implode("\n", $result->errors)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function report(RunResult $result, bool $dryRun, OutputInterface $output): void
    {
        $json = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;
        $plan = json_encode($result->planOutput(), $json);
        $pullRequests = json_encode($result->pullRequestsOutput(), $json);
        $summary = Summary::render($result, $dryRun);

        if (false !== getenv('GITHUB_OUTPUT')) {
            $delimiter = 'EOF_'.bin2hex(random_bytes(8));
            self::appendFile('GITHUB_OUTPUT', \sprintf("plan<<%1\$s\n%2\$s\n%1\$s\npull-requests<<%1\$s\n%3\$s\n%1\$s\n", $delimiter, $plan, $pullRequests));
        }
        if (false !== getenv('GITHUB_STEP_SUMMARY')) {
            self::appendFile('GITHUB_STEP_SUMMARY', $summary);
        } else {
            $output->writeln(['', $summary], OutputInterface::OUTPUT_RAW);
        }
    }

    private function repository(InputInterface $input, string $workspace, string $workingDirectory): string
    {
        $repository = $input->getOption('repository') ?? getenv('GITHUB_REPOSITORY');
        if (!\is_string($repository) || '' === $repository) {
            $process = new Process(['git', 'remote', 'get-url', 'origin'], Path::makeAbsolute($workingDirectory, Path::makeAbsolute($workspace, (string) getcwd())), ProcessRunner::STRIP_ENV);
            $process->run();
            if (1 === preg_match('#github\.com[:/]([\w.-]+/[\w.-]+?)(?:\.git)?/?$#', trim($process->getOutput()), $m)) {
                $repository = $m[1];
            }
        }
        if (!\is_string($repository) || 1 !== preg_match('#^[\w.-]+/[\w.-]+$#', $repository)) {
            throw new \RuntimeException('Pass the GitHub repository with --repository=owner/repo.');
        }

        return $repository;
    }

    private static function option(InputInterface $input, string $name): string
    {
        return (string) $input->getOption($name);
    }

    private static function appendFile(string $env, string $content): void
    {
        $file = getenv($env);
        if (\is_string($file) && '' !== $file) {
            file_put_contents($file, $content, \FILE_APPEND);
        }
    }
}
