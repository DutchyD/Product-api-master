<?php

declare(strict_types=1);

namespace MaxServ\App\Security;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Creates and checks the token that proves a form was sent from a page of this application.
 */
final readonly class CsrfTokenManager
{
    private const string SESSION_KEY = '_csrf_token';
    private const int TOKEN_BYTES = 32;

    /**
     * Returns the session's token, creating it from a cryptographic random source when the session has none.
     *
     * @param SessionInterface $session The browser session.
     *
     * @return string The token, as 64 hexadecimal characters.
     * @throws \Random\RandomException No random source is available.
     * @throws \RuntimeException The session could not be started.
     */
    public function tokenFor(SessionInterface $session): string
    {
        $token = $session->get(self::SESSION_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(self::TOKEN_BYTES));
        $session->set(self::SESSION_KEY, $token);

        return $token;
    }

    /**
     * Checks a submitted token against the session's token in constant time.
     *
     * @param SessionInterface $session The browser session.
     * @param mixed $submittedToken The submitted token.
     *
     * @return bool True only when the session has a token and the submitted token equals it.
     * @throws \RuntimeException The session could not be started.
     */
    #[\NoDiscard]
    public function isValid(SessionInterface $session, mixed $submittedToken): bool
    {
        $expectedToken = $session->get(self::SESSION_KEY);
        if (!is_string($expectedToken) || $expectedToken === '' || !is_string($submittedToken)) {
            return false;
        }

        return hash_equals($expectedToken, $submittedToken);
    }
}
