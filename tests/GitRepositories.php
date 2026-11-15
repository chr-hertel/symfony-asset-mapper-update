<?php

namespace AssetMapperUpdate\Tests;

use AssetMapperUpdate\Adapter\Git;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Real git repositories with a local bare "origin", in a temporary directory.
 */
trait GitRepositories
{
    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if (null !== $this->tmp) {
            (new Filesystem())->remove($this->tmp);
        }
    }

    /**
     * A full clone of an origin with $commits commits on main.
     *
     * @param array<string, string> $files committed in the first commit
     */
    private function cloneWithCommits(int $commits, array $files = []): Git
    {
        $this->tmp ??= sys_get_temp_dir().'/asset-mapper-update-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->tmp);
        $origin = $this->tmp.'/origin.git';
        (new Git($this->tmp))->run(['init', '--quiet', '--bare', '--initial-branch=main', $origin]);
        $work = new Git($this->tmp.'/seed');
        mkdir($work->cwd, 0o777, true);
        $work->run(['init', '--quiet', '--initial-branch=main']);
        foreach ($files as $name => $content) {
            file_put_contents($work->cwd.'/'.$name, $content);
        }
        for ($i = 1; $i <= $commits; ++$i) {
            $this->commitFile($work, 'history.txt', 'commit '.$i);
        }
        $work->run(['push', '--quiet', 'file://'.$origin, 'HEAD:refs/heads/main']);

        return $this->cloneFrom('file://'.$origin, 'clone');
    }

    private function cloneOf(Git $git): Git
    {
        return $this->cloneFrom($git->run(['remote', 'get-url', 'origin']), 'clone-'.bin2hex(random_bytes(2)));
    }

    private function cloneFrom(string $url, string $name): Git
    {
        \assert(null !== $this->tmp);
        (new Git($this->tmp))->run(['clone', '--quiet', $url, $this->tmp.'/'.$name]);

        return new Git($this->tmp.'/'.$name);
    }

    private function commitFile(Git $git, string $file, string $content): void
    {
        file_put_contents($git->cwd.'/'.$file, $content."\n", \FILE_APPEND);
        $git->run(['add', '--all']);
        $git->run(['-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', $content]);
    }
}
