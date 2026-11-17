<?php

declare(strict_types=1);

namespace Mediloop;

/** "Sign in with Mediloop" for an app people sign in to: authorization code with PKCE. */
final class Pkce
{
    public const ISSUER = 'https://auth.mediloop.co/realms/mediloop-healthcare';

    /** @return array{verifier:string, challenge:string, state:string} keep the verifier and state in the person's session */
    public static function start(): array
    {
        $verifier = self::b64(random_bytes(48));
        return [
            'verifier' => $verifier,
            'challenge' => self::b64(hash('sha256', $verifier, true)),
            'state' => self::b64(random_bytes(24)),
        ];
    }

    public static function authorizeUrl(string $clientId, string $redirectUri, string $challenge, string $state, string $scope = 'openid'): string
    {
        return self::ISSUER . '/protocol/openid-connect/auth?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Swap the code Mediloop sent back for the person's tokens. Check that the state you got back equals the one you kept.
     * Do this on your server: it sends your client secret.
     *
     * @return array<string,mixed>
     */
    public static function exchange(string $clientId, string $redirectUri, string $code, string $verifier, ?Transport $transport = null, ?string $clientSecret = null): array
    {
        $form = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $verifier,
        ];
        return self::tokenRequest($form, $clientSecret, $transport, 'Mediloop did not accept the sign-in code.');
    }

    /**
     * Get a new access token with the refresh token. Do this on your server: it sends your client secret.
     *
     * @return array<string,mixed>
     */
    public static function refresh(string $clientId, string $refreshToken, ?Transport $transport = null, ?string $clientSecret = null): array
    {
        $form = ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refreshToken];
        return self::tokenRequest($form, $clientSecret, $transport, 'Mediloop did not accept the refresh token. The person may have stopped your app.');
    }

    /**
     * @param array<string,string> $form
     * @return array<string,mixed>
     */
    private static function tokenRequest(array $form, ?string $clientSecret, ?Transport $transport, string $failure): array
    {
        $transport ??= new CurlTransport();
        if ($clientSecret !== null && $clientSecret !== '') {
            $form['client_secret'] = $clientSecret;
        }
        $response = $transport->send('POST', self::ISSUER . '/protocol/openid-connect/token',
            ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], http_build_query($form), 30.0);
        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data) || empty($data['access_token'])) {
            throw new MediloopException($failure);
        }
        return $data;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
