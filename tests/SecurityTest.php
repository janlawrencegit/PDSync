<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Cross-site request protection, Content-Security-Policy and consent.
final class SecurityTest extends TestCase
{
    public static function requests(): array
    {
        $host = ['HTTP_HOST' => 'pdsync.example'];
        return [
            'same Origin' => [$host + ['HTTP_ORIGIN' => 'https://pdsync.example'], true],
            'other Origin' => [$host + ['HTTP_ORIGIN' => 'https://evil.example'], false],
            'Origin on another port' => [$host + ['HTTP_ORIGIN' => 'https://pdsync.example:8443'], false],
            'Origin wins over a same-origin Sec-Fetch-Site' => [$host + ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], false],
            'no Origin, Sec-Fetch-Site same-origin' => [$host + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], true],
            'no Origin, Sec-Fetch-Site none (typed URL)' => [$host + ['HTTP_SEC_FETCH_SITE' => 'none'], true],
            'no Origin, Sec-Fetch-Site cross-site' => [$host + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], false],
            'no Origin, Sec-Fetch-Site same-site (subdomain)' => [$host + ['HTTP_SEC_FETCH_SITE' => 'same-site'], false],
            'only a same-site Referer' => [$host + ['HTTP_REFERER' => 'https://pdsync.example/library.php'], true],
            'only a cross-site Referer' => [$host + ['HTTP_REFERER' => 'https://evil.example/x'], false],
            'Origin "null" falls back to Sec-Fetch-Site' => [$host + ['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'cross-site'], false],
            'no browser headers (curl)' => [$host, true],
        ];
    }

    #[DataProvider('requests')]
    public function testSameOriginDecision(array $server, bool $allowed): void
    {
        $this->assertSame($allowed, same_origin_ok($server));
    }

    public function testCspAllowsOnlyOwnAndNoncedScripts(): void
    {
        $csp = csp_header();
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]{24}'/", $csp);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString(csp_nonce(), $csp, 'the header carries the same nonce the page uses');
    }

    public function testConsentMustMatchTheCurrentNotice(): void
    {
        $this->assertTrue(consent_given(['consent' => CONSENT_VERSION]));
        $this->assertFalse(consent_given([]));
        $this->assertFalse(consent_given(['consent' => '2020-01-01']));
        $this->assertFalse(consent_given(['consent' => true]));
    }
}
