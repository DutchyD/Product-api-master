<?php

declare(strict_types=1);

namespace MaxServ\App\Tests\Security;

use MaxServ\App\Security\CsrfTokenManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(CsrfTokenManager::class)]
final class CsrfTokenManagerTest extends TestCase
{
    #[Test]
    public function tokenIs64HexadecimalCharacters(): void
    {
        $token = new CsrfTokenManager()->tokenFor(self::session());

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function tokenIsReusedWithinASession(): void
    {
        $manager = new CsrfTokenManager();
        $session = self::session();

        $first = $manager->tokenFor($session);
        $second = $manager->tokenFor($session);

        self::assertSame($first, $second);
    }

    #[Test]
    public function sessionsGetDifferentTokens(): void
    {
        $manager = new CsrfTokenManager();

        self::assertNotSame($manager->tokenFor(self::session()), $manager->tokenFor(self::session()));
    }

    #[Test]
    public function tokenOfTheSessionIsValid(): void
    {
        $manager = new CsrfTokenManager();
        $session = self::session();
        $token = $manager->tokenFor($session);

        self::assertTrue($manager->isValid($session, $token));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'wrong token' => [str_repeat('a', 64)];
        yield 'empty token' => [''];
        yield 'missing token' => [null];
        yield 'array token' => [['token']];
    }

    #[Test]
    #[DataProvider('invalidTokens')]
    public function otherSubmittedTokensAreInvalid(mixed $submittedToken): void
    {
        $manager = new CsrfTokenManager();
        $session = self::session();
        (void) $manager->tokenFor($session);

        self::assertFalse($manager->isValid($session, $submittedToken));
    }

    #[Test]
    public function noTokenIsValidWhenTheSessionHasNone(): void
    {
        $manager = new CsrfTokenManager();

        self::assertFalse($manager->isValid(self::session(), ''));
    }

    private static function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
