<?php

declare(strict_types=1);

namespace Semitexa\Testing\Application\Console\Command;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Environment;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Testing\Application\Service\E2e\E2eDatabaseGuard;
use Semitexa\Testing\Application\Service\E2e\E2eFixtureContext;
use Semitexa\Testing\Application\Service\E2e\E2eFixtureDiscovery;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs every #[AsE2eFixture] on the E2E database; the E2E runner calls it after
 * the schema and data patches (tk-e2e-specs-own-their-data). Refuses, before any
 * fixture runs, on a database E2eDatabaseGuard does not recognise as the E2E one.
 */
#[AsCommand(
    name: 'e2e:fixtures',
    description: 'Put what E2E specs need into the E2E database (refuses on any other database).',
)]
final class E2eFixturesCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected ClassDiscovery $classDiscovery;

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    #[InjectAsReadonly]
    protected ContainerInterface $container;

    protected function configure(): void
    {
        $this->setName('e2e:fixtures')
            ->setDescription('Put what E2E specs need into the E2E database (refuses on any other database).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = array_filter(getenv(), 'is_string');
        // The guard's two keys may live only in .env: read them as the app does.
        foreach (['APP_ENV', 'DB_E2E_DATABASE'] as $key) {
            $value = $env[$key] ?? Environment::getEnvValue($key);
            if ($value !== null) {
                $env[$key] = $value;
            }
        }
        $db = $this->connections->manager('default')->getQueryAdapter();

        $refusal = E2eDatabaseGuard::refusal($db, $env);
        if ($refusal !== null) {
            $output->writeln('<error>e2e:fixtures refused: ' . $refusal . '</error>');

            return Command::FAILURE;
        }

        $fixtures = (new E2eFixtureDiscovery($this->classDiscovery))->discover();
        $context = new E2eFixtureContext($db, $this->container, $env);
        foreach ($fixtures as $fixture) {
            // E2eFixtureDiscovery checked the class implements the contract.
            $instance = new $fixture['class']();
            $output->writeln(sprintf('  %s: %s', $fixture['id'], $instance->apply($context)));
        }
        $output->writeln(sprintf('<info>%d E2E fixture(s) applied.</info>', count($fixtures)));

        return Command::SUCCESS;
    }
}
