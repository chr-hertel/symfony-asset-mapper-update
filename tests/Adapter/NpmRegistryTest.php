<?php

namespace AssetMapperUpdate\Tests\Adapter;

use AssetMapperUpdate\Adapter\NpmRegistry;
use AssetMapperUpdate\Model\PackageLinks;
use AssetMapperUpdate\Model\RegistryPackage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NpmRegistryTest extends TestCase
{
    public function testKeepsAllVersionsAndListsTheDeprecatedOnes(): void
    {
        $this->assertEquals(
            new RegistryPackage('lodash', ['4.17.21', '4.18.0', '4.18.1'], ['4.18.0'], '4.18.1'),
            NpmRegistry::parsePackument('lodash', [
                'dist-tags' => ['latest' => '4.18.1'],
                'versions' => ['4.17.21' => [], '4.18.0' => ['deprecated' => 'Bad release'], '4.18.1' => []],
            ]),
        );
    }

    /**
     * @return iterable<array{mixed, string|null}>
     */
    public static function repositories(): iterable
    {
        yield ['git+https://github.com/twbs/bootstrap.git', 'https://github.com/twbs/bootstrap'];
        yield [['type' => 'git', 'url' => 'git://github.com/hotwired/turbo.git'], 'https://github.com/hotwired/turbo'];
        yield ['git@github.com:lodash/lodash.git', 'https://github.com/lodash/lodash'];
        yield ['github:jquery/jquery', 'https://github.com/jquery/jquery'];
        yield ['loilo/js-confetti', 'https://github.com/loilo/js-confetti'];
        yield ['ssh://git@gitlab.com/a/b.git', 'https://gitlab.com/a/b'];
        yield [null, null];
        yield ['file:../local', null];
    }

    #[DataProvider('repositories')]
    public function testNormalizesRepositoryUrls(mixed $repository, ?string $expected): void
    {
        $this->assertSame($expected, NpmRegistry::repositoryUrl($repository));
    }

    public function testLoadsPackagesConcurrentlyAndReportsFailures(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return str_contains($url, 'missing')
                ? new MockResponse('', ['http_code' => 404])
                : new MockResponse('{"dist-tags":{"latest":"1.0.0"},"versions":{"1.0.0":{}}}');
        });
        $result = (new NpmRegistry($http))->packages(['@scope/pkg', 'missing']);

        $this->assertEquals(new RegistryPackage('@scope/pkg', ['1.0.0'], [], '1.0.0'), $result['@scope/pkg']);
        $this->assertInstanceOf(\RuntimeException::class, $result['missing']);
        $this->assertStringContainsString('not found', $result['missing']->getMessage());
        $this->assertSame(['https://registry.npmjs.org/@scope%2Fpkg', 'https://registry.npmjs.org/missing'], $urls);
    }

    public function testBuildsLinksAndSurvivesFailures(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_contains($url, 'bootstrap')
            ? new MockResponse('{"repository":{"url":"git+https://github.com/twbs/bootstrap.git"}}')
            : new MockResponse('', ['http_code' => 500]));
        $this->assertEquals([
            'bootstrap@5.3.9' => new PackageLinks('https://www.npmjs.com/package/bootstrap/v/5.3.9', 'https://github.com/twbs/bootstrap', 'https://github.com/twbs/bootstrap/releases'),
            '@scope/x@1.0.0' => new PackageLinks('https://www.npmjs.com/package/@scope/x/v/1.0.0'),
        ], (new NpmRegistry($http))->links([['bootstrap', '5.3.9'], ['@scope/x', '1.0.0']]));
    }
}
