<?php

declare(strict_types=1);

namespace Stockpicker\Tests\Web;

use PHPUnit\Framework\TestCase;
use Stockpicker\Config;
use Stockpicker\Web\AuthController;
use Stockpicker\Web\SessionStatus;

/**
 * Unit-level coverage of credential checking and the signed session cookie:
 * sign/verify/expiry edge cases from the spec's I/O Matrix. See
 * tests/FrontControllerTest.php for the end-to-end HTTP flows.
 */
final class AuthControllerTest extends TestCase
{
    private const SESSION_KEY = 'test-session-key';
    private const USERNAME = 'stefan';
    private const PASSWORD = 'correct-horse-battery-staple';

    private function controller(): AuthController
    {
        return new AuthController(Config::fromArray([
            'login_username' => self::USERNAME,
            'login_password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'session_key' => self::SESSION_KEY,
        ]));
    }

    public function testLoginAcceptsCorrectCredentials(): void
    {
        self::assertTrue($this->controller()->login(self::USERNAME, self::PASSWORD));
    }

    public function testLoginRejectsWrongPassword(): void
    {
        self::assertFalse($this->controller()->login(self::USERNAME, 'wrong'));
    }

    public function testLoginRejectsWrongUsername(): void
    {
        self::assertFalse($this->controller()->login('someone-else', self::PASSWORD));
    }

    public function testLoginRejectsBothWrong(): void
    {
        self::assertFalse($this->controller()->login('someone-else', 'wrong'));
    }

    public function testIssuedCookieHasSignedFormat(): void
    {
        $cookie = $this->controller()->issueCookieValue();

        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/=]+\.[0-9a-f]{64}$#', $cookie);
    }

    public function testIssuedCookieVerifiesAsValid(): void
    {
        $auth = $this->controller();

        self::assertSame(SessionStatus::Valid, $auth->sessionStatus($auth->issueCookieValue()));
    }

    public function testIssuedCookieExpiresThirtyDaysOut(): void
    {
        $auth = $this->controller();
        $before = time();
        $cookie = $auth->issueCookieValue();
        $after = time();

        [$encodedPayload] = explode('.', $cookie, 2);
        $payload = json_decode((string) base64_decode($encodedPayload, true), true);

        self::assertIsArray($payload);
        self::assertGreaterThanOrEqual($before + AuthController::SESSION_TTL_SECONDS, $payload['exp']);
        self::assertLessThanOrEqual($after + AuthController::SESSION_TTL_SECONDS, $payload['exp']);
    }

    public function testMissingCookieIsReportedMissing(): void
    {
        $auth = $this->controller();

        self::assertSame(SessionStatus::Missing, $auth->sessionStatus(null));
        self::assertSame(SessionStatus::Missing, $auth->sessionStatus(''));
    }

    public function testTamperedSignatureIsInvalid(): void
    {
        $auth = $this->controller();
        $payload = json_encode(['exp' => time() + 3600], JSON_THROW_ON_ERROR);
        $tampered = base64_encode($payload) . '.' . str_repeat('0', 64);

        self::assertSame(SessionStatus::Invalid, $auth->sessionStatus($tampered));
    }

    public function testSignatureFromADifferentKeyIsInvalid(): void
    {
        $payload = json_encode(['exp' => time() + 3600], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, 'a-different-key');
        $cookie = base64_encode($payload) . '.' . $signature;

        self::assertSame(SessionStatus::Invalid, $this->controller()->sessionStatus($cookie));
    }

    public function testMalformedCookieIsInvalid(): void
    {
        $auth = $this->controller();

        self::assertSame(SessionStatus::Invalid, $auth->sessionStatus('not-a-signed-cookie-at-all'));
        self::assertSame(SessionStatus::Invalid, $auth->sessionStatus('###.###'));
        self::assertSame(SessionStatus::Invalid, $auth->sessionStatus(base64_encode('not json') . '.' . str_repeat('a', 64)));
    }

    public function testExpiredButValidlySignedCookieIsExpired(): void
    {
        $payload = json_encode(['exp' => time() - 10], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, self::SESSION_KEY);
        $cookie = base64_encode($payload) . '.' . $signature;

        self::assertSame(SessionStatus::Expired, $this->controller()->sessionStatus($cookie));
    }

    public function testRenderLoginPageIncludesFormAndGivenMessage(): void
    {
        $html = $this->controller()->renderLoginPage('Fel användarnamn eller lösenord.');

        self::assertStringContainsString('<form', $html);
        self::assertStringContainsString('Fel användarnamn eller lösenord.', $html);
    }

    public function testRenderLoginPageOmitsMessageWhenNotGiven(): void
    {
        $html = $this->controller()->renderLoginPage();

        self::assertStringContainsString('<form', $html);
        self::assertStringNotContainsString('Fel användarnamn', $html);
        self::assertStringNotContainsString('Sessionen har gått ut', $html);
    }

    public function testRenderLoginPageEscapesMessage(): void
    {
        $html = $this->controller()->renderLoginPage('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
