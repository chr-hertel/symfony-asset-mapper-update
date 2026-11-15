<?php

namespace AssetMapperUpdate\Tests\Importmap;

use AssetMapperUpdate\Importmap\ImportmapParser;
use AssetMapperUpdate\Importmap\Packages;
use AssetMapperUpdate\Importmap\ParsedEntry;
use AssetMapperUpdate\Model\ImportmapEntry;
use AssetMapperUpdate\Model\RemotePackage;
use AssetMapperUpdate\Tests\Factory;
use PHPUnit\Framework\TestCase;

final class PackagesTest extends TestCase
{
    public function testGroupsCssEntriesWithTheirPackageAndSkipsLocalEntries(): void
    {
        $parsed = ImportmapParser::parse(Factory::fixture('importmap-multiline.php'));
        $this->assertEquals([
            new RemotePackage('bootstrap', '5.3.3', ['bootstrap', 'bootstrap/dist/css/bootstrap.min.css']),
            new RemotePackage('@popperjs/core', '2.11.8', ['@popperjs/core']),
            new RemotePackage('@fortawesome/fontawesome-free', '6.5.1', ['@fortawesome/fontawesome-free', '@fortawesome/fontawesome-free/css/fontawesome.min.css']),
            new RemotePackage('@hotwired/stimulus', '3.2.2', ['@hotwired/stimulus']),
            new RemotePackage('@hotwired/turbo', '7.3.0', ['@hotwired/turbo']),
            new RemotePackage('js-confetti', '0.12.0', ['js-confetti']),
        ], Packages::remote(array_map(static fn (ParsedEntry $e): ImportmapEntry => $e->entry, $parsed->entries)));
    }

    public function testCssOnlyPackageAndRemoteEntrypoints(): void
    {
        $this->assertEquals([
            new RemotePackage('@fortawesome/fontawesome-free', '6.0.0', ['@fortawesome/fontawesome-free/css/all.css']),
        ], Packages::remote([
            new ImportmapEntry('@fortawesome/fontawesome-free/css/all.css', version: '6.0.0', type: 'css'),
            new ImportmapEntry('thing', version: '1.0.0', entrypoint: true),
        ]));
    }

    public function testNamesAndRequireArguments(): void
    {
        $this->assertSame('@scope/pkg', Packages::nameOf('@scope/pkg/dist/x.css'));
        $this->assertSame('bootstrap', Packages::nameOf('bootstrap/dist/css/bootstrap.min.css'));
        $this->assertSame('bootstrap/dist/css/bootstrap.min.css@5.3.9', Packages::requireArgument(new ImportmapEntry('bootstrap/dist/css/bootstrap.min.css'), '5.3.9'));
        $this->assertSame('vue/dist/vue.esm-bundler.js@3.5.0=vue', Packages::requireArgument(new ImportmapEntry('vue', packageSpecifier: 'vue/dist/vue.esm-bundler.js'), '3.5.0'));
    }
}
