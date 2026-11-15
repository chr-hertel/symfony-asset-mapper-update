<?php

namespace AssetMapperUpdate\Tests\Version;

use AssetMapperUpdate\Version\SemVer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SemVerTest extends TestCase
{
    public function testParse(): void
    {
        $this->assertSame([5, 0, 0, ['beta1']], SemVer::parse('5.0.0-beta1'));
        $this->assertSame([1, 2, 3, []], SemVer::parse('v1.2.3+build.5'));
        $this->assertNull(SemVer::parse('1.2'));
        $this->assertNull(SemVer::parse('01.2.3'));
        $this->assertNull(SemVer::parse('latest'));
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function orderedPairs(): iterable
    {
        yield ['1.0.0', '1.0.1'];
        yield ['1.9.0', '1.10.0'];
        yield ['1.0.0-alpha', '1.0.0'];
        yield ['1.0.0-alpha', '1.0.0-alpha.1'];
        yield ['1.0.0-alpha.1', '1.0.0-alpha.beta'];
        yield ['1.0.0-beta.2', '1.0.0-beta.11'];
        yield ['1.0.0-rc.1', '1.0.0'];
        yield ['5.0.0-beta1', '5.0.0'];
    }

    #[DataProvider('orderedPairs')]
    public function testCompare(string $lower, string $higher): void
    {
        $this->assertSame(-1, SemVer::compare($lower, $higher));
        $this->assertSame(1, SemVer::compare($higher, $lower));
        $this->assertSame(0, SemVer::compare($lower, $lower));
    }

    public function testSort(): void
    {
        $this->assertSame(['1.0.0-beta', '1.0.0', '1.2.0', '10.0.0'], SemVer::sort(['10.0.0', '1.0.0', '1.2.0', '1.0.0-beta']));
    }

    public function testRanges(): void
    {
        $range = SemVer::parseRange('>= 4.0.0, <= 4.17.23');
        $this->assertSame([[['>=', '4.0.0'], ['<=', '4.17.23']]], $range);
        $this->assertTrue(SemVer::satisfies('4.17.23', $range));
        $this->assertFalse(SemVer::satisfies('4.18.0', $range));

        $alternatives = SemVer::parseRange('< 1.0.0 || = 2.0.0');
        $this->assertNotNull($alternatives);
        $this->assertTrue(SemVer::satisfies('2.0.0', $alternatives));
        $this->assertFalse(SemVer::satisfies('1.5.0', $alternatives));

        $this->assertNull(SemVer::parseRange('weird'));
        $this->assertNull(SemVer::parseRange(''));
    }
}
