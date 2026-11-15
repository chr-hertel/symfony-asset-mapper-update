# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

## What this is

A GitHub composite action (`action.yml`) that keeps the `importmap.php` of a Symfony AssetMapper app
up to date with pull requests (security, patch, minor, major buckets). It is a PHP Symfony Console
application (`bin/asset-mapper-update`, single command `Command\UpdateCommand`) that also runs
outside of GitHub Actions. `action.yml` runs `composer install` into the action directory and calls
the bin with `INPUT_*` env vars, so the action's own `vendor/` is separate from the app's.

## Commands

```sh
composer install
composer all                                   # php-cs-fixer (dry run), PHPStan, PHPUnit
composer cs-fix                                # apply coding style
vendor/bin/phpstan analyse                     # level 8, covers bin/, src/, tests/
vendor/bin/phpunit --filter RunnerTest         # one test class
vendor/bin/phpunit --filter testKeepsSecurityPullRequestsWhenTheAuditFails
```

Markdown is formatted with `npx prettier@3.6.2 --single-quote`; check the diff for unrelated churn.

Local run against a real app (needs a clean working tree):
`GITHUB_TOKEN=$(gh auth token) bin/asset-mapper-update --dry-run --repository=owner/repo` from the
app directory.

## Constraints

- PHP >= 8.2, Symfony components `^7.4 || ^8.1`. `composer.lock` is resolved with
  `config.platform.php` 8.2.0, and that lock is what the action installs, so do not remove the
  platform pin. CI tests 8.2 lowest/locked and 8.4/8.5 highest.
- No `declare(strict_types=1)`; PHPStan stays at level 8. Don't add casts, `assert()`s or helpers that
  only exist to satisfy a stricter level.
- The PR body marker `<!-- asset-mapper-update {"v":1,...} -->` (`PullRequest\PullRequestState`) is
  how later runs recognise and compare existing PRs. Changing its format needs a new `v`.

## Architecture

`Runner::run()` is the orchestration, in four steps:

1. **Read state:** parse `importmap.php` (`Importmap\ImportmapParser`, a tokenizer-based parser that
   keeps byte offsets; the file is never executed), run `importmap:outdated` and `importmap:audit`
   through `Adapter\SymfonyConsole`, drop withdrawn advisories via the GitHub API.
2. **Plan:** `Planner` (pure) combines that with npm registry data (`Adapter\NpmRegistry`) into
   `Model\Plan`: planned PRs per bucket/strategy plus `SkippedPackage`s.
3. **Reconcile:** `Reconciler\Reconciler` (pure) compares the plan with open and closed PRs
   (`Adapter\GitHubClient`) and returns `Action`s: create, keep, update, close, blocked, defer,
   skip-closed. Close actions come first, because `prefix/patch` and `prefix/patch/x` branches
   cannot coexist.
4. **Execute:** for create/update, `Applier` builds the change on a detached HEAD of the base branch
   and `Git::pushWithLease()` pushes it.

Behaviour that spans several files:

- **Applying an update:** `importmap:require` rewrites the whole file, so `Applier` runs it only to
  let Symfony resolve the new state, restores the files from git, and `Importmap\Patcher` applies
  only the version changes (plus new dependency entries) to the original text, then verifies the
  diff. Side-effect bumps of other packages are dropped and listed in the PR body.
  `docs/design.md` explains why, with the real command outputs in `tests/Fixtures/spike/`.
- **Never overwrite people's work:** PRs with commits not made by the bot are `blocked` (one comment,
  never force-pushed or closed). Merge commits made on github.com (committer `noreply@github.com`)
  don't count. Pushes use `--force-with-lease` against the head the checks saw.
- **Missing data keeps PRs:** a package marked `SkippedPackage::$unknown` (npm registry failure, or
  the packages of open security PRs when `importmap:audit` fails) makes the Reconciler keep every
  PR containing it as it is, instead of closing it.
- **Token handling:** `ProcessRunner::STRIP_ENV` removes `GITHUB_TOKEN` from every child process (the
  app's console and its dependencies never see it). `Git::useToken()` passes credentials to
  authenticated git commands only, through `GIT_CONFIG_COUNT/KEY_n/VALUE_n` (not argv), with hooks,
  credential helpers and auto-maintenance disabled.
- Exit codes of `importmap:outdated|audit` are 1 when they find something; `SymfonyConsole` treats a
  call as failed only when stdout has no valid JSON.

## Tests

- `tests/GitRepositories.php` creates real git repositories with a local bare `origin`; `GitTest`,
  `ApplierTest` and `RunnerTest` use it.
- GitHub and npm are faked with `MockHttpClient` routes keyed by `"METHOD /path?query"`; the console
  is faked with an anonymous `CommandRunner` that returns the spike fixtures.
- `.github/workflows/e2e.yml` creates a real Symfony app with old pins and runs the action in dry-run
  mode, asserting the `plan` output with `jq`.
- The full PR lifecycle against GitHub is a manual test in a private sandbox repository:
  `docs/release-checklist.md`. Never use a repository whose workflows deploy something for every
  PR.
