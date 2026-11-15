<?php

namespace AssetMapperUpdate;

use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\PullRequest\Content;

/**
 * Markdown for the GitHub job summary.
 */
final class Summary
{
    private const ICONS = [
        'created' => '🆕',
        'updated' => '🔄',
        'unchanged' => '✅',
        'closed' => '🗑️',
        'blocked' => '✋',
        'deferred' => '⏸️',
        'skipped' => '⏭️',
        'failed' => '❌',
    ];

    public static function render(RunResult $result, bool $dryRun): string
    {
        $out = [\sprintf('## Importmap updates%s', $dryRun ? ' (dry run)' : ''), '', \sprintf('Base branch: `%s`', $result->base), ''];
        if ($dryRun) {
            array_push($out, '> [!NOTE]', '> Dry run: nothing was pushed, and no pull request was created, updated or closed.', '');
        }

        array_push($out, '### Plan', '');
        if ([] === $result->plan->pullRequests) {
            array_push($out, 'Everything is up to date. 🎉', '');
        } else {
            array_push($out, '| Branch | Package | From | To | Type | Reason |', '| --- | --- | --- | --- | --- | --- |');
            foreach ($result->plan->pullRequests as $pr) {
                foreach ($pr->updates as $u) {
                    $type = Bucket::Security === $pr->bucket ? \sprintf('security (%s)', $u->updateType->value) : $u->updateType->value;
                    $out[] = \sprintf('| `%s` | %s | %s | %s | %s | %s |', $pr->branch, Content::escape($u->name), $u->from, $u->to, $type, Content::escape($u->reason));
                }
            }
            $out[] = '';
        }

        if ([] !== $result->plan->skipped) {
            array_push($out, '### Skipped packages', '', '| Package | Current | Reason |', '| --- | --- | --- |');
            foreach ($result->plan->skipped as $s) {
                $out[] = \sprintf('| %s | %s | %s |', Content::escape($s->name), $s->current, Content::escape($s->reason));
            }
            $out[] = '';
        }

        array_push($out, '### Pull requests', '');
        if ([] === $result->results) {
            array_push($out, 'Nothing to do.', '');
        } else {
            array_push($out, '| | Result | Pull request | Branch | Details |', '| --- | --- | --- | --- | --- |');
            foreach ($result->results as $r) {
                $label = $r->dryRun && \in_array($r->action, ['created', 'updated', 'closed'], true) ? 'would be '.$r->action : $r->action;
                $link = null !== $r->number && null !== $r->url ? \sprintf('[#%d](%s)', $r->number, $r->url) : (null !== $r->title ? Content::escape($r->title) : '');
                $out[] = \sprintf('| %s | %s | %s | `%s` | %s |', self::ICONS[$r->action], $label, $link, $r->branch, Content::escape($r->detail));
            }
            $out[] = '';
        }

        if ([] !== $result->errors) {
            array_push($out, '### Errors', '');
            foreach ($result->errors as $error) {
                $out[] = '- '.Content::escape($error);
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }
}
