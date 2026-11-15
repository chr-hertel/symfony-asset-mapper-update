<?php

namespace AssetMapperUpdate;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes to the console. On GitHub Actions it uses workflow commands, so warnings and errors show
 * up as annotations and groups are collapsible.
 *
 * @see https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-commands
 */
final class Log
{
    public function __construct(
        private readonly OutputInterface $output = new NullOutput(),
        private readonly bool $githubActions = false,
    ) {
    }

    public function info(string $message): void
    {
        $this->output->writeln(OutputFormatter::escape($message));
    }

    public function debug(string $message): void
    {
        if ($this->githubActions) {
            $this->output->writeln('::debug::'.self::escapeData($message), OutputInterface::OUTPUT_RAW);
        } elseif ($this->output->isVerbose()) {
            $this->output->writeln('<fg=gray>'.OutputFormatter::escape($message).'</>');
        }
    }

    public function warning(string $message): void
    {
        $this->annotate('warning', 'comment', $message);
    }

    public function error(string $message): void
    {
        $this->annotate('error', 'error', $message);
    }

    public function group(string $title): void
    {
        if ($this->githubActions) {
            $this->output->writeln('::group::'.self::escapeData($title), OutputInterface::OUTPUT_RAW);
        } else {
            $this->output->writeln(['', '<info>'.OutputFormatter::escape($title).'</info>']);
        }
    }

    public function endGroup(): void
    {
        if ($this->githubActions) {
            $this->output->writeln('::endgroup::', OutputInterface::OUTPUT_RAW);
        }
    }

    /**
     * Masks a secret in the GitHub Actions log.
     */
    public function mask(string $secret): void
    {
        if ($this->githubActions && '' !== $secret) {
            $this->output->writeln('::add-mask::'.self::escapeData($secret), OutputInterface::OUTPUT_RAW);
        }
    }

    private function annotate(string $command, string $style, string $message): void
    {
        if ($this->githubActions) {
            $this->output->writeln('::'.$command.'::'.self::escapeData($message), OutputInterface::OUTPUT_RAW);
        } else {
            $this->output->writeln(\sprintf('<%s>%s: %s</>', $style, ucfirst($command), OutputFormatter::escape($message)));
        }
    }

    private static function escapeData(string $data): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $data);
    }
}
