<?php

namespace AssetMapperUpdate\Adapter;

use AssetMapperUpdate\Model\Advisory;
use AssetMapperUpdate\Model\AuditedPackage;
use AssetMapperUpdate\Model\OutdatedPackage;
use AssetMapperUpdate\Model\Severity;

/**
 * Runs the importmap commands of the application's Symfony console.
 */
final class SymfonyConsole
{
    /**
     * @param string $command shell command line, e.g. `php bin/console` or `docker compose exec -T php bin/console`
     */
    public function __construct(
        private readonly string $command,
        private readonly string $cwd,
        private readonly CommandRunner $runner = new ProcessRunner(),
    ) {
    }

    /**
     * `importmap:outdated` exits with 1 when packages are outdated, so the exit code is not used.
     *
     * @return list<OutdatedPackage>
     */
    public function outdated(): array
    {
        return self::parseOutdated($this->json(['importmap:outdated', '--format=json']));
    }

    /**
     * `importmap:audit` exits with 1 when vulnerabilities are found, so the exit code is not used.
     *
     * @return list<AuditedPackage>
     */
    public function audit(): array
    {
        return self::parseAudit($this->json(['importmap:audit', '--format=json']));
    }

    /**
     * @param list<string> $packages
     */
    public function require(array $packages): void
    {
        $args = ['importmap:require', ...$packages, '--no-interaction', '--no-ansi'];
        $commandLine = $this->commandLine($args);
        $result = $this->runner->run($commandLine, $this->cwd);
        if (0 !== $result->exitCode) {
            throw new ConsoleException($commandLine, $result);
        }
    }

    /**
     * Extracts the JSON document from output that may have warnings around it.
     */
    public static function extractJson(string $stdout): mixed
    {
        $trimmed = trim($stdout);
        try {
            return json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $start = strcspn($trimmed, '[{');
            $end = max((int) strrpos($trimmed, ']'), (int) strrpos($trimmed, '}'));
            if ($start >= \strlen($trimmed) || $end < $start) {
                throw new \UnexpectedValueException('no JSON found in the output');
            }
            try {
                return json_decode(substr($trimmed, $start, $end - $start + 1), true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \UnexpectedValueException('no JSON found in the output: '.$e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * @return list<OutdatedPackage>
     */
    public static function parseOutdated(mixed $json): array
    {
        if (!\is_array($json) || !array_is_list($json)) {
            throw new \UnexpectedValueException('expected a JSON array');
        }
        $out = [];
        foreach ($json as $row) {
            if (\is_array($row)) {
                $out[] = new OutdatedPackage($row['name'] ?? '', $row['current'] ?? '', $row['latest'] ?? '', $row['latest-status'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @return list<AuditedPackage>
     */
    public static function parseAudit(mixed $json): array
    {
        if (!\is_array($json) || !\is_array($json['packages'] ?? null)) {
            throw new \UnexpectedValueException('expected {"packages": [...]}');
        }
        $out = [];
        foreach ($json['packages'] as $package) {
            if (!\is_array($package)) {
                continue;
            }
            $advisories = [];
            foreach ($package['vulnerabilities'] ?? [] as $v) {
                if (!\is_array($v)) {
                    continue;
                }
                $ghsaId = $v['ghsa_id'] ?? 'unknown';
                $advisories[] = new Advisory(
                    ghsaId: $ghsaId,
                    cveId: $v['cve_id'] ?? null,
                    severity: Severity::fromAudit($v['severity'] ?? null),
                    summary: $v['summary'] ?? '',
                    // The command returns the API URL; link to the human readable page instead.
                    url: str_starts_with($ghsaId, 'GHSA-') ? 'https://github.com/advisories/'.$ghsaId : ($v['url'] ?? ''),
                    vulnerableVersionRange: $v['vulnerable_version_range'] ?? '',
                    firstPatchedVersion: $v['first_patched_version'] ?? null,
                );
            }
            $out[] = new AuditedPackage($package['package'] ?? '', $package['version'] ?? '', $advisories);
        }

        return $out;
    }

    /**
     * @param list<string> $args
     */
    private function json(array $args): mixed
    {
        $commandLine = $this->commandLine([...$args, '--no-interaction', '--no-ansi']);
        $result = $this->runner->run($commandLine, $this->cwd);
        try {
            return self::extractJson($result->stdout);
        } catch (\UnexpectedValueException $e) {
            throw new ConsoleException($commandLine, $result, $e->getMessage());
        }
    }

    /**
     * @param list<string> $args
     */
    private function commandLine(array $args): string
    {
        return $this->command.' '.implode(' ', array_map(escapeshellarg(...), $args));
    }
}
