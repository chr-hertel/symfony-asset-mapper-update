<?php

namespace AssetMapperUpdate\PullRequest;

use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\Model\PlannedUpdate;

/**
 * Titles, commit messages and bodies of the pull requests.
 */
final class Content
{
    public static function title(PlannedPullRequest $pr): string
    {
        $only = $pr->single();
        if (null !== $only) {
            return \sprintf('Bump %s from %s to %s', $only->name, $only->from, $only->to);
        }

        return \sprintf('Bump %d importmap packages (%s)', \count($pr->updates), $pr->bucket->value);
    }

    public static function commitMessage(PlannedPullRequest $pr, string $prefix): string
    {
        $p = '' !== $prefix ? $prefix.' ' : '';
        $only = $pr->single();
        if (null !== $only) {
            return \sprintf('%sbump %s from %s to %s in importmap', $p, $only->name, $only->from, $only->to);
        }
        $lines = array_map(static fn (PlannedUpdate $u): string => \sprintf('- %s from %s to %s', $u->name, $u->from, $u->to), $pr->updates);

        return \sprintf("%sbump %d importmap packages (%s)\n\n%s", $p, \count($pr->updates), $pr->bucket->value, implode("\n", $lines));
    }

    public static function body(PlannedPullRequest $pr, ApplyDetails $details, string $consoleCommand): string
    {
        $out = [];
        $only = $pr->single();
        $out[] = null !== $only
            ? \sprintf('Bumps **%s** from %s to %s in `importmap.php`.', $only->name, $only->from, $only->to)
            : \sprintf('Bumps %d packages in `importmap.php` (%s updates).', \count($pr->updates), $pr->bucket->value);

        array_push($out, '', '| Package | From | To | Update type |', '| --- | --- | --- | --- |');
        foreach ($pr->updates as $u) {
            $package = null !== $u->links ? \sprintf('[%s](%s)', self::escape($u->name), $u->links->npm) : self::escape($u->name);
            $out[] = \sprintf('| %s | %s | %s | %s |', $package, $u->from, $u->to, $u->updateType->value);
        }

        $vulnerable = array_filter($pr->updates, static fn (PlannedUpdate $u): bool => [] !== $u->advisories);
        if ([] !== $vulnerable) {
            array_push($out, '', '### Security advisories', '', '| Package | Advisory | Severity | Summary | Vulnerable versions |', '| --- | --- | --- | --- | --- |');
            foreach ($vulnerable as $u) {
                foreach ($u->advisories as $a) {
                    $id = \sprintf('[%s](%s)%s', $a->ghsaId, $a->url, null !== $a->cveId ? ' ('.$a->cveId.')' : '');
                    $out[] = \sprintf('| %s | %s | %s | %s | %s |', self::escape($u->name), $id, $a->severity->value, self::escape($a->summary), self::code($a->vulnerableVersionRange));
                }
            }
        }

        $crossing = array_filter($pr->updates, static fn (PlannedUpdate $u): bool => $u->crossesMajor);
        if ([] !== $crossing) {
            array_push($out, '', '> [!WARNING]');
            foreach ($crossing as $u) {
                $out[] = \sprintf('> **%s**: no fixed version exists in %s.x, so this updates to the next major version (%s). Check the release notes for breaking changes.', $u->name, explode('.', $u->from)[0], $u->to);
            }
        }

        $withNotes = array_filter($pr->updates, static fn (PlannedUpdate $u): bool => null !== ($u->links->releaseNotes ?? $u->links->repository ?? null));
        if ([] !== $withNotes) {
            array_push($out, '', '### Release notes', '');
            foreach ($withNotes as $u) {
                \assert(null !== $u->links);
                $links = array_filter([
                    null !== $u->links->releaseNotes ? \sprintf('[release notes](%s)', $u->links->releaseNotes) : null,
                    null !== $u->links->repository ? \sprintf('[repository](%s)', $u->links->repository) : null,
                    \sprintf('[npm](%s)', $u->links->npm),
                ]);
                $out[] = \sprintf('- **%s**: %s', $u->name, implode(' · ', $links));
            }
        }

        array_push($out, '', '<details><summary>Updated importmap entries</summary>', '');
        foreach ($pr->updates as $u) {
            foreach ($u->entries as $entry) {
                $out[] = '- '.self::code($entry);
            }
        }
        array_push($out, '', '</details>');

        if ([] !== $details->additions) {
            array_push($out, '', '### New entries', '', 'These entries were added because the new versions depend on them:', '');
            foreach ($details->additions as $key => $attributes) {
                $out[] = rtrim(\sprintf('- %s %s', self::code((string) $key), \is_string($attributes['version'] ?? null) ? $attributes['version'] : ''));
            }
        }

        if ([] !== $details->ignoredSideEffects) {
            array_push(
                $out,
                '',
                '<details><summary>Not changed by this PR</summary>',
                '',
                '`importmap:require` also re-resolved these entries. This PR leaves them as they are; they get their own pull requests if needed:',
                '',
            );
            foreach ($details->ignoredSideEffects as $sideEffect) {
                $out[] = '- '.self::code($sideEffect);
            }
            array_push($out, '', '</details>');
        }

        array_push(
            $out,
            '',
            '---',
            '',
            \sprintf('`assets/vendor/` is not part of this diff. It is regenerated by `%s importmap:install`.', $consoleCommand),
            '',
            '<sub>Opened by [Symfony AssetMapper Importmap Update](https://github.com/chr-hertel/symfony-asset-mapper-update). This pull request is rebuilt when new versions are released and closed when it is no longer needed. If you close it, the same update is not proposed again. Please do not push to this branch; your commits would block automatic updates.</sub>',
            '',
            PullRequestState::of($pr)->render(),
        );

        return implode("\n", $out);
    }

    public static function escape(string $text): string
    {
        return (string) preg_replace('/\r?\n/', ' ', str_replace('|', '\|', $text));
    }

    private static function code(string $text): string
    {
        return '`'.str_replace('`', "'", $text).'`';
    }
}
