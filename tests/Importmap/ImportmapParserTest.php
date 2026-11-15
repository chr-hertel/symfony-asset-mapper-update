<?php

namespace AssetMapperUpdate\Tests\Importmap;

use AssetMapperUpdate\Importmap\ImportmapParseException;
use AssetMapperUpdate\Importmap\ImportmapParser;
use AssetMapperUpdate\Importmap\ParsedEntry;
use AssetMapperUpdate\Model\ImportmapEntry;
use AssetMapperUpdate\Tests\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportmapParserTest extends TestCase
{
    public function testCompactSymfonyFormat(): void
    {
        $this->assertEquals([
            new ImportmapEntry('app', path: './assets/app.js', entrypoint: true),
            new ImportmapEntry('bootstrap', version: '4.6.0'),
            new ImportmapEntry('lodash', version: '4.17.15'),
            new ImportmapEntry('@hotwired/stimulus', version: '3.0.0'),
            new ImportmapEntry('jquery', version: '3.4.1'),
            new ImportmapEntry('popper.js', version: '1.16.1'),
            new ImportmapEntry('bootstrap/dist/css/bootstrap.min.css', version: '4.6.0', type: 'css'),
        ], self::entries(ImportmapParser::parse(Factory::fixture('importmap-compact.php'))->entries));
    }

    public function testMultiLineFormatWithStrictTypes(): void
    {
        $parsed = ImportmapParser::parse(Factory::fixture('importmap-multiline.php'));
        $this->assertCount(12, $parsed->entries);
        $this->assertEquals(new ImportmapEntry('bootstrap/dist/css/bootstrap.min.css', version: '5.3.3', type: 'css'), $parsed->entries[4]->entry);
    }

    public function testLongArraySyntaxDoubleQuotesCommentsAndPackageSpecifier(): void
    {
        $parsed = ImportmapParser::parse(<<<'PHP'
            <?php
            // comment
            # another
            return array(
                "vue" => array("version" => "3.4.0", 'package_specifier' => 'vue/dist/vue.esm-bundler.js'), /* x */
                'app' => ['path' => "./assets/app.js", 'entrypoint' => TRUE],
            );
            PHP);
        $this->assertEquals([
            new ImportmapEntry('vue', version: '3.4.0', packageSpecifier: 'vue/dist/vue.esm-bundler.js'),
            new ImportmapEntry('app', path: './assets/app.js', entrypoint: true),
        ], self::entries($parsed->entries));
    }

    public function testDecodesEscapeSequences(): void
    {
        $parsed = ImportmapParser::parse(<<<'PHP'
            <?php return ["a\tb\x41\u{1F600}\101\$\"" => ['version' => 'it\'s \\ \n', 'n' => -1, 'f' => 1.5, 'x' => null]];
            PHP);
        $this->assertSame(["a\tbA😀A\$\"" => ['version' => 'it\'s \\ \n', 'n' => -1, 'f' => 1.5, 'x' => null]], $parsed->toPlainMap());
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function unsupportedFiles(): iterable
    {
        yield ['<?php return [\'a\' => [\'version\' => FOO]];', '/unsupported expression "FOO"/'];
        yield ['<?php return [\'a\' => [\'path\' => __DIR__ . \'/x.js\']];', '/unsupported/'];
        yield ['<?php return [\'a\' => [\'path\' => "$x"]];', '/interpolation/'];
        yield ['<?php return [\'a\' => [\'version\' => \'1\'] \'b\'];', '/expected "," or "]"/'];
        yield ['<?php return [\'a\' => \'1.0.0\'];', '/entry "a" must be an array/'];
        yield ['<?php $x = 1;', '/no "return" statement/'];
        yield ['return [];', '/<\?php/'];
    }

    #[DataProvider('unsupportedFiles')]
    public function testRejectsWhatItCannotEvaluate(string $php, string $message): void
    {
        $this->expectException(ImportmapParseException::class);
        $this->expectExceptionMessageMatches($message);
        ImportmapParser::parse($php);
    }

    /**
     * @param list<ParsedEntry> $entries
     *
     * @return list<ImportmapEntry>
     */
    private static function entries(array $entries): array
    {
        return array_map(static fn (ParsedEntry $e): ImportmapEntry => $e->entry, $entries);
    }
}
