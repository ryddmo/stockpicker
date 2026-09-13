<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Config;

/**
 * Single hardcoded credential pair, checked with password_verify(), and an
 * HMAC-signed session cookie with no server-side session storage (AD-13).
 *
 * This is the only place session logic lives. The front controller's
 * require_session() (public_html/index.php) composes on top of
 * sessionStatus() so Stories 4.2+ can reuse it without copy-pasting cookie
 * handling.
 */
final class AuthController
{
    public const COOKIE_NAME = 'stockpicker_session';

    /** 30 days, in seconds. */
    public const SESSION_TTL_SECONDS = 60 * 60 * 24 * 30;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Verifies the submitted credentials against the single hardcoded user.
     * Both checks always run (no short-circuit on username) so failure timing
     * does not hint at which part was wrong.
     */
    public function login(string $username, string $password): bool
    {
        $validUsername = hash_equals($this->config->loginUsername(), $username);
        $validPassword = password_verify($password, $this->config->loginPasswordHash());

        return $validUsername && $validPassword;
    }

    /**
     * Builds a fresh signed cookie value: base64(payload).signature, where
     * payload is JSON {"exp": <unix-timestamp 30 days out>} and signature is
     * hash_hmac('sha256', payload, Config::sessionKey()).
     */
    public function issueCookieValue(): string
    {
        $payload = json_encode(['exp' => time() + self::SESSION_TTL_SECONDS], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, $this->config->sessionKey());

        return base64_encode($payload) . '.' . $signature;
    }

    /**
     * Verifies the cookie's signature with hash_equals() before trusting its
     * expiry — no session storage, matching AD-13. A missing cookie, a
     * malformed value, and a bad signature are all reported the same way
     * (Invalid/Missing) so a tampered cookie behaves like no cookie at all.
     */
    public function sessionStatus(?string $cookieValue): SessionStatus
    {
        if ($cookieValue === null || $cookieValue === '') {
            return SessionStatus::Missing;
        }

        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return SessionStatus::Invalid;
        }

        [$encodedPayload, $signature] = $parts;
        $payload = base64_decode($encodedPayload, true);
        if ($payload === false) {
            return SessionStatus::Invalid;
        }

        $expected = hash_hmac('sha256', $payload, $this->config->sessionKey());
        if (!hash_equals($expected, $signature)) {
            return SessionStatus::Invalid;
        }

        $data = json_decode($payload, true);
        if (!\is_array($data) || !isset($data['exp']) || !\is_int($data['exp'])) {
            return SessionStatus::Invalid;
        }

        return $data['exp'] < time() ? SessionStatus::Expired : SessionStatus::Valid;
    }

    /**
     * Renders the full-page login form (server-rendered, full-page
     * POST-and-reload — no client-side JS, AD-12). $message, when given, is
     * shown inline above the form.
     */
    public function renderLoginPage(?string $message = null): string
    {
        $messageHtml = $message !== null
            ? '<p class="error">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "</p>\n"
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Logga in — stockpicker</title>
        </head>
        <body>
        <h1>Logga in</h1>
        {$messageHtml}<form method="post" action="/login">
        <label>Användarnamn<br><input type="text" name="username" autocomplete="username" required></label>
        <br>
        <label>Lösenord<br><input type="password" name="password" autocomplete="current-password" required></label>
        <br>
        <button type="submit">Logga in</button>
        </form>
        </body>
        </html>

        HTML;
    }
}
