<?php

namespace AssetMapperUpdate;

use AssetMapperUpdate\Adapter\CommandRunner;
use AssetMapperUpdate\Adapter\ConsoleException;
use AssetMapperUpdate\Adapter\Git;
use AssetMapperUpdate\Adapter\GitHubClient;
use AssetMapperUpdate\Adapter\NpmRegistry;
use AssetMapperUpdate\Adapter\SymfonyConsole;
use AssetMapperUpdate\Config\Config;
use AssetMapperUpdate\Config\ConfigParser;
use AssetMapperUpdate\Importmap\ImportmapParser;
use AssetMapperUpdate\Importmap\Packages;
use AssetMapperUpdate\Model\AuditedPackage;
use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\Plan;
use AssetMapperUpdate\Model\RegistryPackage;
use AssetMapperUpdate\Model\RemotePackage;
use AssetMapperUpdate\PullRequest\Content;
use AssetMapperUpdate\PullRequest\PullRequestState;
use AssetMapperUpdate\Reconciler\Action;
use AssetMapperUpdate\Reconciler\ActionType;
use AssetMapperUpdate\Reconciler\Reconciler;
use Symfony\Component\Filesystem\Path;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Runner
{
    private const DRY = '[dry-run] ';
    private const AUDIT_FAILED = 'the security audit failed';

    private Inputs $inputs;
    private Config $config;
    private Git $git;
    private GitHubClient $github;
    private SymfonyConsole $console;
    private string $base;
    private string $baseRef;
    private string $importmapPath;
    private string $importmapRelative;

    /**
     * @param HttpClientInterface|null $http          for the GitHub API and the npm registry (tests)
     * @param CommandRunner|null       $consoleRunner runs the Symfony console of the app (tests)
     */
    public function __construct(
        private readonly Log $log,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?CommandRunner $consoleRunner = null,
    ) {
    }

    public function run(Inputs $inputs): RunResult
    {
        $this->inputs = $inputs;
        $workDir = Path::makeAbsolute($inputs->workingDirectory, Path::makeAbsolute($inputs->workspace, (string) getcwd()));
        if (!is_dir($workDir)) {
            throw new \RuntimeException(\sprintf('The working directory "%s" does not exist.', $workDir));
        }
        $root = (new Git($workDir))->toplevel();
        $this->git = new Git($root);
        $this->log->mask($this->git->useToken($inputs->serverUrl, $inputs->token));
        $this->github = new GitHubClient($inputs->token, $inputs->repository, $this->log, $inputs->apiUrl, $this->http);
        $this->console = null !== $this->consoleRunner ? new SymfonyConsole($inputs->console, $workDir, $this->consoleRunner) : new SymfonyConsole($inputs->console, $workDir);
        $this->importmapPath = Path::join($workDir, 'importmap.php');
        $this->importmapRelative = Path::makeRelative($this->importmapPath, $root);

        if ($this->git->hasUncommittedChanges()) {
            throw new \RuntimeException(self::uncommittedChangesMessage($this->git->modifiedFiles(), Path::makeRelative(Path::join($workDir, 'config/reference.php'), $root)));
        }

        $this->base = '' !== $inputs->baseBranch ? $inputs->baseBranch : $this->github->defaultBranch();
        $this->baseRef = 'refs/remotes/origin/'.$this->base;
        $originalRef = $this->git->currentRef();

        $this->git->fetchBranch($this->base);
        $baseSha = $this->git->revParse($this->baseRef);
        if ($this->git->head() !== $baseSha) {
            $this->log->info(\sprintf('Checking out %s (%s).', $this->base, substr($baseSha, 0, 7)));
        }
        $this->git->checkoutDetached($this->baseRef);

        try {
            $configPath = Path::makeAbsolute($inputs->configFile, $root);
            if (is_file($configPath)) {
                $this->config = ConfigParser::parseFile($configPath, $inputs->configFile);
            } else {
                $this->log->info(\sprintf('No config file at %s, using the defaults.', $inputs->configFile));
                $this->config = new Config();
            }
            if (!is_file($this->importmapPath)) {
                throw new \RuntimeException(\sprintf('No importmap.php found in %s.', $workDir));
            }

            // 1. Current state
            $this->log->group('Reading the importmap state');
            $contents = file_get_contents($this->importmapPath);
            $packages = Packages::remote(array_map(static fn ($e) => $e->entry, ImportmapParser::parse(false !== $contents ? $contents : '')->entries));
            $outdated = $this->console->outdated();
            $open = $this->github->openPullRequests($this->config->branchPrefix, $this->base);
            $unknown = [];
            try {
                $audit = $this->withoutWithdrawnAdvisories($this->console->audit());
            } catch (ConsoleException $e) {
                // E.g. the unauthenticated GitHub API rate limit of the runner's IP. The other
                // updates still run; security PRs are kept until an audit succeeds again.
                $this->log->warning('The security audit failed, so this run makes no security updates: '.$e->getMessage());
                $audit = [];
                foreach ($open as $pr) {
                    if (Bucket::Security->value === $pr->state->bucket) {
                        $unknown += array_fill_keys(array_keys($pr->state->updatesByName()), self::AUDIT_FAILED);
                    }
                }
            }
            $vulnerable = \count(array_filter($audit, static fn (AuditedPackage $a): bool => [] !== $a->advisories));
            $this->log->info(\sprintf('%d remote packages, %d outdated entries, %d vulnerable packages.', \count($packages), \count($outdated), $vulnerable));
            $this->log->endGroup();

            // 2. Registry data and plan
            $registry = new NpmRegistry($this->http);
            $registryData = [];
            foreach ($registry->packages(Planner::packagesToLookUp($packages, $outdated, $audit)) as $name => $result) {
                if ($result instanceof RegistryPackage) {
                    $registryData[$name] = $result;
                } else {
                    $this->log->warning(\sprintf('Could not load %s from the npm registry: %s', $name, $result->getMessage()));
                }
            }
            $plan = Planner::plan($packages, $outdated, $audit, $registryData, $this->config, $unknown);
            $links = $registry->links(array_map(static fn ($u): array => [$u->name, $u->to], $plan->updates()));
            foreach ($plan->updates() as $update) {
                $update->links = $links[$update->name.'@'.$update->to] ?? null;
            }
            $this->logPlan($plan);

            // 3. Reconcile with the existing pull requests
            $openBranches = array_map(static fn ($pr): string => $pr->branch, $open);
            $withoutOpenPullRequest = array_values(array_filter(
                array_map(static fn ($pr): string => $pr->branch, $plan->pullRequests),
                static fn (string $branch): bool => !\in_array($branch, $openBranches, true),
            ));
            $actions = Reconciler::reconcile(
                $plan,
                $open,
                $this->github->closedPullRequests($this->config->branchPrefix, $this->base, $withoutOpenPullRequest),
                array_combine(array_map(static fn (RemotePackage $p): string => $p->name, $packages), array_map(static fn (RemotePackage $p): string => $p->version, $packages)),
                $this->config->openPullRequestsLimit,
            );

            // 4. Execute
            $results = [];
            $errors = [];
            foreach ($actions as $action) {
                try {
                    $results[] = $this->execute($action);
                } catch (\Throwable $e) {
                    $errors[] = $action->branch().': '.$e->getMessage();
                    $this->log->error($action->branch().': '.$e->getMessage());
                    $results[] = new PullRequestResult('failed', $action->branch(), $e->getMessage(), $inputs->dryRun);
                    try {
                        $this->git->checkoutDetached($this->baseRef);
                    } catch (\Throwable) {
                    }
                }
            }

            return new RunResult($this->base, $plan, $results, $errors);
        } finally {
            try {
                $this->git->checkout($originalRef);
            } catch (\Throwable $e) {
                $this->log->warning(\sprintf('Could not restore %s: %s', $originalRef, $e->getMessage()));
            }
        }
    }

    /**
     * @param list<AuditedPackage> $audit
     *
     * @return list<AuditedPackage>
     */
    private function withoutWithdrawnAdvisories(array $audit): array
    {
        $summaries = [];
        foreach ($audit as $package) {
            foreach ($package->advisories as $advisory) {
                $summaries[$advisory->ghsaId] = $advisory->summary;
            }
        }
        $withdrawn = [];
        foreach ($summaries as $id => $summary) {
            if ($this->github->isWithdrawn($id, $summary)) {
                $withdrawn[] = $id;
            }
        }
        if ([] !== $withdrawn) {
            $this->log->info('Ignoring withdrawn advisories: '.implode(', ', $withdrawn));
        }

        return array_map(
            static fn (AuditedPackage $p): AuditedPackage => $p->withAdvisories(array_values(array_filter($p->advisories, static fn ($a): bool => !\in_array($a->ghsaId, $withdrawn, true)))),
            $audit,
        );
    }

    private function execute(Action $action): PullRequestResult
    {
        $dryRun = $this->inputs->dryRun;
        $prefix = $dryRun ? self::DRY : '';

        switch ($action->type) {
            case ActionType::Create:
            case ActionType::Update:
                $pr = $action->pr ?? throw new \LogicException('Missing pull request.');
                $create = ActionType::Create === $action->type;
                $title = Content::title($pr);
                $this->log->group(\sprintf('%s%s %s: %s', $prefix, $create ? 'Creating' : 'Updating', $pr->branch, $title));
                try {
                    // The push only replaces the commit that was checked for foreign commits.
                    $expectedSha = $action->existing?->headSha;
                    if ($create && !$dryRun) {
                        $expectedSha = $this->git->remoteSha($pr->branch);
                        if (null !== $expectedSha && $this->github->branchHasForeignCommits($pr->branch, $this->base)) {
                            throw new \RuntimeException(\sprintf('branch %s already exists with commits the action did not create; not overwriting it', $pr->branch));
                        }
                    }
                    // Built on a detached HEAD: no local branch is created or reset.
                    $this->git->checkoutDetached($this->baseRef);
                    $details = (new Applier($this->console, $this->git))->apply($pr, $this->importmapPath);
                    foreach ($details->ignoredSideEffects as $sideEffect) {
                        $this->log->info('Not applying a side effect of importmap:require: '.$sideEffect);
                    }
                    $this->git->commit($this->importmapRelative, Content::commitMessage($pr, $this->config->commitMessagePrefix));
                    $body = Content::body($pr, $details, $this->inputs->console);
                    $detail = $create ? 'new pull request' : (string) $action->reason;

                    if ($dryRun) {
                        $this->log->info(\sprintf('%swould push %s and %s the pull request "%s"', self::DRY, $pr->branch, $create ? 'create' : 'update', $title));
                        $this->git->checkoutDetached($this->baseRef);

                        return new PullRequestResult($create ? 'created' : 'updated', $pr->branch, $detail, true, $action->existing?->number, $action->existing?->url, $title);
                    }

                    if (!$create && null === $expectedSha) {
                        throw new \RuntimeException('GitHub did not return the head commit of the pull request; not pushing without it');
                    }
                    $this->git->pushWithLease($pr->branch, $expectedSha);
                    $this->git->checkoutDetached($this->baseRef);
                    if ($create) {
                        $labels = array_values(array_unique([...$this->config->labels, ...(Bucket::Security === $pr->bucket ? ['security'] : [])]));
                        $created = $this->github->createPullRequest($pr->branch, $this->base, $title, $body, $labels, $this->config->reviewers, $this->config->assignees);
                        $this->log->info(\sprintf('Created #%d: %s', $created['number'], $created['url']));

                        return new PullRequestResult('created', $pr->branch, $detail, false, $created['number'], $created['url'], $title);
                    }
                    $existing = $action->existing ?? throw new \LogicException('Missing existing pull request.');
                    $this->github->updatePullRequest($existing->number, $title, $body);
                    $this->log->info(\sprintf('Updated #%d (%s)', $existing->number, $detail));

                    return new PullRequestResult('updated', $pr->branch, $detail, false, $existing->number, $existing->url, $title);
                } finally {
                    $this->log->endGroup();
                }

            case ActionType::Keep:
                $existing = $action->existing ?? throw new \LogicException('Missing existing pull request.');
                if (null !== $action->reason) {
                    $this->log->warning(\sprintf('#%d %s', $existing->number, $action->reason));
                } else {
                    $this->log->info(\sprintf('%s: #%d is up to date.', $existing->branch, $existing->number));
                }

                return new PullRequestResult('unchanged', $existing->branch, $action->reason ?? 'already up to date', $dryRun, $existing->number, $existing->url, null !== $action->pr ? Content::title($action->pr) : null);

            case ActionType::Close:
                $existing = $action->existing ?? throw new \LogicException('Missing existing pull request.');
                $reason = (string) $action->reason;
                if ($dryRun) {
                    $this->log->info(\sprintf('%swould close #%d and delete %s: %s', self::DRY, $existing->number, $existing->branch, $reason));
                } else {
                    $comment = \sprintf("Closing this pull request because it is no longer needed: %s.\n\n<sub>asset-mapper-update</sub>", $reason);
                    $this->github->closePullRequest($existing->number, $existing->branch, $existing->state, $comment);
                    $this->log->info(\sprintf('Closed #%d: %s', $existing->number, $reason));
                }

                return new PullRequestResult('closed', $existing->branch, $reason, $dryRun, $existing->number, $existing->url);

            case ActionType::Blocked:
                $existing = $action->existing ?? throw new \LogicException('Missing existing pull request.');
                $detail = 'has commits that were not made by the action; wanted to '.$action->wanted;
                if ($action->notify) {
                    $comment = \sprintf("This branch has commits that were not created by asset-mapper-update, so the action does not force-push or close it.\n\nIt wanted to %s.\n\nTo let the action manage this pull request again, reset the branch to the action's commit (or close the pull request if you no longer need it).\n\n%s", $action->wanted, PullRequestState::MANUAL_COMMITS_MARKER);
                    if ($dryRun) {
                        $this->log->info(\sprintf('%swould comment on #%d: %s', self::DRY, $existing->number, $detail));
                    } else {
                        $this->github->comment($existing->number, $comment);
                    }
                }
                $this->log->warning(\sprintf('#%d %s', $existing->number, $detail));

                return new PullRequestResult('blocked', $existing->branch, $detail, $dryRun, $existing->number, $existing->url);

            case ActionType::Defer:
                $pr = $action->pr ?? throw new \LogicException('Missing pull request.');
                $detail = \sprintf('open_pull_requests_limit (%d) reached', $this->config->openPullRequestsLimit);
                if (null !== $action->existing) {
                    $detail .= \sprintf('; #%d left as it is', $action->existing->number);
                }
                $this->log->info(\sprintf('%s: not proposed yet, %s.', $pr->branch, $detail));

                return new PullRequestResult('deferred', $pr->branch, $detail, $dryRun, $action->existing?->number, $action->existing?->url, Content::title($pr));

            case ActionType::SkipClosed:
                $pr = $action->pr ?? throw new \LogicException('Missing pull request.');
                $detail = \sprintf('the same update was closed without merging in #%d', (int) $action->closedNumber);
                $this->log->info(\sprintf('%s: not proposed again, %s.', $pr->branch, $detail));

                return new PullRequestResult('skipped', $pr->branch, $detail, $dryRun, title: Content::title($pr));
        }
    }

    /**
     * @param list<string> $files
     */
    private static function uncommittedChangesMessage(array $files, string $configReference): string
    {
        $message = \sprintf('The repository has uncommitted changes to tracked files: %s. The command checks out the base branch with --force, which would discard them, so commit or stash them first (on GitHub Actions: do not modify tracked files in the steps before this one).', implode(', ', $files));
        if (\in_array($configReference, $files, true)) {
            // Symfony 7.4+ rewrites it whenever the container is built in debug mode, e.g. by
            // the cache:clear of Composer's auto-scripts after Dependabot updated a bundle.
            $message .= \sprintf(' Symfony regenerates %s when the container is built in debug mode, usually by the auto-scripts of "composer install": run it with --no-scripts before this action, or commit the regenerated file.', $configReference);
        }

        return $message;
    }

    private function logPlan(Plan $plan): void
    {
        $this->log->group(\sprintf('Plan: %d pull request(s)', \count($plan->pullRequests)));
        foreach ($plan->pullRequests as $pr) {
            $this->log->info(\sprintf('%s (%s, %s)', $pr->branch, $pr->bucket->value, $pr->strategy->value));
            foreach ($pr->updates as $u) {
                $this->log->info(\sprintf('  %s %s → %s [%s] %s', $u->name, $u->from, $u->to, $u->updateType->value, $u->reason));
            }
        }
        foreach ($plan->skipped as $s) {
            $this->log->info(\sprintf('skipped %s %s: %s', $s->name, $s->current, $s->reason));
        }
        $this->log->endGroup();
    }
}
