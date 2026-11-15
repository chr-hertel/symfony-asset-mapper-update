<?php

namespace AssetMapperUpdate\Tests\Adapter;

use AssetMapperUpdate\Adapter\CommandResult;
use AssetMapperUpdate\Adapter\CommandRunner;
use AssetMapperUpdate\Adapter\ConsoleException;
use AssetMapperUpdate\Adapter\SymfonyConsole;
use AssetMapperUpdate\Model\Advisory;
use AssetMapperUpdate\Model\AuditedPackage;
use AssetMapperUpdate\Model\OutdatedPackage;
use AssetMapperUpdate\Model\Severity;
use AssetMapperUpdate\Tests\Factory;
use PHPUnit\Framework\TestCase;

final class SymfonyConsoleTest extends TestCase
{
    public function testParsesTheRealOutdatedOutput(): void
    {
        $this->assertContainsEquals(
            new OutdatedPackage('bootstrap/dist/css/bootstrap.min.css', '4.6.0', '5.3.9', 'update-possible'),
            SymfonyConsole::parseOutdated(SymfonyConsole::extractJson(Factory::fixture('outdated.json'))),
        );
    }

    public function testParsesTheRealAuditOutputAndLinksToTheAdvisoryPage(): void
    {
        $audit = SymfonyConsole::parseAudit(SymfonyConsole::extractJson(Factory::fixture('audit-app.json')));
        $turbo = array_values(array_filter($audit, static fn (AuditedPackage $p): bool => '@hotwired/turbo' === $p->name))[0];
        $this->assertEquals(new AuditedPackage('@hotwired/turbo', '7.3.0', [new Advisory(
            ghsaId: 'GHSA-qppm-g56g-fpvp',
            cveId: 'CVE-2025-66803',
            severity: Severity::Low,
            summary: 'Turbo Frame responses can restore stale session cookies',
            url: 'https://github.com/advisories/GHSA-qppm-g56g-fpvp',
            vulnerableVersionRange: '<= 8.0.20',
            firstPatchedVersion: '8.0.21',
        )]), $turbo);

        $lodash = array_values(array_filter(SymfonyConsole::parseAudit(SymfonyConsole::extractJson(Factory::fixture('audit.json'))), static fn (AuditedPackage $p): bool => 'lodash' === $p->name))[0];
        $this->assertNotEmpty(array_filter($lodash->advisories, static fn (Advisory $a): bool => null === $a->firstPatchedVersion));
    }

    public function testExtractsJsonSurroundedByWarnings(): void
    {
        $this->assertSame([['name' => 'x']], SymfonyConsole::extractJson("PHP Deprecated: foo\n[{\"name\":\"x\"}]\n"));
        $this->expectExceptionMessage('no JSON');
        SymfonyConsole::extractJson('Something broke');
    }

    public function testIgnoresTheExitCodeOfOutdatedButFailsOnInvalidOutput(): void
    {
        $runner = new class implements CommandRunner {
            /** @var list<string> */
            public array $calls = [];

            public function run(array|string $command, string $cwd, array $env = []): CommandResult
            {
                \assert(\is_string($command));
                $this->calls[] = $command;

                return match (true) {
                    str_contains($command, 'importmap:outdated') => new CommandResult(1, '[]', ''),
                    str_contains($command, 'importmap:require') => new CommandResult(1, '', 'boom'),
                    default => new CommandResult(255, 'Fatal error', ''),
                };
            }
        };
        $console = new SymfonyConsole('php bin/console', '/app', $runner);

        $this->assertSame([], $console->outdated());
        try {
            $console->audit();
            $this->fail('Expected an exception');
        } catch (ConsoleException $e) {
            $this->assertMatchesRegularExpression('/importmap:audit.*--format=json.*failed \(exit code 255\)/s', $e->getMessage());
        }
        try {
            $console->require(['a@1.0.0', 'it\'s']);
            $this->fail('Expected an exception');
        } catch (ConsoleException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->assertSame("php bin/console 'importmap:require' 'a@1.0.0' 'it'\\''s' '--no-interaction' '--no-ansi'", $runner->calls[2]);
    }
}
