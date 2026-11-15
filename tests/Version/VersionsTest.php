<?php

namespace AssetMapperUpdate\Tests\Version;

use AssetMapperUpdate\Model\UpdateType;
use AssetMapperUpdate\Tests\Factory;
use AssetMapperUpdate\Version\Versions;
use PHPUnit\Framework\TestCase;

final class VersionsTest extends TestCase
{
    private const BOOTSTRAP = ['4.5.3', '4.6.0', '4.6.1', '4.6.2', '5.0.0-beta1', '5.0.0', '5.2.3', '5.3.0', '5.3.9', '6.0.0-alpha1'];
    private const LODASH = ['4.17.19', '4.17.20', '4.17.21', '4.17.23', '4.18.0', '4.18.1'];

    public function testCandidatesAreNewerStableVersionsSortedAndCappedAtLatest(): void
    {
        $this->assertSame(
            ['4.6.1', '4.6.2', '5.0.0', '5.2.3', '5.3.0', '5.3.9'],
            Versions::candidates('4.6.0', Factory::registry('bootstrap', self::BOOTSTRAP, '5.3.9')),
        );
        $this->assertSame(['1.1.0'], Versions::candidates('1.0.0', Factory::registry('x', ['1.0.0', '1.1.0', '2.0.0'], '1.1.0')));
    }

    public function testCandidatesIncludePrereleasesOnlyForPrereleases(): void
    {
        $this->assertContains('6.0.0-alpha1', Versions::candidates('5.0.0-beta1', Factory::registry('bootstrap', self::BOOTSTRAP, '5.3.9')));
        $this->assertNotContains('6.0.0-alpha1', Versions::candidates('5.0.0', Factory::registry('bootstrap', self::BOOTSTRAP, '5.3.9')));
    }

    public function testCandidatesIgnoreInvalidVersions(): void
    {
        $this->assertSame(['1.0.1'], Versions::candidates('1.0.0', Factory::registry('x', ['1.0.1', 'garbage'], '1.0.1')));
        $this->assertSame([], Versions::candidates('latest', Factory::registry('x', ['1.0.1'])));
    }

    public function testBucketTargets(): void
    {
        $candidates = Versions::candidates('4.6.0', Factory::registry('bootstrap', self::BOOTSTRAP, '5.3.9'));
        $this->assertSame(['patch' => '4.6.2', 'major' => '5.3.9'], Versions::bucketTargets('4.6.0', $candidates));
        $this->assertSame(
            ['patch' => '5.2.3', 'minor' => '5.3.9', 'major' => '7.0.1'],
            Versions::bucketTargets('5.2.0', ['5.2.3', '5.3.0', '5.3.9', '6.1.0', '7.0.1']),
        );
    }

    public function testPreferredBucketTargetsAvoidDeprecatedVersions(): void
    {
        // lodash 4.18.0 is a deprecated "bad release"; bootstrap deprecated its whole 4.x line.
        $this->assertEquals(
            ['patch' => '4.17.21', 'minor' => '4.18.0'],
            Versions::preferredBucketTargets('4.17.15', ['4.17.21', '4.18.0'], ['4.18.0' => true]),
        );
        $this->assertEquals(
            ['patch' => '4.17.21', 'minor' => '4.18.0'],
            Versions::preferredBucketTargets('4.17.15', ['4.17.21', '4.17.22', '4.18.0', '4.18.1'], ['4.17.22' => true, '4.18.1' => true]),
        );
        $this->assertEquals(
            ['patch' => '4.6.2', 'major' => '5.3.9'],
            Versions::preferredBucketTargets('4.6.0', ['4.6.1', '4.6.2', '5.3.9'], ['4.6.1' => true, '4.6.2' => true]),
        );
    }

    public function testUpdateTypeIsPositionalAlsoFor0x(): void
    {
        $this->assertSame(UpdateType::Minor, Versions::updateType('0.12.0', '0.13.1'));
        $this->assertSame(UpdateType::Patch, Versions::updateType('0.12.0', '0.12.1'));
        $this->assertSame(UpdateType::Major, Versions::updateType('0.12.0', '1.0.0'));
    }

