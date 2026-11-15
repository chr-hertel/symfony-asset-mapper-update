# Release checklist

CI covers the unit tests and a dry run against a fresh Symfony app (`e2e.yml`). What CI cannot
cover is the full pull request lifecycle against GitHub. Run through it before tagging a release,
and after changes to `Runner`, `Reconciler`, `GitHubClient`, `Git` or `action.yml`.

## Sandbox setup

Use a **private** repository that nobody depends on, so no real project gets pull requests or
deployments from the test. Never use a repository whose workflows deploy something for every PR.

1. A Symfony app with old pinned packages, so there is something in every bucket:

   ```sh
   composer create-project symfony/skeleton sandbox && cd sandbox
   composer require symfony/asset-mapper
   php bin/console importmap:require bootstrap@4.6.0 lodash@4.17.15 @hotwired/stimulus@3.0.0 \
       @hotwired/turbo@7.3.0 jquery@3.4.1 @fortawesome/fontawesome-free@6.4.0
   ```

   Then rewrite `importmap.php` by hand into the multi-line style with `declare(strict_types=1);`,
   so the test shows that the action keeps the formatting. Keep the local `path` entries.

2. `.github/workflows/importmap-updates.yml`:

   ```yaml
   name: Importmap updates
   on:
     push:
       branches: [main]
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
         - uses: chr-hertel/symfony-asset-mapper-update@main # or the branch / tag under test
           id: update
           with:
             github-token: ${{ secrets.GITHUB_TOKEN }}
         - name: Outputs
           env:
             PRS: ${{ steps.update.outputs.pull-requests }}
           run: echo "$PRS" | jq .
   ```

3. Enable **Settings → Actions → General → "Allow GitHub Actions to create and approve pull
   requests"**.

Useful commands:

```sh
gh workflow run "Importmap updates" -R owner/sandbox
gh run watch -R owner/sandbox $(gh run list -R owner/sandbox --limit 1 --json databaseId -q '.[0].databaseId')
gh pr list -R owner/sandbox --json number,title,headRefName,headRefOid,updatedAt
gh pr diff <number> -R owner/sandbox
```

"Bump a package on `main`" means: edit its version string in `importmap.php` (and its CSS entry, if
it has one), commit and push. The push triggers the workflow.

## Scenarios

Note the head SHA and `updatedAt` of every PR before each step.

| #   | Step                                                                                                                                             | Expected                                                                                                                                                                            |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | First run                                                                                                                                        | One PR per bucket. Diffs only touch version lines; formatting and `declare` kept. Labels `dependencies`, `javascript`, plus `security` on security PRs.                             |
| 2   | Run again, nothing changed                                                                                                                       | All PRs `unchanged`. Head SHAs and `updatedAt` identical (nothing force-pushed, no CI re-run).                                                                                      |
| 3   | Bump one PR's package to its target version on `main`                                                                                            | That PR is closed with "… is already at … on the base branch", its branch is deleted. The other PRs are untouched.                                                                  |
| 4   | Bump two packages on `main` so that the plan changes                                                                                             | A PR whose updates changed is rebuilt in place (new head, new title). A PR whose package moved to another bucket is closed with "now proposed in `…`".                              |
| 5   | Push a commit by hand to a PR branch, then bump its package                                                                                      | That PR is **not** closed and **not** force-pushed (head stays the human commit). The run logs a `blocked` warning, the PR gets exactly one comment.                                |
| 6   | Change packages on `main` at once so that one PR's update changes, and another package moves to a bucket without a PR (e.g. from patch to minor) | One PR rebuilt, one closed ("now proposed in `…`"), one created, in one run. A package that moves within a grouped bucket rebuilds that PR instead ("added …, removed …").          |
| 7   | Run again with `workflow_dispatch`                                                                                                               | Everything `unchanged`. The PR from step 5 is still `blocked` and still has only one comment.                                                                                       |
| 8   | Close a PR by hand without merging, run again                                                                                                    | The same update is not proposed again. The log says "not proposed again, the same update was closed without merging in #…" (skipped updates are not in the `pull-requests` output). |
| 9   | Use **Update branch** on a PR (push to `main` first if it is not behind), run, then change its update on `main`                                  | After the merge the PR is still `unchanged`. After the change it is rebuilt from the base branch (merge commit gone, new title), not `blocked`.                                     |

Also check once per release:

- The run log does not contain the token, and the job summary renders.
- `dry-run: true` creates, pushes and closes nothing.

## Releasing

Releases have a plain semver tag without a `v` (`1.0.1`, `1.1.0`, `2.0.0-beta1`). `v1` is a rolling
tag, not a release: `.github/workflows/release.yml` moves it to every new stable `1.x.y` release.

1. Run the scenarios above against `@main`, and wait for CI and e2e on the commit you release.
2. Create the release with a new tag on that commit, with notes on what changed for users:

   ```sh
   gh release create 1.0.1 --target <sha> --title 1.0.1 --notes-file notes.md
   ```

   Mark it as a pre-release for `-alpha`/`-beta`/`-rc` versions; the workflow does not move `v1`
   for those. To list the release in the Marketplace, edit it in the GitHub UI and tick "Publish
   this Action to the GitHub Marketplace".

3. Check that the **Release** workflow moved `v1` (`git ls-remote --tags origin v1` shows the
   release commit), then run the sandbox workflow once with `@v1`.

The workflow only moves `v1` to the highest `1.x.y` tag, so releasing a fix for an older minor does
not move it backwards. To move it by hand:

```sh
git tag -fa v1 -m "Update v1 tag to 1.0.1" 1.0.1^{commit}
git push -f origin refs/tags/v1
```

Never create a release on the `v1` tag itself. With immutable releases, that would lock it.
