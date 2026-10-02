<?php

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Invoice;
use Config\Services;
use RuntimeException;

/**
 * Generic Zoho Books API client (token refresh + authenticated requests).
 *
 * The access token is kept in the CI cache, so it is reused across requests for
 * its full hour instead of being refreshed every time (Zoho limits how many access
 * tokens one refresh token may generate in a short period).
 *
 * Error messages never include the token, credentials or request bodies.
 */
class ZohoClient
{
    private const TOKEN_CACHE_KEY = 'zoho_access_token';

    private ?string $accessToken = null;
    private int $tokenExpiresAt  = 0;

    public function __construct(private readonly Invoice $config)
    {
    }

    private function http(): CURLRequest
    {
        $caBundle = $this->config->zohoCaBundle;

        return Services::curlrequest([
            'connect_timeout' => 10,
            'timeout'         => 30,
            'http_errors'     => false,
            // Empty CA bundle = LocalWP workaround for the CA bundle error. Set invoice.zohoCaBundle before going live.
            'verify' => ($caBundle !== '' && is_readable($caBundle)) ? $caBundle : false,
        ], null, null, false);
    }

    /**
     * A valid access token, refreshed only when needed.
     */
    private function getAccessToken(bool $force = false): string
    {
        if (! $force) {
            if ($this->accessToken === null) {
                $cached = cache(self::TOKEN_CACHE_KEY);
                if (is_array($cached) && ! empty($cached['token']) && ! empty($cached['expires_at'])) {
                    $this->accessToken    = $cached['token'];
                    $this->tokenExpiresAt = (int) $cached['expires_at'];
                }
            }
            if ($this->accessToken !== null && time() < $this->tokenExpiresAt) {
                return $this->accessToken;
            }
        }

        try {
            $response = $this->http()->post($this->config->zohoAccountsUrl, [
                'form_params' => [
                    'refresh_token' => $this->config->zohoRefreshToken,
                    'client_id'     => $this->config->zohoClientId,
                    'client_secret' => $this->config->zohoClientSecret,
                    'grant_type'    => 'refresh_token',
                ],
            ]);
        } catch (HTTPException $e) {
            throw new RuntimeException('Could not reach Zoho accounts: ' . $e->getMessage());
        }

        $data = json_decode((string) $response->getBody(), true);
        if (empty($data['access_token'])) {
            $reason = is_array($data) && isset($data['error']) ? (string) $data['error'] : 'HTTP ' . $response->getStatusCode();

            throw new RuntimeException('Failed to refresh Zoho access token: ' . $reason);
        }

        $this->accessToken    = $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600) - 60; // refresh 1 min early
        cache()->save(self::TOKEN_CACHE_KEY, [
            'token'      => $this->accessToken,
            'expires_at' => $this->tokenExpiresAt,
        ], max(60, $this->tokenExpiresAt - time()));

        return $this->accessToken;
    }

    /**
     * Authenticated API call.
     *
     * @return array{status: int, data: mixed}
     */
    public function request(string $method, string $endpoint, array $params = [], ?array $body = null, bool $retry = true): array
    {
        $token = $this->getAccessToken();

        $params['organization_id'] = $this->config->zohoOrgId;
        $options = [
            'headers' => ['Authorization' => 'Zoho-oauthtoken ' . $token],
            'query'   => $params,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http()->request($method, $this->config->zohoApiUrl . $endpoint, $options);
        } catch (HTTPException $e) {
            throw new RuntimeException('Could not reach Zoho Books: ' . $e->getMessage());
        }
        $status = $response->getStatusCode();

        // Saved token was revoked or expired early: get a fresh one and retry once
        if ($status === 401 && $retry) {
            $this->getAccessToken(true);

            return $this->request($method, $endpoint, $params, $body, false);
        }

        return [
            'status' => $status,
            'data'   => json_decode((string) $response->getBody(), true, 512, JSON_BIGINT_AS_STRING),
        ];
    }
}
