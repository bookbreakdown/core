<?php

namespace TurnkeyAgentic\Core\Services;

use Google\Client;

class GoogleOAuthService
{
    private Client $client;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri
    ) {
        $this->client = new Client();
        $this->client->setClientId($clientId);
        $this->client->setClientSecret($clientSecret);
        $this->client->setRedirectUri($redirectUri);
        $this->client->addScope('email');
        $this->client->addScope('profile');
        $this->client->setAccessType('online');
    }

    public function getAuthUrl(?string $state = null): string
    {
        if ($state !== null) {
            $this->client->setState($state);
        }
        return $this->client->createAuthUrl();
    }

    /**
     * Exchange authorization code for user profile.
     *
     * @return array{email: string, name: string, picture: ?string, google_id: string}
     */
    public function handleCallback(string $code): array
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \RuntimeException('Google OAuth error: ' . ($token['error_description'] ?? $token['error']));
        }

        $this->client->setAccessToken($token);

        $oauth2 = new \Google\Service\Oauth2($this->client);
        $userInfo = $oauth2->userinfo->get();

        return [
            'email'     => $userInfo->getEmail(),
            'name'      => $userInfo->getName(),
            'picture'   => $userInfo->getPicture(),
            'google_id' => $userInfo->getId(),
        ];
    }
}
