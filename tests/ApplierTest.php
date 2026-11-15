<?php

namespace AssetMapperUpdate\Tests;

use AssetMapperUpdate\Adapter\CommandResult;
use AssetMapperUpdate\Adapter\CommandRunner;
use AssetMapperUpdate\Adapter\Git;
use AssetMapperUpdate\Adapter\SymfonyConsole;
use AssetMapperUpdate\Applier;
use AssetMapperUpdate\Importmap\PatchException;
use AssetMapperUpdate\Model\Bucket;
use AssetMapperUpdate\Model\UpdateType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs against a real git repository; `importmap:require` is simulated with the real output from the spike.
 */
final class ApplierTest extends TestCase
{
    private string $dir;
    private Git $git;
    /** @var CommandRunner&object{calls: list<string>} */
    private object $runner;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/asset-mapper-update-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/importmap.php', Factory::fixture('importmap-compact.php'));
        file_put_contents($this->dir.'/symfony.lock', "{}\n");
        $this->git = new Git($this->dir);
        $this->git->run(['init', '--quiet']);
        $this->git->run(['add', '--all']);
        $this->git->run(['-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'init']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testReplaysOnlyThePlannedChangesAndRestoresWhatRequireTouched(): void
    {
        $console = $this->console('importmap-compact-after-update-bootstrap.php');
        $pr = Factory::pr('importmap-updates/major/bootstrap', [Factory::update('bootstrap', '4.6.0', '5.3.9', Bucket::Major, UpdateType::Major, ['bootstrap', 'bootstrap/dist/css/bootstrap.min.css'])]);

        $details = (new Applier($console, $this->git))->apply($pr, $this->dir.'/importmap.php');

        $this->assertSame(["php bin/console 'importmap:require' 'bootstrap@5.3.9' 'bootstrap/dist/css/bootstrap.min.css@5.3.9' '--no-interaction' '--no-ansi'"], $this->runner->calls);
        $this->assertSame(['@popperjs/core' => ['version' => '2.11.8']], $details->additions);
        $this->assertSame("{}\n", file_get_contents($this->dir.'/symfony.lock'), 'files require wrote to are restored');
        $diff = $this->git->run(['diff', '--unified=0', '--', 'importmap.php']);
        $this->assertStringContainsString("-    'bootstrap' => ['version' => '4.6.0'],\n+    'bootstrap' => ['version' => '5.3.9'],", $diff);
        $this->assertStringContainsString("+    '@popperjs/core' => ['version' => '2.11.8'],", $diff);
        $this->assertStringNotContainsString('jquery', $diff);
    }

    public function testFailsAndRestoresWhenRequireResolvedAnotherVersion(): void
    {
        $console = $this->console('importmap-compact-after-require-bootstrap-4.6.2.php');
        $pr = Factory::pr('importmap-updates/patch', [Factory::update('bootstrap', '4.6.0', '4.6.1', entries: ['bootstrap', 'bootstrap/dist/css/bootstrap.min.css'])]);

        try {
            (new Applier($console, $this->git))->apply($pr, $this->dir.'/importmap.php');
            $this->fail('Expected an exception');
        } catch (PatchException $e) {
            $this->assertStringContainsString('resolved "bootstrap" to 4.6.2 instead of 4.6.1', $e->getMessage());
        }
        $this->assertSame([], $this->git->modifiedFiles());
    }

    /**
     * A console whose `importmap:require` writes the given fixture, like the real command does, and also touches symfony.lock.
     */
    private function console(string $fixture): SymfonyConsole
    {
        $runner = new class($this->dir, Factory::fixture($fixture)) implements CommandRunner {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private string $dir, private string $result)
            {
            }

            public function run(array|string $command, string $cwd, array $env = []): CommandResult
            {
                \assert(\is_string($command));
                $this->calls[] = $command;
                file_put_contents($this->dir.'/importmap.php', $this->result);
                file_put_contents($this->dir.'/symfony.lock', "{\"changed\": true}\n");

                return new CommandResult(0, '', '');
            }
        };
        $this->runner = $runner;

        return new SymfonyConsole('php bin/console', $this->dir, $runner);
    }
}
