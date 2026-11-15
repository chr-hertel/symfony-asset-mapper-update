<?php

namespace AssetMapperUpdate\Tests;

use AssetMapperUpdate\Model\Plan;
use AssetMapperUpdate\Model\SkippedPackage;
use AssetMapperUpdate\PullRequestResult;
use AssetMapperUpdate\RunResult;
use AssetMapperUpdate\Summary;
use PHPUnit\Framework\TestCase;

final class SummaryTest extends TestCase
{
    public function testShowsPlanSkippedPackagesResultsAndErrors(): void
    {
        $result = self::runResult();
        $text = Summary::render($result, true);

        $this->assertStringContainsString('## Importmap updates (dry run)', $text);
        $this->assertStringContainsString('| `importmap-updates/patch` | a | 1.0.0 | 1.0.1 | patch | latest patch version |', $text);
        $this->assertStringContainsString('| b | 1.0.0 | ignored by config |', $text);
        $this->assertStringContainsString('| 🆕 | would be created | Bump a from 1.0.0 to 1.0.1 | `importmap-updates/patch` | new pull request |', $text);
        $this->assertStringContainsString('| 🗑️ | would be closed | [#3](u) |', $text);
        $this->assertStringContainsString('- importmap-updates/major/c: boom', $text);
    }

    public function testOutputs(): void
    {
        $result = self::runResult();
        $plan = $result->planOutput();
        $this->assertSame([
            'branch' => 'importmap-updates/patch',
            'bucket' => 'patch',
            'strategy' => 'individual',
            'title' => 'Bump a from 1.0.0 to 1.0.1',
            'updates' => [['name' => 'a', 'from' => '1.0.0', 'to' => '1.0.1', 'updateType' => 'patch', 'entries' => ['a'], 'reason' => 'latest patch version', 'advisories' => []]],
            'state' => ['v' => 1, 'bucket' => 'patch', 'updates' => [['name' => 'a', 'from' => '1.0.0', 'to' => '1.0.1']]],
        ], \is_array($plan['pullRequests']) ? $plan['pullRequests'][0] : null);
        $this->assertSame('[{"name":"b","current":"1.0.0","reason":"ignored by config","unknown":false}]', json_encode($plan['skipped']));
        $this->assertSame([
            ['action' => 'created', 'number' => null, 'url' => null, 'branch' => 'importmap-updates/patch', 'dryRun' => true],
            ['action' => 'closed', 'number' => 3, 'url' => 'u', 'branch' => 'importmap-updates/minor', 'dryRun' => true],
        ], $result->pullRequestsOutput());
    }

    private static function runResult(): RunResult
    {
        return new RunResult(
            'main',
            new Plan([Factory::pr('importmap-updates/patch', [Factory::update('a', '1.0.0', '1.0.1')])], [new SkippedPackage('b', '1.0.0', 'ignored by config')]),
            [
                new PullRequestResult('created', 'importmap-updates/patch', 'new pull request', true, title: 'Bump a from 1.0.0 to 1.0.1'),
                new PullRequestResult('closed', 'importmap-updates/minor', 'a is already at 1.1.0', true, 3, 'u'),
                new PullRequestResult('failed', 'importmap-updates/major/c', 'boom', true),
            ],
            ['importmap-updates/major/c: boom'],
        );
    }
}
