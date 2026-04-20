<?php

namespace TurnkeyAgentic\Core\Tests;

use PHPUnit\Framework\TestCase;
use TurnkeyAgentic\Core\Services\GoogleOAuthService;

class GoogleOAuthServiceTest extends TestCase
{
    public function testGeneratesAuthUrl(): void
    {
        $service = new GoogleOAuthService(
            'test-client-id.apps.googleusercontent.com',
            'test-secret',
            'https://example.com/auth/google/callback'
        );

        $url = $service->getAuthUrl('test-state-123');

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('test-client-id', $url);
        $this->assertStringContainsString('test-state-123', $url);
        $this->assertStringContainsString('email', $url);
        $this->assertStringContainsString('profile', $url);
        $this->assertStringContainsString(urlencode('https://example.com/auth/google/callback'), $url);
    }

    public function testAuthUrlWithoutState(): void
    {
        $service = new GoogleOAuthService(
            'test-client-id.apps.googleusercontent.com',
            'test-secret',
            'https://example.com/auth/google/callback'
        );

        $url = $service->getAuthUrl();

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringNotContainsString('state=', $url);
    }
}
