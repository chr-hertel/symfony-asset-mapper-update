<?php

namespace AssetMapperUpdate;

final readonly class Inputs
{
    /**
     * @param string $repository `owner/repo`
     * @param string $workspace  directory that relative paths are resolved against
     */
    public function __construct(
        public string $token,
        public string $repository,
        public string $console = 'php bin/console',
        public string $workingDirectory = '.',
        public string $configFile = '.github/importmap-updates.yml',
        public bool $dryRun = false,
        public string $baseBranch = '',
        public string $serverUrl = 'https://github.com',
        public string $apiUrl = 'https://api.github.com',
        public string $workspace = '.',
    ) {
    }
}
