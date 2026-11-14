<?php

declare(strict_types=1);

namespace Semitexa\Testing\Application\Service\E2e\Fixture;

use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Platform\User\Application\Service\PasswordHasher;
use Semitexa\Platform\User\Domain\Contract\PlatformUserRepositoryInterface;
use Semitexa\Platform\User\Domain\Enum\UserRole;
use Semitexa\Platform\User\Domain\Enum\UserStatus;
use Semitexa\Platform\User\Domain\Model\PlatformUser;
use Semitexa\Testing\Application\Service\E2e\E2eFixtureContext;
use Semitexa\Testing\Attributes\AsE2eFixture;
use Semitexa\Testing\Contract\E2eFixtureInterface;

/**
 * The owner the E2E specs sign in as (CMS, OS apps, files, tasks, calendar): it
 * existed only because someone made it by hand in a developer's database, so on a
 * clean E2E database every sign-in failed (tk-e2e-specs-own-their-data).
 *
 * The password is the runner's, made for this run and handed to the specs too
 * (SEMITEXA_E2E_PASSWORD): no fixed password is written anywhere. Here, not in
 * semitexa/platform-user, so production never gains a dependency on this package;
 * a project without platform-user skips it.
 */
#[AsE2eFixture(id: 'testing.sign-in-owner', priority: 100)]
final class SignInOwnerFixture implements E2eFixtureInterface
{
    public const DEFAULT_EMAIL = 'owner@semitexa.test';

    public function apply(E2eFixtureContext $context): string
    {
        if (!interface_exists(PlatformUserRepositoryInterface::class)) {
            return 'skipped: semitexa/platform-user is not installed';
        }
        $password = $context->env('SEMITEXA_E2E_PASSWORD');
        if ($password === null) {
            throw new \RuntimeException('SEMITEXA_E2E_PASSWORD is not set: the E2E runner makes one per run and hands it to the specs.');
        }
        $email = $context->env('SEMITEXA_E2E_EMAIL') ?? self::DEFAULT_EMAIL;

        $users = $context->container->get(PlatformUserRepositoryInterface::class);
        $hasher = $context->container->get(PasswordHasher::class);
        if (!$users instanceof PlatformUserRepositoryInterface || !$hasher instanceof PasswordHasher) {
            throw new \LogicException('The container gave no platform-user repository or password hasher.');
        }
        $hash = $hasher->hash($password);

        // A copied database may hold the account already, under any tenant: reset that one row to an
        // active owner. A second row would make the tenant-less sign-in lookup find two and refuse
        // both (Fable's review, testing#40).
        $normalized = strtolower(trim($email));
        $matches = array_values(array_filter(
            $users->findAll(null, true),
            static fn (PlatformUser $user): bool => strtolower(trim($user->getEmail())) === $normalized,
        ));
        if (count($matches) > 1) {
            throw new \RuntimeException(sprintf('%s is %d accounts in the E2E database: the sign-in would find none.', $email, count($matches)));
        }
        $existing = $matches[0] ?? null;
        if ($existing !== null) {
            // An owner belongs to no site, and a copied row other tables point at is not deleted here.
            if ($existing->getTenantId() !== '') {
                throw new \RuntimeException(sprintf('%s is an account of tenant %s in the E2E database: an owner belongs to no site. Set SEMITEXA_E2E_EMAIL to another address.', $email, $existing->getTenantId()));
            }
            $users->update($existing->withPasswordHash($hash)->withRole(UserRole::Owner)->withStatus(UserStatus::Active));

            return sprintf('owner %s: password reset for this run', $email);
        }
        $users->save(new PlatformUser(
            id: Uuid7::generate(),
            email: $email,
            passwordHash: $hash,
            displayName: 'E2E owner',
            role: UserRole::Owner,
        ));

        return sprintf('owner %s: created', $email);
    }
}
