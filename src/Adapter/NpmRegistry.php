<?php

namespace AssetMapperUpdate\Adapter;

use AssetMapperUpdate\Model\PackageLinks;
use AssetMapperUpdate\Model\RegistryPackage;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class NpmRegistry
{
    private const REGISTRY = 'https://registry.npmjs.org';

    private readonly HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->http = $http ?? new RetryableHttpClient(HttpClient::create(['timeout' => 30, 'headers' => ['User-Agent' => 'asset-mapper-update']]));
    }

    /**
     * Loads several packages concurrently.
     *
     * @param list<string> $names
     *
     * @return array<string, RegistryPackage|\RuntimeException> the package, or why it could not be loaded
     */
    public function packages(array $names): array
    {
        $responses = [];
        foreach ($names as $name) {
            // The abbreviated metadata is small and has the versions, dist-tags and deprecation notices.
            $responses[$name] = $this->http->request('GET', self::packageUrl($name), ['headers' => ['Accept' => 'application/vnd.npm.install-v1+json']]);
        }

        $out = [];
        foreach ($responses as $name => $response) {
            try {
                $out[$name] = self::parsePackument($name, self::decode($response));
            } catch (\RuntimeException $e) {
                $out[$name] = $e;
            }
        }

        return $out;
    }

    public function package(string $name): RegistryPackage
    {
        $result = $this->packages([$name])[$name];

        return $result instanceof RegistryPackage ? $result : throw $result;
    }

    /**
     * Links for the PR body. They are optional: failures are ignored.
     *
     * @param list<array{string, string}> $packages name and version
     *
     * @return array<string, PackageLinks> by "name@version"
     */
    public function links(array $packages): array
    {
        $responses = [];
        foreach ($packages as [$name, $version]) {
            $responses[$name.'@'.$version] = [$name, $version, $this->http->request('GET', self::packageUrl($name).'/'.rawurlencode($version), ['headers' => ['Accept' => 'application/json']])];
        }

        $out = [];
        foreach ($responses as $key => [$name, $version, $response]) {
            $npm = \sprintf('https://www.npmjs.com/package/%s/v/%s', $name, $version);
            $repository = null;
            try {
                $json = self::decode($response);
                $repository = self::repositoryUrl($json['repository'] ?? null);
            } catch (\RuntimeException) {
            }
            $out[$key] = new PackageLinks($npm, $repository, null !== $repository && str_starts_with($repository, 'https://github.com/') ? $repository.'/releases' : null);
        }

        return $out;
    }

    public static function parsePackument(string $name, mixed $json): RegistryPackage
    {
        if (!\is_array($json) || !\is_array($json['versions'] ?? null)) {
            throw new \RuntimeException($name.': unexpected registry response');
        }
        $versions = array_map('strval', array_keys($json['versions']));
        $deprecated = [];
        foreach ($json['versions'] as $version => $meta) {
            if (\is_array($meta) && \is_string($meta['deprecated'] ?? null) && '' !== $meta['deprecated']) {
                $deprecated[] = (string) $version;
            }
        }
        $latest = $json['dist-tags']['latest'] ?? null;

        return new RegistryPackage($name, $versions, $deprecated, \is_string($latest) ? $latest : ($versions[\count($versions) - 1] ?? ''));
    }

    /**
     * Turns the many shapes of the package.json `repository` field into a browsable https URL.
     */
    public static function repositoryUrl(mixed $repository): ?string
    {
        $raw = \is_array($repository) ? ($repository['url'] ?? null) : $repository;
        if (!\is_string($raw) || '' === trim($raw)) {
            return null;
        }
        $url = trim($raw);
        if (1 === preg_match('/^(github|gitlab|bitbucket):([\w.-]+\/[\w.-]+)$/', $url, $m)) {
            return \sprintf('https://%s/%s', 'bitbucket' === $m[1] ? 'bitbucket.org' : $m[1].'.com', $m[2]);
        }
        if (1 === preg_match('/^[\w.-]+\/[\w.-]+$/', $url)) {
            return 'https://github.com/'.$url;
        }
        $url = (string) preg_replace(
            ['/^git\+/', '/^git:\/\//', '/^ssh:\/\/git@/', '/^git@([^:]+):/', '/\.git$/', '/^http:\/\//'],
            ['', 'https://', 'https://', 'https://$1/', '', 'https://'],
            $url,
        );

        return str_starts_with($url, 'https://') ? $url : null;
    }

    private static function packageUrl(string $name): string
    {
        return self::REGISTRY.'/'.(str_starts_with($name, '@') ? str_replace('/', '%2F', $name) : $name);
    }

    private static function decode(ResponseInterface $response): mixed
    {
        try {
            $status = $response->getStatusCode();
            $url = $response->getInfo('url');
            if (404 === $status) {
                throw new \RuntimeException($url.': not found');
            }
            if ($status >= 400) {
                throw new \RuntimeException(\sprintf('%s: HTTP %d', $url, $status));
            }

            return json_decode($response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (ExceptionInterface|\JsonException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
