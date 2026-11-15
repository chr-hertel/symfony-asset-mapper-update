# Design notes

Why the action works the way it does. The first part records how the Symfony importmap commands
really behave, because most design decisions follow from that. Re-check it when a new
`symfony/asset-mapper` version changes the commands.

## How the importmap commands behave

Checked with Symfony 8.1.11, `symfony/asset-mapper` 8.1, PHP 8.5 (2026-11). Test app:
`symfony/skeleton` + `symfony/asset-mapper`, then

```
php bin/console importmap:require bootstrap@4.6.0 lodash@4.17.15 @hotwired/stimulus@3.0.0 jquery@3.4.1
```

plus a second variant using the `importmap.php` of a real production app
(multi-line formatting, `declare(strict_types=1);`, CSS entries, local `path` entries).

All raw outputs are stored in [`tests/Fixtures/spike/`](../tests/Fixtures/spike), and the tests use them.

### `importmap:outdated --format=json`

`--format=json` exists (`txt`, `json`). Output is a flat array:

```json
{
  "name": "bootstrap",
  "current": "4.6.0",
  "latest": "5.3.9",
  "latest-status": "update-possible"
}
```

- `latest-status` is `semver-safe-update` (same major) or `update-possible` (new major).
- Only `latest` is reported, so the per-bucket versions (highest patch / minor / major) have to
  come from the npm registry.
- CSS entries show up as separate rows (`bootstrap/dist/css/bootstrap.min.css`).
- Local `path` entries and up-to-date packages are not listed.
- **Exit code is `1` when something is outdated**, so a non-zero exit is not an error. The adapter
  only treats the call as failed if stdout is not valid JSON.

Fixtures: `outdated.json`, `outdated.txt`, `outdated-app.json`

### `importmap:audit --format=json`

```json
{
  "packages": [
    {
      "package": "lodash",
      "version": "4.17.15",
      "vulnerabilities": [
        {
          "ghsa_id": "GHSA-35jh-r3h4-6jhm",
          "cve_id": "CVE-2021-23337",
          "url": "https://api.github.com/advisories/GHSA-35jh-r3h4-6jhm",
          "summary": "Command Injection in lodash",
          "severity": "high",
          "vulnerable_version_range": "< 4.17.21",
          "first_patched_version": "4.17.21"
        }
      ]
    }
  ],
  "summary": { "critical": 0, "high": 0, "medium": 0, "low": 1, "unknown": 0 }
}
```

Things to know:

- **Exit code is `1` when vulnerabilities are found.** It is handled like `outdated`.
- **It calls the GitHub advisory API without a token**, so it is subject to the rate limit of the
  machine's IP (60 requests per hour), which GitHub-hosted runners share. When it fails, the action
  warns and continues without security updates; the packages of open security PRs are treated like
  packages the npm registry failed for, so those PRs are kept and the packages are not proposed in
  other buckets.
- `url` is the **API** URL, not the HTML page. The PR body links to `https://github.com/advisories/<GHSA>`.
- **Withdrawn advisories are reported.** bootstrap 4.6.0 is flagged by GHSA-vc8w-jr9v-vj7f, which
  was withdrawn in 2025-10 (`"summary": "Withdrawn Advisory: …"`). Treating it as real would push
  every Bootstrap 4 user into a major upgrade under the "security" label. The action checks
  `withdrawn_at` through `GET /advisories/{ghsa}` with the given token. If the API call fails, it
  falls back to the summary prefix `Withdrawn`. Withdrawn advisories are ignored.
- **Duplicates.** A package with a CSS entry has every advisory listed twice (once per entry).
  The action deduplicates by GHSA id.
- `first_patched_version` can be `null` (GHSA-8p5q-j9m2-g8wr). The security target is therefore
  not taken from `first_patched_version`. It is the lowest registry version that satisfies
  **none** of the `vulnerable_version_range`s. Those ranges use the GitHub format
  `>= 4.0.0, <= 4.6.2` and are evaluated with a small semver implementation. composer/semver
  does not fit: npm versions are not Composer versions (e.g. `1.0.0-next.3`).
- Real-world case: `@hotwired/turbo` 7.3.0 is vulnerable up to 8.0.20 and fixed in 8.0.21. No fixed version exists in 7.x, so the "fall back to the next major and say so" path
  is a real case and not just an edge case.

Fixtures: `audit.json`, `audit-app.json`

### `importmap:update <pkg>`

- **Always goes to latest, including majors** (bootstrap 4.6.0 → 5.3.9). It has no version
  argument, so it cannot do patch-only or minor-only updates.
- Bumps the package's CSS entry too.
- **Reorders keys.** The updated entries are removed and appended at the end of the array.
- Adds new dependencies (`@popperjs/core`) but leaves the old ones in place (`popper.js` stays).

→ **Not usable** for this action.

Fixture: `importmap-compact-after-update-bootstrap.php`

### `importmap:require <pkg>@<exact-version>`

- **Can target an exact version** (`bootstrap@4.6.2`). It also bumps the package's existing
  sub-path entries (the bootstrap and fontawesome CSS followed along).
