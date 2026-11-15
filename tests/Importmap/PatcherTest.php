<?php

namespace AssetMapperUpdate\Tests\Importmap;

use AssetMapperUpdate\Importmap\ImportmapParser;
use AssetMapperUpdate\Importmap\LineDiff;
use AssetMapperUpdate\Importmap\Patcher;
use AssetMapperUpdate\Importmap\PatchException;
use AssetMapperUpdate\Tests\Factory;
use PHPUnit\Framework\TestCase;

final class PatcherTest extends TestCase
{
    private const BOOTSTRAP_5_3_9 = ['bootstrap' => '5.3.9', 'bootstrap/dist/css/bootstrap.min.css' => '5.3.9'];

    public function testReplaysTheRealRequireResultAndDropsSideEffects(): void
    {
        $changes = Patcher::resolveChanges(
            self::map('importmap-compact.php'),
            self::map('importmap-compact-after-require-bootstrap-4.6.2.php'),
            ['bootstrap' => '4.6.2', 'bootstrap/dist/css/bootstrap.min.css' => '4.6.2'],
        );
        $this->assertSame(['bootstrap' => '4.6.2', 'bootstrap/dist/css/bootstrap.min.css' => '4.6.2'], $changes->versionChanges);
        $this->assertSame([], $changes->additions);
        $this->assertSame(['jquery: 3.4.1 → 3.6.0'], $changes->ignoredSideEffects);
    }

    public function testCollectsNewDependenciesAsAdditions(): void
    {
        // bootstrap 4 → 5 adds @popperjs/core
        $changes = Patcher::resolveChanges(self::map('importmap-compact.php'), self::map('importmap-compact-after-update-bootstrap.php'), self::BOOTSTRAP_5_3_9);
        $this->assertSame(['@popperjs/core' => ['version' => '2.11.8']], $changes->additions);
    }

    public function testFailsWhenRequireResolvedADifferentVersion(): void
    {
        $this->expectExceptionMessage('resolved "a" to 1.0.1 instead of 1.0.2');
        Patcher::resolveChanges(['a' => ['version' => '1.0.0', 'type' => 'css']], ['a' => ['version' => '1.0.1', 'type' => 'css']], ['a' => '1.0.2']);
    }

    public function testFailsWhenRequireChangedOtherAttributes(): void
    {
        $this->expectExceptionMessage('more than the version');
        Patcher::resolveChanges(['a' => ['version' => '1.0.0', 'type' => 'css']], ['a' => ['version' => '1.0.2']], ['a' => '1.0.2']);
    }

    public function testFailsWhenRequireRemovedAnEntry(): void
    {
        $this->expectExceptionMessage('removed "a"');
        Patcher::resolveChanges(['a' => ['version' => '1.0.0']], [], ['a' => '1.0.2']);
    }

    public function testAttributeOrderDoesNotMatter(): void
    {
        $changes = Patcher::resolveChanges(['a' => ['version' => '1.0.0', 'type' => 'css']], ['a' => ['type' => 'css', 'version' => '1.0.2']], ['a' => '1.0.2']);
        $this->assertSame(['a' => '1.0.2'], $changes->versionChanges);
    }

    public function testOnlyTouchesVersionStringsInTheMultiLineFormat(): void
    {
        $before = Factory::fixture('importmap-multiline.php');
        $after = Patcher::patch($before, self::BOOTSTRAP_5_3_9);
        Patcher::verify($before, $after, self::BOOTSTRAP_5_3_9, []);
        $this->assertStringContainsString('declare(strict_types=1);', $after);
        $this->assertSame($before, str_replace('5.3.9', '5.3.3', $after));
        $this->assertSame(['removed' => [26, 32], 'added' => [26, 32]], LineDiff::changedLines($before, $after));
    }

    public function testAppendsNewEntriesInMultiLineStyle(): void
    {
        $before = Factory::fixture('importmap-multiline.php');
        $changes = ['@hotwired/turbo' => '8.0.23'];
        $additions = ['@hotwired/new-dep' => ['version' => '1.0.0']];
        $after = Patcher::patch($before, $changes, $additions);
        Patcher::verify($before, $after, $changes, $additions);
        $this->assertStringContainsString(<<<'PHP'
                'js-confetti' => [
                    'version' => '0.12.0',
                ],
                '@hotwired/new-dep' => [
                    'version' => '1.0.0',
                ],
            ];
            PHP, $after);
    }

    public function testAppendsNewEntriesInCompactStyle(): void
    {
        $before = Factory::fixture('importmap-compact.php');
        $additions = ['@popperjs/core' => ['version' => '2.11.8']];
        $after = Patcher::patch($before, self::BOOTSTRAP_5_3_9, $additions);
        Patcher::verify($before, $after, self::BOOTSTRAP_5_3_9, $additions);
        $this->assertStringContainsString(<<<'PHP'
                'bootstrap/dist/css/bootstrap.min.css' => ['version' => '5.3.9', 'type' => 'css'],
                '@popperjs/core' => ['version' => '2.11.8'],
            ];
            PHP, $after);
    }

    public function testMissingTrailingCommaAndDoubleQuotes(): void
    {
        $before = "<?php\nreturn [\n    \"a\" => [\"version\" => \"1.0.0\"]\n];\n";
        $changes = ['a' => '1.1.0'];
        $additions = ['b' => ['version' => '2.0.0', 'type' => 'css']];
        $after = Patcher::patch($before, $changes, $additions);
        Patcher::verify($before, $after, $changes, $additions);
        $this->assertSame("<?php\nreturn [\n    \"a\" => [\"version\" => \"1.1.0\"],\n    \"b\" => [\"version\" => \"2.0.0\", \"type\" => \"css\"]\n];\n", $after);
    }

    public function testEmptyImportmap(): void
    {
        $additions = ['a' => ['version' => '1.0.0']];
        $after = Patcher::patch("<?php\n\nreturn [];\n", [], $additions);
        Patcher::verify("<?php\n\nreturn [];\n", $after, [], $additions);
        $this->assertSame("<?php\n\nreturn [\n    'a' => ['version' => '1.0.0'],\n];\n", $after);
    }

    public function testVerifyRejectsDiffsThatTouchUnrelatedLines(): void
    {
        $before = Factory::fixture('importmap-multiline.php');
        $changes = ['bootstrap' => '5.3.9'];
        $after = str_replace('declare(strict_types=1);', '', Patcher::patch($before, $changes));
        $this->expectException(PatchException::class);
        $this->expectExceptionMessage('unrelated lines');
        Patcher::verify($before, $after, $changes, []);
    }

    public function testVerifyRejectsSemanticChangesToOtherEntries(): void
    {
        $before = Factory::fixture('importmap-compact.php');
        $after = Patcher::patch($before, ['bootstrap' => '5.3.9', 'jquery' => '9.9.9']);
        $this->expectExceptionMessage('expected entries');
        Patcher::verify($before, $after, ['bootstrap' => '5.3.9'], []);
    }

    public function testVerifyRejectsTheFullFileRewriteOfRequire(): void
    {
        $this->expectException(PatchException::class);
        Patcher::verify(Factory::fixture('importmap-multiline.php'), Factory::fixture('importmap-multiline-after-require.php'), ['bootstrap' => '5.3.8'], []);
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    private static function map(string $fixture): array
    {
        return ImportmapParser::parse(Factory::fixture($fixture))->toPlainMap();
    }
}
