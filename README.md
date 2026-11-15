# Symfony AssetMapper Importmap Update

A GitHub Action that keeps the JavaScript and CSS dependencies in the `importmap.php` of a Symfony
[AssetMapper](https://symfony.com/doc/current/frontend/asset_mapper.html) app up to date. It is like
Dependabot, but for `importmap.php`.

- It finds **outdated** packages (`importmap:outdated`) and **vulnerable** packages (`importmap:audit`).
- It opens **pull requests** for them. You can group them by update type (security, patch, minor, major).
- It keeps those pull requests **in sync** on every run: it rebuilds them when a newer version is
  released, and closes them when they are no longer needed.

## Why?

Dependabot does not understand `importmap.php`. Dependencies like `'bootstrap' => ['version' => '5.3.3']`
silently get older. Often you only notice when `importmap:audit` starts to report a vulnerability. This
action fills that gap. The design follows [thoughtbot/importmap-update](https://github.com/thoughtbot/importmap-update),
which does the same for Rails.

## Quick start

```yaml
# .github/workflows/importmap-updates.yml
name: Importmap updates
on:
  schedule:
    - cron: '17 6 * * 1'
  workflow_dispatch:

permissions:
  contents: write
  pull-requests: write

jobs:
  update:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - uses: ramsey/composer-install@v4
        with:
          composer-options: --no-scripts
      - uses: chr-hertel/symfony-asset-mapper-update@v1
        with:
          github-token: ${{ secrets.GITHUB_TOKEN }}
```

`--no-scripts` skips Composer's `auto-scripts` (`cache:clear`, `assets:install`,
`importmap:install`, …), which the action does not need. They can change tracked files: since
Symfony 7.4, building the container in debug mode regenerates `config/reference.php`, and after a
Dependabot update of a bundle the committed file is outdated. The action refuses to run with
uncommitted changes to tracked files.

The action is a Symfony Console application written in PHP. It needs **PHP 8.2 or newer and
Composer**, and it installs its own dependencies (Symfony 7.4 components) into the action
directory, separate from your app's `vendor/`, so your app can use any Symfony version. It does not install PHP itself. Set up the app first, so that `php bin/console`
works. It needs network access to `registry.npmjs.org` and to jsDelivr (which `importmap:require`
uses).

If you use the default `GITHUB_TOKEN`, enable **Settings → Actions → General → "Allow GitHub Actions
to create and approve pull requests"**. Without it, GitHub rejects the pull requests.

## Inputs

| Input               | Default                         | Description                                                                                                                       |
| ------------------- | ------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| `github-token`      | _required_                      | Token for `git push` and the GitHub API. See [Tokens and CI](#tokens-and-ci).                                                     |
| `console`           | `php bin/console`               | Command to call the Symfony console, e.g. `docker compose exec -T php bin/console`.                                               |
| `working-directory` | `.`                             | Directory of the Symfony app (the one with `importmap.php`), for monorepos.                                                       |
| `config-file`       | `.github/importmap-updates.yml` | Optional config file, relative to the repository root.                                                                            |
| `dry-run`           | `false`                         | Plan, apply and verify the updates locally, but do not push, create, update or close anything. Every planned operation is logged. |
| `base-branch`       | default branch                  | Branch the pull requests target.                                                                                                  |

## Outputs

| Output          | Description                                                                                                                                                                             |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `plan`          | JSON of the plan: `pullRequests[]` (branch, bucket, strategy, title, `updates[]` with name, from, to, updateType, entries, reason, advisories) and `skipped[]` (name, current, reason). |
| `pull-requests` | JSON list of the pull requests that were created, updated, closed or left as they are: `{action, number, url, branch, dryRun}`.                                                         |

## Configuration

All fields are optional. Unknown keys or values fail the run with a clear error message.

```yaml
# .github/importmap-updates.yml
version: 1

grouping:
  security: { strategy: individual } # one PR per vulnerable package
  patch: { strategy: grouped } # one PR for all patch bumps
  minor: { strategy: grouped }
  major: { strategy: individual }

ignore:
  - name: bootstrap
    update-types: [major] # stay on bootstrap 5
  - name: '@fortawesome/*' # ignore entirely; * is a wildcard

open_pull_requests_limit: 10
labels: [dependencies, javascript]
branch_prefix: importmap-updates
commit_message_prefix: 'chore(deps):'
reviewers: [] # users, or org/team-slug for teams
assignees: []
```

| Key                          | Default                                                                     | Description                                                                                                               |
| ---------------------------- | --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `grouping.<bucket>.strategy` | security `individual`, patch `grouped`, minor `grouped`, major `individual` | `individual`: one PR per package. `grouped`: one PR per bucket. `skip`: no PRs for this bucket.                           |
| `ignore[].name`              |                                                                             | Package name. `*` matches anything.                                                                                       |
| `ignore[].update-types`      | all                                                                         | Any of `patch`, `minor`, `major`. Without it, the package is ignored entirely, including security updates.                |
| `open_pull_requests_limit`   | `10`                                                                        | Maximum number of open PRs. Security PRs go first and count toward the limit.                                             |
| `labels`                     | `[dependencies, javascript]`                                                | Labels for every PR. Security PRs also get `security`. Missing labels are created.                                        |
| `branch_prefix`              | `importmap-updates`                                                         | Prefix of the branch names. If you change it, the action no longer sees the PRs under the old prefix: close them by hand. |
| `commit_message_prefix`      | `chore(deps):`                                                              | Prefix of the commit message.                                                                                             |
| `reviewers`, `assignees`     | `[]`                                                                        | Requested on new PRs.                                                                                                     |

## How it works

### 1. The plan

1. It reads `importmap.php`, `importmap:outdated --format=json` and `importmap:audit --format=json`.
2. Entries without `version` (local `path` entries) and entrypoints are skipped. CSS and other sub-path
   entries (e.g. `bootstrap/dist/css/bootstrap.min.css`) belong to their package. They are always bumped
   together with it and never get their own PR.
3. It loads the versions of every outdated or vulnerable package from the npm registry. Then it finds the
   highest **patch**, **minor** and **major** version. Pre-releases are only considered if the current
   version is one. Versions above the `latest` tag are ignored. Deprecated versions are avoided. They are only used if a bucket has nothing else, for example because bootstrap deprecated its whole 4.x line.
4. **Security:** if the current version is affected by an advisory, the package goes into `security`.
   The target is the lowest version that fixes _all_ advisories. It is in the same major version if
   possible. If not, it is the next major, and the PR says so. For 0.x versions the minor version
   counts as the major here (as with `^0.12`), so a fix that is only in 0.13 is flagged the same way. Withdrawn advisories, which
   `importmap:audit` still reports, are ignored. Security updates ignore `update-types` rules, because
   staying vulnerable is rarely the intent. Ignore the package entirely to opt out.
5. **Otherwise** the package goes into its highest bucket. If that bucket is ignored for the package or
   set to `skip`, it falls back to the next lower one. For example, with the major ignored, you get the
   latest minor.

The buckets follow the position of the change, like Dependabot does: 0.12 → 0.13 is a **minor**
update, although for 0.x packages it may contain breaking changes. To update such a package by hand
instead, add an `ignore` rule with `update-types: [minor]` for it.

Branch names are deterministic: `importmap-updates/security/lodash`, `importmap-updates/minor`,
`importmap-updates/major/@hotwired/turbo`.

### 2. Applying an update

`importmap:update` always updates to the latest version, including majors, and reorders the file.
`importmap:require` can target an exact version, but it rewrites the whole file. See
[docs/design.md](docs/design.md) for details. So the action works like this:

1. It runs `importmap:require <package>@<version> <package>/<css-entry>@<version>`, so that Symfony
   resolves the new state, including new dependencies.
2. It restores the files the command wrote, and then applies **only** the version changes of the
   planned packages to the original text of `importmap.php`. New dependency entries
   (e.g. `@popperjs/core` for bootstrap 5) are added in the style of your file.
3. It checks that the diff only touches those lines. If not, that PR is aborted with an error, and you
   never get a noisy diff.

Changes `importmap:require` wanted to make to _other_ packages are not applied. The PR body lists them.

### 3. Keeping pull requests in sync

The action finds its own PRs by the branch prefix **and** a hidden marker in the PR body. The marker
stores the planned versions. Pushes use `--force-with-lease`: if someone pushes to the branch while
the action prepares the update, the push is rejected instead of overwriting their commit. On every
run:

| Situation                                                                                                     | What happens                                                                                                                                      |
| ------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| PR exists, plan unchanged                                                                                     | Nothing. No force-push, so CI does not run again.                                                                                                 |
| PR exists, target versions changed (new release, package added to or removed from a group)                    | The branch is rebuilt from the current base and force-pushed. Title and body are updated.                                                         |
| PR exists, base branch moved and the PR conflicts                                                             | The branch is rebuilt.                                                                                                                            |
| PR exists but is not needed anymore (merged elsewhere, updated by hand, now ignored, package removed)         | The PR is closed with a comment that explains why, and the branch is deleted.                                                                     |
| You closed a PR without merging it                                                                            | The same update is not proposed again. A newer version gets a new PR.                                                                             |
| Someone pushed commits to the branch                                                                          | The action never force-pushes or closes it. It leaves one comment instead.                                                                        |
| Someone clicked "Update branch" on GitHub, or resolved a conflict there                                       | Not counted as a manual commit. If the PR is rebuilt later, a conflict resolved by hand there is replaced; push a normal commit to keep a change. |
| The npm registry failed for a package of the PR                                                               | The PR is kept as it is until the next run.                                                                                                       |
| `importmap:audit` failed (it calls the GitHub API without a token, so the runner's IP can hit the rate limit) | A warning. The run makes no security updates, keeps the open security PRs as they are, and does the other updates.                                |
| PR without the marker                                                                                         | Never touched.                                                                                                                                    |

### 4. Job summary

Every run writes a job summary. It lists the plan, skipped packages and why, and what was created,
updated, closed, deferred (limit reached) or blocked.

## Tokens and CI

Pull requests and pushes made with the default `GITHUB_TOKEN` **do not trigger other workflows**. Your
CI does not run on the update PRs. If you need CI on them, use a GitHub App token or a fine-grained
personal access token (contents and pull requests: read and write):

```yaml
- uses: actions/create-github-app-token@v3
  id: app-token
  with:
    app-id: ${{ vars.APP_ID }}
    private-key: ${{ secrets.APP_PRIVATE_KEY }}
- uses: chr-hertel/symfony-asset-mapper-update@v1
  with:
    github-token: ${{ steps.app-token.outputs.token }}
```

The action uses this token for pushes too. It replaces the credentials that `actions/checkout` stored,
so the pushes trigger `pull_request: synchronize` workflows as well. The token never reaches the
processes the action starts: your app's console (with its bundles and dependencies) runs without
`GITHUB_TOKEN` in its environment, and git gets the credentials through its environment, not on the
command line, where other users of the machine could see them. Git runs no hooks for those
commands, so hooks that tools like CaptainHook or GrumPHP installed do not see the credentials either.

## Running it without GitHub Actions

The action is a thin wrapper around a console command, so you can run it anywhere, for example to
see the plan before you enable the workflow:

```sh
git clone https://github.com/chr-hertel/symfony-asset-mapper-update.git
composer install --working-dir=symfony-asset-mapper-update --no-dev

cd my-symfony-app
GITHUB_TOKEN=$(gh auth token) ../symfony-asset-mapper-update/bin/asset-mapper-update --dry-run
```

The options are the same as the inputs: `--console`, `--working-directory`, `--config-file`,
`--dry-run`, `--base-branch`. The repository comes from `--repository=owner/repo`,
`$GITHUB_REPOSITORY` or the `origin` remote. The token is only read from `GITHUB_TOKEN`, so it does
not end up in your shell history. The command refuses to run with uncommitted changes to tracked
files, because it checks out the base branch with `--force`, and names the files. This also applies
on GitHub Actions, so earlier steps in the job must not modify tracked files (see `--no-scripts` in
the [quick start](#quick-start)). The updates are built on a detached `HEAD`,
so no local branches are created. At the end, it prints the same summary as the job summary.

## Also run `importmap:audit` in CI

This action runs on a schedule. To catch a vulnerable package the moment it is added, also run the
audit in your regular CI:

```yaml
- run: php bin/console importmap:audit
```

## Limitations

- Only remote packages that `importmap:require` manages. Entries with a local `path` (including
  packages from `vendor/` or `node_modules`) are left alone.
- `importmap.php` must be a plain `return [...]` of literals (the format Symfony generates). Constants,
  function calls or string interpolation in the file cause a clear error.
- The action does not remove entries that are no longer needed. For example, after bootstrap 4 → 5 the
  old `popper.js` entry stays.
- `assets/vendor/` is not committed. Run `importmap:install` after checking out an update branch, as
  usual.
- No auto-merge, no Rails, no npm/`node_modules` dependencies.

## Development

```sh
composer install
composer all      # coding style, PHPStan (level 8), PHPUnit
```

`composer.lock` is resolved for PHP 8.2 (`config.platform.php`), so the action installs the same
dependencies on every supported PHP version. CI also runs the tests with the lowest and highest
dependencies. [`.github/workflows/e2e.yml`](.github/workflows/e2e.yml) creates a real Symfony app
with old pinned packages and runs the action in dry-run mode.

The code is split into pure parts (`Planner`, `Reconciler`, `Importmap\Patcher`) and adapters for
the outside world (`Adapter\SymfonyConsole`, `NpmRegistry`, `Git`, `GitHubClient`). `Runner`
connects them, and `Command\UpdateCommand` maps the console input and the GitHub Actions outputs.

## Versioning

- Use `@v1`. It is a rolling tag that always points to the latest `1.x.y` release, so you get bug
  fixes and new features without changing your workflow.
- Every release also has its own tag, e.g. `@1.0.1`. If you pin one, let
  [Dependabot](https://docs.github.com/en/code-security/dependabot/working-with-dependabot/keeping-your-actions-up-to-date-with-dependabot)
  update it.
- A full commit SHA works too, and is the safest option, but it also needs a tool that updates it.
- A new major version (`v2`) only comes with breaking changes to the inputs, the outputs or the
  configuration file.
- Do not use `@main`. It can contain unreleased changes.

## Contributing

This repository does not accept pull requests. If you found a bug or have an idea,
[open an issue](https://github.com/chr-hertel/symfony-asset-mapper-update/issues). For a bug, add:

- the version of the action you use (e.g. `@v1` or a commit SHA)
- the step of your workflow that runs it
- the log of the run (re-run it with debug logging for more details)
- the relevant entries of your `importmap.php`

## License

MIT