- **Side effects on other packages.** `require bootstrap@4.6.2` also bumped `jquery` from 3.4.1 to 3.6.0,
  because it re-resolves the package's dependencies to the newest matching versions.
- **It rewrites the whole file.** Given the multi-line file of the production app, it:
  - dropped `declare(strict_types=1);`
  - replaced the docblock with the current recipe docblock
  - collapsed every entry into the compact one-line format (`'x' => ['version' => '1.0.0'],`)

  On a file that is already in the 8.1 compact format the rewrite happens to be a no-op. On any
  older or hand-edited file the diff touches every line.

- It writes `assets/vendor/**` and `assets/vendor/installed.php`, which are normally git-ignored.

Fixtures: `importmap-multiline.php` → `importmap-multiline-after-require.php`,
`importmap-compact.php` → `importmap-compact-after-require-bootstrap-4.6.2.php`

## Consequences for the design

1. **Bump mechanism: "resolve with `require`, apply as a text patch".**
   Plain `require` would rewrite the whole file on almost every real project, so the diff of a PR
   would touch every line. Instead, for each planned PR:
   1. Run `importmap:require <pkg>@<to> <pkg>/<sub-entry>@<to> …` for every package in the PR, so
      Symfony / jsDelivr resolve the new state (this includes new dependencies).
   2. Read the resulting map, then **restore** `importmap.php` (and any other tracked file the
      command touched) from git.
   3. Compute the semantic diff against the original map and apply it to the **original text**:
      - version changes of the planned package and its sub-entries are applied by replacing only
        the version string literal inside that entry
      - **new** entries (new dependencies, e.g. `@popperjs/core` on a bootstrap 4 → 5 bump) are
        appended before the closing `];`, in the file's own style (multi-line or compact,
        indentation, trailing comma)
      - version changes of **other existing** entries (the jquery side effect) are **not applied**
        and are listed in the PR body as "not changed by this PR". Turning them into an error would
        block most bootstrap/jquery-style bumps.
      - any other change to a planned entry (type, removed key, …) or a removed planned entry
        **aborts that PR** with an error
   4. Verify: parse the patched file and check it equals `original + intended changes`, and that
      the textual diff only touches lines inside the planned entries or the appended block.
      Otherwise, abort the PR.
2. **`importmap.php` is parsed, not executed**, with PHP's own tokenizer (`token_get_all()`):
   short and long arrays, quoted strings, numbers, bools/null, comments, a preamble such as
   `declare`. The parser keeps byte offsets, which the text patcher needs. Unsupported syntax
   (e.g. constants or function calls in values) fails with a clear error.
3. **Exit codes** of `outdated` / `audit` are not used to detect errors.
4. **Withdrawn advisories** are filtered out, advisories are deduplicated, and the security
   target is computed from the vulnerable ranges plus registry versions.
5. **Package grouping:** an entry's package name is its `package_specifier` (or its key) cut down
   to `name` / `@scope/name`. All entries of a package are bumped together. A CSS-only package
   (only `pkg/path.css` entries) is still one package.
6. **Version targets** are capped at the `latest` dist-tag. Deprecated versions are avoided but not
   excluded: lodash 4.18.0 is a deprecated "bad release" (→ 4.18.1 is used), but bootstrap
   deprecated its _whole_ 4.x line, so 4.6.2 is still the right patch target for 4.6.0.
7. Requires a network connection to jsDelivr (through `require`) and to `registry.npmjs.org`,
   which the workflow already needs.

## Other decisions

- **A PHP Symfony Console application** (`bin/asset-mapper-update`) that a composite action runs.
  The workflow already has PHP and Composer for the app, and the command also works outside of
  GitHub Actions.
- **Own `vendor/`:** the action installs its dependencies into the action directory from its
  `composer.lock` (Symfony 7.4, locked for PHP 8.2), so it works next to any Symfony version of the
  app. The Composer package allows Symfony `^7.4 || ^8.1`.
- **No secrets for child processes:** the app's console and git run without `GITHUB_TOKEN` in their
  environment. Git gets the credentials through `GIT_CONFIG_COUNT`/`_KEY_n`/`_VALUE_n`, so they do
  not show up in the process list.
- **Names:** repository `chr-hertel/symfony-asset-mapper-update`, Composer package
  `hertel/symfony-asset-mapper-update`, action name "Symfony AssetMapper Update", PR marker
  `<!-- asset-mapper-update {...} -->`. The marker holds the state of the PR (bucket and updates),
  so the action can tell whether a PR is up to date without rebuilding it. Do not change its format
  without a new `v`.
- **Versions:** each release has its own semver tag (`1.0.1`); `v1` is a rolling tag that a workflow
  moves to the latest `1.x.y` release, like `shivammathur/setup-php` does with `v2`. A new major
  only for breaking changes to inputs, outputs or the configuration file.
- **Manual tests** run against a private sandbox repository, so no real project gets pull requests
  or deployments from them. See [release-checklist.md](release-checklist.md).
