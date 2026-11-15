<?php

namespace AssetMapperUpdate\Importmap;

use AssetMapperUpdate\Model\ImportmapEntry;
use AssetMapperUpdate\Model\RemotePackage;

final class Packages
{
    /**
     * `bootstrap/dist/css/bootstrap.min.css` → `bootstrap`, `@hotwired/stimulus/x.js` → `@hotwired/stimulus`.
     */
    public static function nameOf(string $specifier): string
    {
        $parts = explode('/', $specifier);

        return str_starts_with($specifier, '@') ? implode('/', \array_slice($parts, 0, 2)) : $parts[0];
    }

    /**
     * Groups the remote entries of an importmap by npm package.
     *
     * Local entries (`path`, no `version`) and entrypoints are left out: there is nothing to update.
     * CSS and other sub-path entries are folded into their package, so they are always bumped with it.
     *
     * @param list<ImportmapEntry> $entries
     *
     * @return list<RemotePackage>
     */
    public static function remote(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            if (null === $entry->version || $entry->entrypoint) {
                continue;
            }
            $specifier = $entry->specifier();
            $name = self::nameOf($specifier);
            if (!isset($groups[$name])) {
                $groups[$name] = ['main' => null, 'entries' => [$entry]];
            } else {
                $groups[$name]['entries'][] = $entry;
            }
            if ($specifier === $name) {
                $groups[$name]['main'] = $entry;
            }
        }

        $packages = [];
        foreach ($groups as $name => $group) {
            $main = $group['main'] ?? $group['entries'][0];
            $packages[] = new RemotePackage(
                $name,
                (string) $main->version,
                array_map(static fn (ImportmapEntry $e): string => $e->key, $group['entries']),
            );
        }

        return $packages;
    }

    /**
     * Argument for `importmap:require`, e.g. `bootstrap/dist/css/bootstrap.min.css@5.3.9` or
     * `vue/dist/vue.esm-bundler.js@3.5.0=vue`.
     */
    public static function requireArgument(ImportmapEntry $entry, string $version): string
    {
        $specifier = $entry->specifier();

        return $specifier.'@'.$version.($specifier !== $entry->key ? '='.$entry->key : '');
    }
}
