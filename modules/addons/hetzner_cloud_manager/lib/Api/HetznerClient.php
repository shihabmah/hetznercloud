<?php
/**
 * Hetzner Cloud Manager - Resilient Hetzner Cloud API v1 Client
 *
 * Thin Guzzle wrapper around https://api.hetzner.cloud/v1/ providing:
 *  - Per-account bearer token resolution (multi-project support)
 *  - Automatic pagination helper for list endpoints
 *  - HTTP 429 handling via exponential backoff with jitter
 *  - Rate-limit headroom tracking persisted back to mod_hetzner_cloud_accounts
 *  - Structured logModuleCall() logging for WHMCS admin "Module Log"
 *
 * @package HetznerCloudManager\Api
 */

namespace HetznerCloudManager\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use WHMCS\Database\Capsule;
use Exception;

class HetznerClient
{
    protected Client $http;
    protected string $apiToken;
    protected int $accountId;
    protected string $baseUrl = 'https://api.hetzner.cloud/v1/';
    protected int $maxAttempts = 5;
    protected bool $logCalls = true;

    /**
     * In-process cache of already-instantiated clients per account,
     * so a single request lifecycle doesn't reconnect repeatedly.
     */
    protected static array $instances = [];

    public function __construct(int $accountId)
    {
        $this->accountId = $accountId;

        $account = Capsule::table('mod_hetzner_cloud_accounts')->where('id', $accountId)->first();
        if (!$account) {
            throw new Exception("Hetzner Cloud account #{$accountId} was not found.");
        }
        if (!$account->is_active) {
            throw new Exception("Hetzner Cloud account '{$account->account_name}' is disabled.");
        }
        if (empty($account->api_token)) {
            throw new Exception("Hetzner Cloud account '{$account->account_name}' has no API token configured.");
        }

        $this->apiToken = $account->api_token;

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => 30.0,
            'connect_timeout' => 10.0,
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'WHMCS-Hetzner-Cloud-Manager/1.0 (+https://github.com)',
            ],
        ]);
    }

    /**
     * Get (and cache) a client instance for the given account id.
     */
    public static function forAccount(int $accountId): self
    {
        if (!isset(self::$instances[$accountId])) {
            self::$instances[$accountId] = new self($accountId);
        }

        return self::$instances[$accountId];
    }

    /**
     * Return a client bound to the first active account, optionally
     * picking the "least recently rate-limited" project when several
     * accounts are active (basic load balancing across projects).
     */
    public static function forAnyActiveAccount(): self
    {
        $account = Capsule::table('mod_hetzner_cloud_accounts')
            ->where('is_active', 1)
            ->orderBy('rate_limit_remaining', 'desc')
            ->first();

        if (!$account) {
            throw new Exception('No active Hetzner Cloud accounts are configured.');
        }

        return self::forAccount($account->id);
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    /**
     * Perform a raw API request with automatic 429 retry (exponential
     * backoff + jitter) and rate-limit header tracking.
     *
     * @param string $method  GET, POST, PUT, DELETE
     * @param string $endpoint  Relative to /v1/, e.g. "servers" or "servers/123"
     * @param array  $payload  Query params for GET, JSON body otherwise
     * @param int    $attempt  Internal retry counter, do not set manually
     * @return array Decoded JSON response body
     */
    public function request(string $method, string $endpoint, array $payload = [], int $attempt = 1)
    {
        $method = strtoupper($method);
        $startedAt = microtime(true);

        try {
            $options = [];
            if (!empty($payload)) {
                $options[$method === 'GET' ? 'query' : 'json'] = $payload;
            }

            $response = $this->http->request($method, $endpoint, $options);

            $this->trackRateLimit($response);
            $this->logCall($method, $endpoint, $payload, $response->getStatusCode(), $startedAt);

            $body = (string) $response->getBody();
            return $body === '' ? [] : json_decode($body, true);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $status = $e->getResponse()->getStatusCode();

                // Rate limited: exponential backoff with jitter, then retry.
                if ($status === 429 && $attempt <= $this->maxAttempts) {
                    $this->trackRateLimit($e->getResponse());
                    $this->sleepWithBackoff($attempt, $e->getResponse());
                    return $this->request($method, $endpoint, $payload, $attempt + 1);
                }

                $decoded = json_decode((string) $e->getResponse()->getBody(), true);
                $message = $decoded['error']['message'] ?? $e->getMessage();
                $code = $decoded['error']['code'] ?? (string) $status;

                $this->logCall($method, $endpoint, $payload, $status, $startedAt, $message);

                throw new Exception("Hetzner API error [{$code}]: {$message}", $status);
            }

            $this->logCall($method, $endpoint, $payload, 0, $startedAt, $e->getMessage());
            throw new Exception('Hetzner API request failed: ' . $e->getMessage());
        } catch (ConnectException $e) {
            if ($attempt <= $this->maxAttempts) {
                $this->sleepWithBackoff($attempt, null);
                return $this->request($method, $endpoint, $payload, $attempt + 1);
            }

            $this->logCall($method, $endpoint, $payload, 0, $startedAt, $e->getMessage());
            throw new Exception('Unable to reach the Hetzner Cloud API: ' . $e->getMessage());
        }
    }

    /**
     * Follow `meta.pagination.next_page` across all pages and merge
     * the requested list key (e.g. "server_types", "servers").
     */
    public function requestAll(string $endpoint, string $listKey, array $payload = []): array
    {
        $items = [];
        $page = 1;
        $payload['per_page'] = $payload['per_page'] ?? 50;

        do {
            $payload['page'] = $page;
            $result = $this->request('GET', $endpoint, $payload);
            $items = array_merge($items, $result[$listKey] ?? []);
            $nextPage = $result['meta']['pagination']['next_page'] ?? null;
            $page = $nextPage;
        } while (!empty($nextPage));

        return $items;
    }

    protected function sleepWithBackoff(int $attempt, $response): void
    {
        // Exponential backoff: 2^attempt seconds, capped, plus random jitter.
        $base = min(pow(2, $attempt), 30);
        $jitter = mt_rand(0, 1000) / 1000; // 0.0 - 1.0s
        $delay = $base + $jitter;

        // Respect RateLimit-Reset if the server told us exactly when to retry.
        if ($response && $response->hasHeader('RateLimit-Reset')) {
            $resetAt = (int) $response->getHeaderLine('RateLimit-Reset');
            $secondsUntilReset = $resetAt - time();
            if ($secondsUntilReset > 0 && $secondsUntilReset < 60) {
                $delay = max($delay, $secondsUntilReset + $jitter);
            }
        }

        usleep((int) ($delay * 1_000_000));
    }

    protected function trackRateLimit($response): void
    {
        if (!$response || !$response->hasHeader('RateLimit-Remaining')) {
            return;
        }

        $remaining = (int) $response->getHeaderLine('RateLimit-Remaining');
        $reset = (int) $response->getHeaderLine('RateLimit-Reset');

        Capsule::table('mod_hetzner_cloud_accounts')->where('id', $this->accountId)->update([
            'rate_limit_remaining' => $remaining,
            'rate_limit_reset'     => $reset,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
    }

    protected function logCall(string $method, string $endpoint, array $payload, int $statusCode, float $startedAt, ?string $error = null): void
    {
        if (!$this->logCalls || !function_exists('logModuleCall')) {
            return;
        }

        $durationMs = round((microtime(true) - $startedAt) * 1000);

        logModuleCall(
            'hetzner_cloud_manager',
            "{$method} {$endpoint}",
            json_encode($this->redact($payload)),
            $error ? "HTTP {$statusCode} ({$durationMs}ms): {$error}" : "HTTP {$statusCode} ({$durationMs}ms)",
            '',
            ['api_token' => $this->apiToken]
        );
    }

    /**
     * Strip anything sensitive before writing request payloads to the log.
     */
    protected function redact(array $payload): array
    {
        foreach (['public_key', 'user_data', 'password'] as $sensitiveKey) {
            if (isset($payload[$sensitiveKey])) {
                $payload[$sensitiveKey] = '[redacted]';
            }
        }
        return $payload;
    }

    // ---------------------------------------------------------------
    // Convenience wrappers for commonly used read-only catalog data
    // ---------------------------------------------------------------

    public function getServerTypes(): array
    {
        return $this->requestAll('server_types', 'server_types');
    }

    public function getDatacenters(): array
    {
        return $this->requestAll('datacenters', 'datacenters');
    }

    public function getLocations(): array
    {
        return $this->requestAll('locations', 'locations');
    }

    public function getImages(array $filters = []): array
    {
        $filters = array_merge(['status' => 'available'], $filters);
        return $this->requestAll('images', 'images', $filters);
    }

    public function getPricing(): array
    {
        return $this->request('GET', 'pricing');
    }

    public function getServers(array $filters = []): array
    {
        return $this->requestAll('servers', 'servers', $filters);
    }

    public function getServer(int $id): array
    {
        return $this->request('GET', "servers/{$id}")['server'] ?? [];
    }

    public function createServer(array $params): array
    {
        return $this->request('POST', 'servers', $params)['server'] ?? [];
    }

    public function deleteServer(int $id): array
    {
        return $this->request('DELETE', "servers/{$id}");
    }

    public function serverAction(int $id, string $action, array $params = []): array
    {
        return $this->request('POST', "servers/{$id}/actions/{$action}", $params);
    }
}
