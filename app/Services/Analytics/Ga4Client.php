<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Analytics 4 Data API (where Firebase Analytics events land), using a
 * service-account key. No Google SDK needed: the OAuth token is a signed JWT
 * (RS256 via ext-openssl) exchanged at Google's token endpoint.
 * https://developers.google.com/analytics/devguides/reporting/data/v1/rest/v1beta/properties/runReport
 */
class Ga4Client
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    private const API = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    /** Held in memory for this request only, so the credential never sits in the cache store. */
    private ?string $accessToken = null;

    private int $accessTokenExpiresAt = 0;

    public function isConfigured(): bool
    {
        $path = (string) config('timevault_analytics.ga4.credentials');

        return filled(config('timevault_analytics.ga4.property_id')) && $path !== '' && is_readable($path);
    }

    /** @return array<string, mixed> The raw runReport response. */
    public function runReport(array $body): array
    {
        $property = preg_replace('/\D/', '', (string) config('timevault_analytics.ga4.property_id'));

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->post(self::API.$property.':runReport', $body);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Turns a runReport response into plain rows keyed by header name:
     * [['eventName' => 'login', 'eventCount' => 12.0], ...]. Metrics are floats.
     *
     * @return array<int, array<string, string|float>>
     */
    public static function rows(array $report): array
    {
        $dims = array_column($report['dimensionHeaders'] ?? [], 'name');
        $mets = array_column($report['metricHeaders'] ?? [], 'name');
        $out = [];

        foreach ($report['rows'] ?? [] as $row) {
            $item = [];
            foreach ($dims as $i => $name) {
                $item[$name] = (string) ($row['dimensionValues'][$i]['value'] ?? '');
            }
            foreach ($mets as $i => $name) {
                $item[$name] = (float) ($row['metricValues'][$i]['value'] ?? 0);
            }
            $out[] = $item;
        }

        return $out;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }

        $key = json_decode((string) file_get_contents((string) config('timevault_analytics.ga4.credentials')), true);
        if (! is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new RuntimeException('GA4 credentials file is not a service-account JSON key.');
        }

        $tokenUri = $key['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $assertion = self::jwt([
            'iss' => $key['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $key['private_key']);

        $response = Http::asForm()->timeout(30)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);
        $response->throw();

        $this->accessToken = (string) $response->json('access_token');
        $this->accessTokenExpiresAt = $now + (int) $response->json('expires_in', 3600) - 60;

        return $this->accessToken;
    }

    /** RS256-signed JWT for Google's service-account OAuth flow. */
    public static function jwt(array $claims, string $privateKey): string
    {
        $b64 = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $input = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$b64((string) json_encode($claims));

        if (! openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the GA4 token request (bad private key).');
        }

        return $input.'.'.$b64($signature);
    }
}
