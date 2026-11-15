<?php

namespace AssetMapperUpdate;

use AssetMapperUpdate\Adapter\Git;
use AssetMapperUpdate\Adapter\SymfonyConsole;
use AssetMapperUpdate\Importmap\ImportmapParser;
use AssetMapperUpdate\Importmap\Packages;
use AssetMapperUpdate\Importmap\Patcher;
use AssetMapperUpdate\Importmap\PatchException;
use AssetMapperUpdate\Model\PlannedPullRequest;
use AssetMapperUpdate\PullRequest\ApplyDetails;

/**
 * Applies the updates of one pull request to importmap.php in the working tree.
 *
 * 1. `importmap:require <entry>@<version>…` resolves the new state, including new dependencies
 * 2. the files it touched are restored from git
 * 3. only the planned version bumps and new entries are replayed onto the original text
 * 4. the result is verified to touch nothing else
 */
final class Applier
{
    public function __construct(
        private readonly SymfonyConsole $console,
        private readonly Git $git,
    ) {
    }

    public function apply(PlannedPullRequest $pr, string $importmapPath): ApplyDetails
    {
        $original = self::read($importmapPath);
        $parsed = ImportmapParser::parse($original);

        $planned = [];
        $args = [];
        foreach ($pr->updates as $update) {
            foreach ($update->entries as $key) {
                $entry = $parsed->entry($key) ?? throw new PatchException(\sprintf('"%s" is not in importmap.php', $key));
                $planned[$key] = $update->to;
                $args[] = Packages::requireArgument($entry->entry, $update->to);
            }
        }

        try {
            $this->console->require($args);
            $resolved = self::read($importmapPath);
        } finally {
            $this->git->restore($this->git->modifiedFiles());
        }

        $changes = Patcher::resolveChanges($parsed->toPlainMap(), ImportmapParser::parse($resolved)->toPlainMap(), $planned);
        $patched = Patcher::patch($original, $changes->versionChanges, $changes->additions);
        Patcher::verify($original, $patched, $changes->versionChanges, $changes->additions);
        if (false === file_put_contents($importmapPath, $patched)) {
            throw new \RuntimeException('Could not write '.$importmapPath);
        }

        return new ApplyDetails($changes->additions, $changes->ignoredSideEffects);
    }

    private static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        return false !== $contents ? $contents : throw new \RuntimeException('Could not read '.$path);
    }
}
