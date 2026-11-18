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
    public function token_is_64_hexadecimal_characters(): void
    {
        $token = new CsrfTokenManager()->tokenFor(self::session());

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function token_is_reused_within_a_session(): void
    {
        $manager = new CsrfTokenManager();
        $session = self::session();

        $first = $manager->tokenFor($session);
        $second = $manager->tokenFor($session);

        self::assertSame($first, $second);
    }

    #[Test]
    public function sessions_get_different_tokens(): void
    {
        $manager = new CsrfTokenManager();

        self::assertNotSame($manager->tokenFor(self::session()), $manager->tokenFor(self::session()));
    }

    #[Test]
    public function token_of_the_session_is_valid(): void
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
    public function other_submitted_tokens_are_invalid(mixed $submittedToken): void
    {
        $manager = new CsrfTokenManager();
        $session = self::session();
        (void) $manager->tokenFor($session);

        self::assertFalse($manager->isValid($session, $submittedToken));
    }

    #[Test]
    public function no_token_is_valid_when_the_session_has_none(): void
    {
        $manager = new CsrfTokenManager();

        self::assertFalse($manager->isValid(self::session(), ''));
    }

    private static function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
