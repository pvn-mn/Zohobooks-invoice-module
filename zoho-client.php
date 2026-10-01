<?php
/**
 * Generic Zoho Books API client (token refresh + authenticated requests).
 *
 * Change from the first version: the access token is now saved to
 * zoho-token.cache.php, so it is reused across page loads for its full hour
 * instead of being refreshed on every request (Zoho limits how many access
 * tokens one refresh token may generate in a short period).
 */
require_once __DIR__ . '/../config.php';

class ZohoClient {
    private static $accessToken = null;
    private static $tokenExpiresAt = 0;
    const TOKEN_GUARD = '<?php exit; ?>'; // makes the cache file print nothing if opened in a browser

    private static function accountsUrl() {
        return defined('ZOHO_ACCOUNTS_URL') ? ZOHO_ACCOUNTS_URL : 'https://accounts.zoho.com/oauth/v2/token';
    }

    private static function apiUrl() {
        return defined('ZOHO_API_URL') ? ZOHO_API_URL : 'https://www.zohoapis.com/books/v3';
    }

    private static function tokenFile() {
        return __DIR__ . '/zoho-token.cache.php';
    }

    private static function loadToken() {
        $file = self::tokenFile();
        if (!is_readable($file)) return;
        $data = json_decode(substr((string)file_get_contents($file), strlen(self::TOKEN_GUARD)), true);
        if (!empty($data['token']) && !empty($data['expires_at'])) {
            self::$accessToken = $data['token'];
            self::$tokenExpiresAt = (int)$data['expires_at'];
        }
    }

    private static function saveToken() {
        $json = json_encode(['token' => self::$accessToken, 'expires_at' => self::$tokenExpiresAt]);
        @file_put_contents(self::tokenFile(), self::TOKEN_GUARD . $json, LOCK_EX);
    }

    private static function curl($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        if (defined('ZOHO_CA_BUNDLE') && is_readable(ZOHO_CA_BUNDLE)) {
            curl_setopt($ch, CURLOPT_CAINFO, ZOHO_CA_BUNDLE);
        } else {
            // LocalWP workaround for the CA bundle error. Set ZOHO_CA_BUNDLE in config.php before going live.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        return $ch;
    }

    // Step 4: get a valid access token, refreshing only when needed
    private static function getAccessToken($force = false) {
        if (!$force) {
            if (!self::$accessToken) self::loadToken();
            if (self::$accessToken && time() < self::$tokenExpiresAt) return self::$accessToken;
        }

        $ch = self::curl(self::accountsUrl());
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'refresh_token' => ZOHO_REFRESH_TOKEN,
            'client_id' => ZOHO_CLIENT_ID,
            'client_secret' => ZOHO_CLIENT_SECRET,
            'grant_type' => 'refresh_token',
        ]));
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) throw new Exception('Could not reach Zoho accounts: ' . $error);
        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new Exception('Failed to refresh Zoho access token: ' . $response);
        }

        self::$accessToken = $data['access_token'];
        self::$tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600) - 60; // refresh 1 min early
        self::saveToken();
        return self::$accessToken;
    }

    // Step 5: make an authenticated API call
    public static function request($method, $endpoint, $params = [], $body = null, $retry = true) {
        $token = self::getAccessToken();

        $params['organization_id'] = ZOHO_ORG_ID;
        $ch = self::curl(self::apiUrl() . $endpoint . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Zoho-oauthtoken ' . $token,
            'Content-Type: application/json',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) throw new Exception('Could not reach Zoho Books: ' . $error);

        // Saved token was revoked or expired early: get a fresh one and retry once
        if ($httpCode === 401 && $retry) {
            self::getAccessToken(true);
            return self::request($method, $endpoint, $params, $body, false);
        }

        return [
            'status' => $httpCode,
            'data' => json_decode($response, true, 512, JSON_BIGINT_AS_STRING),
        ];
    }
}
