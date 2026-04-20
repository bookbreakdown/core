<?php

namespace TurnkeyAgentic\Core\Tests;

use PHPUnit\Framework\TestCase;
use TurnkeyAgentic\Core\Services\MagicLinkService;

class MagicLinkServiceTest extends TestCase
{
    private MagicLinkService $service;

    protected function setUp(): void
    {
        $this->service = new MagicLinkService();
    }

    public function testGenerateTokenProducesUniqueTokens(): void
    {
        $result1 = $this->service->generateToken('a@example.com');
        $result2 = $this->service->generateToken('a@example.com');

        $this->assertNotEquals($result1['token'], $result2['token']);
        $this->assertSame('a@example.com', $result1['email']);
        $this->assertEquals(64, strlen($result1['token']));
    }

    public function testVerifySucceedsWithValidToken(): void
    {
        $generated = $this->service->generateToken('b@example.com');

        $find = fn(string $token) => $token === $generated['token']
            ? $generated
            : null;
        $markUsed = fn(string $token) => null;

        $record = $this->service->verifyToken($generated['token'], $find, $markUsed);

        $this->assertSame($generated['token'], $record['token']);
        $this->assertSame('b@example.com', $record['email']);
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        $generated = $this->service->generateToken('c@example.com');
        $generated['expires_at'] = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');

        $find = fn(string $token) => $generated;
        $markUsed = fn(string $token) => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token has expired.');

        $this->service->verifyToken($generated['token'], $find, $markUsed);
    }

    public function testVerifyRejectsAlreadyUsedToken(): void
    {
        $generated = $this->service->generateToken('d@example.com');
        $generated['used_at'] = '2026-01-01 00:00:00';

        $find = fn(string $token) => $generated;
        $markUsed = fn(string $token) => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token has already been used.');

        $this->service->verifyToken($generated['token'], $find, $markUsed);
    }

    public function testVerifyRejectsNonexistentToken(): void
    {
        $find = fn(string $token) => null;
        $markUsed = fn(string $token) => null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token not found.');

        $this->service->verifyToken('nonexistent-token', $find, $markUsed);
    }
}
