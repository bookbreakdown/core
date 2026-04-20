<?php

namespace TurnkeyAgentic\Core\Services;

class MagicLinkService
{
    public function generateToken(string $email, int $expiresInMinutes = 30): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable())->modify("+{$expiresInMinutes} minutes");

        return [
            'token'      => $token,
            'email'      => $email,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    public function verifyToken(string $token, callable $findToken, callable $markUsed): array
    {
        $record = $findToken($token);

        if ($record === null) {
            throw new \RuntimeException('Token not found.');
        }

        if (!empty($record['used_at'])) {
            throw new \RuntimeException('Token has already been used.');
        }

        $expiresAt = new \DateTimeImmutable($record['expires_at']);
        if ($expiresAt < new \DateTimeImmutable()) {
            throw new \RuntimeException('Token has expired.');
        }

        $markUsed($token);

        return $record;
    }
}
