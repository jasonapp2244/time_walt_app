<?php

namespace Tests\Fakes;

use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for the Stripe SDK's HTTP layer so tests exercise the real
 * \Stripe\* static calls without touching the network.
 *
 * Responses are matched by "METHOD /path-prefix" and consumed in order; an
 * unmatched request fails the test loudly instead of reaching Stripe.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var array<int, array{method: string, path: string, params: array, headers: array}> */
    public array $requests = [];

    /** @var array<string, array<int, array{0: int, 1: array}>> */
    private array $responses = [];

    public function respond(string $route, array $body, int $status = 200): self
    {
        $this->responses[$route][] = [$status, $body];

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $method = strtoupper($method);
        $path = parse_url($absUrl, PHP_URL_PATH);

        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params ?? [], 'headers' => $headers ?? []];

        foreach ($this->responses as $route => $queue) {
            [$routeMethod, $prefix] = explode(' ', $route, 2);

            if ($routeMethod === $method && str_starts_with($path, $prefix) && $queue !== []) {
                [$status, $body] = array_shift($this->responses[$route]);

                return [json_encode($body), $status, ['request-id' => 'req_fake']];
            }
        }

        throw new \LogicException("Unexpected Stripe request: {$method} {$path}");
    }

    /**
     * The Idempotency-Key header Stripe received with a request, if any.
     */
    public static function idempotencyKey(array $request): ?string
    {
        foreach ($request['headers'] as $header) {
            if (stripos($header, 'Idempotency-Key:') === 0) {
                return trim(substr($header, strlen('Idempotency-Key:')));
            }
        }

        return null;
    }

    /** @return array<int, array{method: string, path: string, params: array, headers: array}> */
    public function requestsTo(string $method, string $prefix): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (array $r) => $r['method'] === $method && str_starts_with($r['path'], $prefix)
        ));
    }
}
