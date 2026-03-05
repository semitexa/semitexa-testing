<?php

declare(strict_types=1);

namespace Semitexa\Testing\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'test:run', description: 'Run PHPUnit tests inside Docker (APP_ENV=dev only)')]
class TestRunCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('test:run')
            ->setDescription('Run PHPUnit tests inside Docker (APP_ENV=dev only)')
            ->addArgument(
                'phpunit-args',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Arguments forwarded to PHPUnit (e.g. -- --testdox --filter MyTest)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->getProjectRoot();

        if (!$this->isDevEnvironment($projectRoot)) {
            $io->error('test:run is only available when APP_ENV=dev.');
            $io->text('Set APP_ENV=dev in your .env file to enable test containers.');
            return Command::FAILURE;
        }

        if (!file_exists($projectRoot . '/docker-compose.yml')) {
            $io->error('docker-compose.yml not found. Run from project root.');
            return Command::FAILURE;
        }

        if (!file_exists($projectRoot . '/docker-compose.test.yml')) {
            $io->error('docker-compose.test.yml not found.');
            return Command::FAILURE;
        }

        $composeFiles = $this->buildComposeFiles($projectRoot);
        $phpunitArgs  = $input->getArgument('phpunit-args') ?? [];

        $io->title('Running PHPUnit in Docker');
        $io->text('Compose files: ' . implode(', ', $composeFiles));

        $cmd = array_merge(
            ['docker', 'compose'],
            $this->buildComposeFileArgs($composeFiles),
            ['run', '--rm', 'phpunit', 'vendor/bin/phpunit'],
            $phpunitArgs,
        );

        $process = new Process($cmd, $projectRoot);
        $process->setTimeout(null);
        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Always: docker-compose.yml + docker-compose.test.yml.
     * Conditionally: mysql and redis overlays (mirrors ServerStartCommand logic).
     *
     * @return list<string>
     */
    private function buildComposeFiles(string $projectRoot): array
    {
        $files = ['docker-compose.yml'];

        if ($this->shouldUseMysqlCompose($projectRoot)) {
            $files[] = 'docker-compose.mysql.yml';
        }
        if ($this->shouldUseRedisCompose($projectRoot)) {
            $files[] = 'docker-compose.redis.yml';
        }

        $files[] = 'docker-compose.test.yml';

        return $files;
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function buildComposeFileArgs(array $files): array
    {
        $args = [];
        foreach ($files as $file) {
            $args[] = '-f';
            $args[] = $file;
        }
        return $args;
    }

    private function isDevEnvironment(string $projectRoot): bool
    {
        $content = $this->readEnv($projectRoot);
        return $content !== null
            && (bool) preg_match('/^\s*APP_ENV\s*=\s*dev\s*$/mi', $content);
    }

    private function shouldUseMysqlCompose(string $projectRoot): bool
    {
        if (!file_exists($projectRoot . '/docker-compose.mysql.yml')) {
            return false;
        }
        $content = $this->readEnv($projectRoot);
        return $content !== null && (bool) preg_match('/^\s*DB_DRIVER\s*=\s*\S+/m', $content);
    }

    private function shouldUseRedisCompose(string $projectRoot): bool
    {
        if (!file_exists($projectRoot . '/docker-compose.redis.yml')) {
            return false;
        }
        $content = $this->readEnv($projectRoot);
        return $content !== null && (bool) preg_match('/^\s*REDIS_HOST\s*=\s*\S+/m', $content);
    }

    private function readEnv(string $projectRoot): ?string
    {
        $envFile = $projectRoot . '/.env';
        if (!file_exists($envFile)) {
            return null;
        }
        $content = file_get_contents($envFile);
        return $content !== false ? $content : null;
    }
}
