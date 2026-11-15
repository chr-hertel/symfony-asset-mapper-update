<?php

namespace AssetMapperUpdate\Tests;

use AssetMapperUpdate\Log;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class LogTest extends TestCase
{
    public function testUsesWorkflowCommandsOnGitHubActions(): void
    {
        $output = new BufferedOutput();
        $log = new Log($output, true);
        $log->group('Plan');
        $log->info('<b>text</b>');
        $log->warning("100% sure\nsecond line");
        $log->error('bad');
        $log->mask('s3cr3t');
        $log->endGroup();

        $this->assertSame("::group::Plan\n<b>text</b>\n::warning::100%25 sure%0Asecond line\n::error::bad\n::add-mask::s3cr3t\n::endgroup::\n", $output->fetch());
    }

    public function testPlainOutputElsewhere(): void
    {
        $output = new BufferedOutput();
        $log = new Log($output, false);
        $log->group('Plan');
        $log->warning('careful');
        $log->mask('s3cr3t');
        $log->endGroup();

        $this->assertSame("\nPlan\nWarning: careful\n", $output->fetch());
    }
}
