<?php

namespace AssetMapperUpdate\Tests\Config;

use AssetMapperUpdate\Config\Config;
use AssetMapperUpdate\Config\ConfigException;
use AssetMapperUpdate\Config\ConfigParser;
use AssetMapperUpdate\Config\IgnoreRule;
use AssetMapperUpdate\Model\Strategy;
use AssetMapperUpdate\Model\UpdateType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigParserTest extends TestCase
{
    public function testDefaultsForAnEmptyFile(): void
    {
        $this->assertEquals(new Config(), ConfigParser::parse(''));
        $this->assertEquals(new Config(), ConfigParser::parse('version: 1'));
    }

    public function testFullExample(): void
    {
        $config = ConfigParser::parse(<<<'YAML'
            version: 1
            grouping:
              security: { strategy: individual }
              patch:    { strategy: grouped }
              minor:    { strategy: grouped }
              major:    { strategy: skip }
            ignore:
              - name: bootstrap
                update-types: [major]
              - name: some-package
            open_pull_requests_limit: 3
            labels: [dependencies, javascript, importmap]
            branch_prefix: deps/importmap
            commit_message_prefix: "chore(deps):"
            reviewers: [octocat]
            assignees: [hubot]
            YAML);

        $this->assertEquals(new Config(
            grouping: ['security' => Strategy::Individual, 'patch' => Strategy::Grouped, 'minor' => Strategy::Grouped, 'major' => Strategy::Skip],
            ignore: [new IgnoreRule('bootstrap', [UpdateType::Major]), new IgnoreRule('some-package')],
            openPullRequestsLimit: 3,
            labels: ['dependencies', 'javascript', 'importmap'],
            branchPrefix: 'deps/importmap',
            commitMessagePrefix: 'chore(deps):',
            reviewers: ['octocat'],
            assignees: ['hubot'],
        ), $config);
    }

    public function testKeepsDefaultsForBucketsThatAreNotConfigured(): void
    {
        $this->assertEquals(
            ['security' => Strategy::Individual, 'patch' => Strategy::Grouped, 'minor' => Strategy::Grouped, 'major' => Strategy::Grouped],
            ConfigParser::parse('grouping: { major: { strategy: grouped } }')->grouping,
        );
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield ['foo: bar', '/unknown key "foo"/'];
        yield ['version: 2', '/"version" must be 1/'];
        yield ['grouping: { mayor: { strategy: grouped } }', '/unknown key "grouping.mayor"/'];
        yield ['grouping: { major: { strategy: together } }', '/"grouping.major.strategy" must be one of/'];
        yield ['grouping: { major: { strategy: grouped, x: 1 } }', '/unknown key "grouping.major.x"/'];
        yield ['grouping: { major: grouped }', '/must be a mapping like/'];
        yield ['ignore: { name: x }', '/"ignore" must be a list/'];
        yield ['ignore: [ { name: x, update-types: [huge] } ]', '/contains "huge"/'];
        yield ['ignore: [ { name: x, versions: ["1.x"] } ]', '/unknown key "ignore\[0\].versions"/'];
        yield ['ignore: [ { update-types: [major] } ]', '/"ignore\[0\].name" must be a non-empty string/'];
        yield ['ignore: [ { name: x, update-types: [] } ]', '/must be a non-empty list/'];
        yield ['open_pull_requests_limit: -1', '/must be an integer >= 0/'];
        yield ['open_pull_requests_limit: "5"', '/must be an integer >= 0/'];
        yield ['labels: dependencies', '/"labels" must be a list/'];
        yield ['branch_prefix: "a b"', '/"branch_prefix" must be a valid branch name/'];
        yield ['branch_prefix: a..b', '/"branch_prefix" must be a valid branch name/'];
        yield ['branch_prefix: deps/x.lock', '/"branch_prefix" must be a valid branch name/'];
        yield ['branch_prefix: .hidden', '/"branch_prefix" must be a valid branch name/'];
        yield ['branch_prefix: a/.b', '/"branch_prefix" must be a valid branch name/'];
        yield ['branch_prefix: -x', '/"branch_prefix" must be a valid branch name/'];
        yield ["- a\n- b", '/expected a mapping/'];
        yield ['a: [', '/not valid YAML/'];
    }

    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfig(string $yaml, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches($message);
        ConfigParser::parse($yaml, '.github/importmap-updates.yml');
    }

    public function testMentionsTheFileInErrors(): void
    {
        $this->expectExceptionMessage('Invalid config .github/importmap-updates.yml: unknown key "foo"');
        ConfigParser::parse('foo: 1', '.github/importmap-updates.yml');
    }

    public function testIgnoreRulesMatchNamesAndWildcards(): void
    {
        $this->assertTrue((new IgnoreRule('bootstrap'))->matches('bootstrap'));
        $this->assertFalse((new IgnoreRule('bootstrap'))->matches('bootstrap-icons'));
        $this->assertTrue((new IgnoreRule('@fortawesome/*'))->matches('@fortawesome/fontawesome-free'));
        $this->assertFalse((new IgnoreRule('@hotwired/*'))->matches('@fortawesome/fontawesome-free'));
        $this->assertTrue((new IgnoreRule('*'))->matches('anything'));
    }
}
