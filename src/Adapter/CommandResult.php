<?php

namespace AssetMapperUpdate\Adapter;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {
    }

    /**
     * The last lines of the output, for error messages.
     */
    public function tail(int $lines = 20): string
    {
        $output = trim('' !== trim($this->stderr) ? $this->stderr : $this->stdout);

        return implode("\n", \array_slice(explode("\n", $output), -$lines));
    }
}
