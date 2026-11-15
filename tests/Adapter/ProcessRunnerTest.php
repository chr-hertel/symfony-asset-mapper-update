<?php

namespace AssetMapperUpdate\Tests\Adapter;

use AssetMapperUpdate\Adapter\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class ProcessRunnerTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('GITHUB_TOKEN');
        unset($_ENV['GITHUB_TOKEN'], $_SERVER['GITHUB_TOKEN']);
    }

    public function testChildProcessesDoNotGetTheToken(): void
    {
        putenv('GITHUB_TOKEN=secret');
        $_ENV['GITHUB_TOKEN'] = $_SERVER['GITHUB_TOKEN'] = 'secret';
        $runner = new ProcessRunner();
        $print = 'echo getenv("GITHUB_TOKEN") === false ? "unset" : "set", getenv("OTHER") ?: "";';

        $this->assertSame('unset', $runner->run('php -r '.escapeshellarg($print), sys_get_temp_dir())->stdout);
        $this->assertSame('unsetvalue', $runner->run(['php', '-r', $print], sys_get_temp_dir(), ['OTHER' => 'value'])->stdout);
    }
}
