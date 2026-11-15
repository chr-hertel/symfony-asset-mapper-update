<?php

namespace AssetMapperUpdate\Config;

use AssetMapperUpdate\Model\UpdateType;

final readonly class IgnoreRule
{
    /**
     * @param string           $name        package name; `*` matches any characters, e.g. `@fortawesome/*`
     * @param list<UpdateType> $updateTypes empty means: ignore the package entirely, including security updates
     */
    public function __construct(
        public string $name,
        public array $updateTypes = [],
    ) {
    }

    public function ignoresEverything(): bool
    {
        return [] === $this->updateTypes;
    }

    public function matches(string $package): bool
    {
        if (!str_contains($this->name, '*')) {
            return $this->name === $package;
        }
        $regex = '/^'.implode('.*', array_map(static fn (string $p): string => preg_quote($p, '/'), explode('*', $this->name))).'$/';

        return 1 === preg_match($regex, $package);
    }
}