    public function testIsAffected(): void
    {
        $a = Factory::advisory(vulnerableVersionRange: '>= 4.0.0, <= 4.17.23', firstPatchedVersion: '4.18.0');
        $this->assertTrue(Versions::isAffected('4.17.15', $a));
        $this->assertTrue(Versions::isAffected('4.17.23', $a));
        $this->assertFalse(Versions::isAffected('4.18.0', $a));
        $this->assertFalse(Versions::isAffected('3.10.0', $a));
    }

    public function testIsAffectedFallsBackToThePatchedVersion(): void
    {
        $a = Factory::advisory(vulnerableVersionRange: 'weird', firstPatchedVersion: '2.0.0');
        $this->assertTrue(Versions::isAffected('1.0.0', $a));
        $this->assertFalse(Versions::isAffected('2.0.0', $a));
    }

    public function testSecurityTargetFixesAllAdvisories(): void
    {
        // The real lodash audit.
        $advisories = [
            Factory::advisory('A', '>= 4.0.0, <= 4.17.23', '4.18.0'),
            Factory::advisory('B', '>= 4.0.0, <= 4.17.22', '4.17.23'),
            Factory::advisory('C', '< 4.17.21', '4.17.21'),
            Factory::advisory('D', '>= 3.7.0, < 4.17.19', '4.17.19'),
        ];
        $this->assertSame(['version' => '4.18.0', 'crossesMajor' => false], Versions::securityTarget('4.17.15', self::LODASH, $advisories));
    }

    public function testSecurityTargetWithoutPatchedVersion(): void
    {
        $a = Factory::advisory(vulnerableVersionRange: '<= 4.17.21', firstPatchedVersion: null);
        $this->assertSame(['version' => '4.17.23', 'crossesMajor' => false], Versions::securityTarget('4.17.15', self::LODASH, [$a]));
    }

    public function testSecurityTargetCrossesTheMajorWhenNeeded(): void
    {
        // The real turbo case.
        $a = Factory::advisory(vulnerableVersionRange: '<= 8.0.20', firstPatchedVersion: '8.0.21');
        $this->assertSame(
            ['version' => '8.0.21', 'crossesMajor' => true],
            Versions::securityTarget('7.3.0', ['7.3.1', '8.0.0', '8.0.20', '8.0.21', '8.0.23'], [$a]),
        );
    }

    public function testSecurityTargetAvoidsDeprecatedReleasesButPrefersTheSameMajor(): void
    {
        $a = Factory::advisory(vulnerableVersionRange: '< 4.18.0', firstPatchedVersion: '4.18.0');
        $this->assertSame(
            ['version' => '4.18.1', 'crossesMajor' => false],
            Versions::securityTarget('4.17.15', ['4.18.0', '4.18.1', '5.0.0'], [$a], ['4.18.0' => true]),
        );
        $b = Factory::advisory(vulnerableVersionRange: '< 4.6.1', firstPatchedVersion: '4.6.1');
        $this->assertSame(
            ['version' => '4.6.1', 'crossesMajor' => false],
            Versions::securityTarget('4.6.0', ['4.6.1', '4.6.2', '5.3.9'], [$b], ['4.6.1' => true, '4.6.2' => true]),
        );
    }

    public function testSecurityTargetIsNullWithoutFix(): void
    {
        $a = Factory::advisory(vulnerableVersionRange: '>= 0.0.0', firstPatchedVersion: null);
        $this->assertNull(Versions::securityTarget('1.0.0', ['1.0.1', '2.0.0'], [$a]));
    }

    public function testSecurityTargetTreatsA0xMinorAsANewMajor(): void
    {
        $advisory = Factory::advisory(vulnerableVersionRange: '< 0.9.0', firstPatchedVersion: '0.9.0');
        $this->assertSame(['version' => '0.9.0', 'crossesMajor' => true], Versions::securityTarget('0.1.0', ['0.1.5', '0.9.0', '1.0.0'], [$advisory]));
        $this->assertSame(['version' => '0.1.5', 'crossesMajor' => false], Versions::securityTarget('0.1.0', ['0.1.5', '0.9.0'], [Factory::advisory(vulnerableVersionRange: '< 0.1.5', firstPatchedVersion: '0.1.5')]));
    }
}
