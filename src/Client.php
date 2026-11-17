<?php

declare(strict_types=1);

namespace Mediloop;

/**
 * The Mediloop API client.
 *
 *     $mediloop = new Mediloop\Client(apiKey: 'sk_live_...');
 *     $facility = $mediloop->forFacility($facilityId)->onBehalfOf($clinicianUhid);
 *     $found = $facility->post('/api/v1/uhid/integrations/identify/', ['nin' => 'CM...']);
 *
 * What it does for you, and nothing more:
 *  - exchanges your API key for a short-lived token, keeps it, and renews it before it ends;
 *  - sends X-Facility-ID and X-On-Behalf-Of when you scope the client;
 *  - gives every write an Idempotency-Key, and re-sends the SAME key on a retry, so a retry can never write twice;
 *  - retries a network failure, 429 and 502/503/504 with a pause, and never retries any other refusal;
 *  - turns a refusal into an exception that carries Mediloop's reason code.
 * It has no dependencies beyond PHP's own curl and json.
 */
final class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.mediloop.co';

    private string $baseUrl;
    private Transport $transport;
    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly ?string $facilityId = null,
        private readonly ?string $onBehalfOf = null,
        private readonly float $timeout = 30.0,
        private readonly int $maxRetries = 2,
        ?Transport $transport = null,
        private readonly ?\Closure $sleeper = null,
    ) {
        if ($apiKey === '') {
            throw new MediloopException('An API key is required.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        if (!str_starts_with($this->baseUrl, 'https://') && !str_starts_with($this->baseUrl, 'http://localhost')) {
            throw new MediloopException('The base URL must be https.');
        }
        $this->transport = $transport ?? new CurlTransport();
    }

    /** A copy that acts for one facility (X-Facility-ID). */
    public function forFacility(string $facilityId): self
    {
        return $this->copy(facilityId: $facilityId);
    }

    /** A copy that names the person doing the work (X-On-Behalf-Of): a clinician, a cashier, an insurer's officer. */
    public function onBehalfOf(string $uhid): self
    {
        return $this->copy(onBehalfOf: $uhid);
    }

    /** @param array<string,mixed> $query */
    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, query: $query);
    }

    /** @param array<string,mixed> $json */
    public function post(string $path, array $json = [], ?string $idempotencyKey = null): mixed
    {
        return $this->request('POST', $path, json: $json, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string,mixed> $json */
    public function patch(string $path, array $json = [], ?string $idempotencyKey = null): mixed
    {
        return $this->request('PATCH', $path, json: $json, idempotencyKey: $idempotencyKey);
    }

    /** @param array<string,mixed> $json */
    public function put(string $path, array $json = [], ?string $idempotencyKey = null): mixed
    {
        return $this->request('PUT', $path, json: $json, idempotencyKey: $idempotencyKey);
    }

    /**
     * Every item of a paged list, following "next" until it ends.
     *
     * @param array<string,mixed> $query
     * @return \Generator<int,mixed>
     */
    public function paginate(string $path, array $query = []): \Generator
    {
        $page = $this->get($path, $query);
        while (true) {
            foreach (($page['results'] ?? []) as $item) {
                yield $item;
            }
            $next = $page['next'] ?? null;
            if (!is_string($next) || $next === '') {
                return;
            }
            // Follow the link, but only to Mediloop: a link must never take your token elsewhere.
            if (!str_starts_with($next, $this->baseUrl . '/')) {
                throw new MediloopException('A paging link pointed outside Mediloop and was not followed.');
            }
            $page = $this->request('GET', substr($next, strlen($this->baseUrl)));
        }
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed>|null $json
     * @param array<string,string> $headers
     */
    public function request(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): mixed {
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $body = $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $isWrite = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        // One key for the whole call, retries included: that is what makes a retry safe.
        $key = $isWrite ? ($idempotencyKey ?? self::uuid()) : null;

        $renewed = false;
        $attempt = 0;
        while (true) {
            $sent = [
                'Accept' => 'application/json',
                'User-Agent' => 'mediloop-php/' . self::VERSION,
                'Authorization' => 'Bearer ' . $this->accessToken(),
            ] + $headers;
            if ($body !== null) {
                $sent['Content-Type'] = 'application/json';
            }
            if ($this->facilityId !== null) {
                $sent['X-Facility-ID'] = $this->facilityId;
            }
            if ($this->onBehalfOf !== null) {
                $sent['X-On-Behalf-Of'] = $this->onBehalfOf;
            }
            if ($key !== null) {
                $sent['Idempotency-Key'] = $key;
            }

            try {
                $response = $this->transport->send($method, $url, $sent, $body, $this->timeout);
            } catch (ConnectionException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                $this->pause($attempt++, null);
                continue;
            }

            $status = $response['status'];
            if ($status === 401 && !$renewed) {
                // The token may have been revoked or expired early: get a new one once.
                $renewed = true;
                $this->token = null;
                continue;
            }
            if (in_array($status, [429, 502, 503, 504], true) && $attempt < $this->maxRetries) {
                $this->pause($attempt++, $response['headers']['retry-after'] ?? null);
                continue;
            }
            return $this->decode($status, $response);
        }
    }

    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExpiresAt - 30) {
            return $this->token;
        }
        $body = json_encode(['api_key' => $this->apiKey], JSON_THROW_ON_ERROR);
        $attempt = 0;
        while (true) {
            try {
                $response = $this->transport->send(
                    'POST',
                    $this->baseUrl . '/api/v1/auth/token/',
                    ['Content-Type' => 'application/json', 'Accept' => 'application/json',
                     'User-Agent' => 'mediloop-php/' . self::VERSION],
                    $body,
                    $this->timeout,
                );
            } catch (ConnectionException $e) {
                if ($attempt >= $this->maxRetries) {
                    throw $e;
                }
                $this->pause($attempt++, null);
                continue;
            }
            if (in_array($response['status'], [429, 502, 503, 504], true) && $attempt < $this->maxRetries) {
                $this->pause($attempt++, $response['headers']['retry-after'] ?? null);
                continue;
            }
            break;
        }
        $data = $this->decode($response['status'], $response);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new MediloopException('Mediloop did not return a token.');
        }
        $this->token = (string) $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 300);
        return $this->token;
    }

    /** @param array{status:int, headers:array<string,string>, body:string} $response */
    private function decode(int $status, array $response): mixed
    {
        $raw = $response['body'];
        $data = $raw === '' ? null : json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return $data;
        }
        $code = is_array($data) ? ($data['code'] ?? null) : null;
        $message = is_array($data)
            ? (string) ($data['error'] ?? $data['detail'] ?? $data['message'] ?? 'Mediloop refused the request.')
            : 'Mediloop refused the request.';
        $retryAfter = $response['headers']['retry-after'] ?? null;
        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status === 400 || $status === 422 => ValidationException::class,
            $status >= 500 => ServerException::class,
            default => ApiException::class,
        };
        throw new $class($status, is_string($code) ? $code : null, $message, $data, $retryAfter);
    }

    private function pause(int $attempt, ?string $retryAfter): void
    {
        $seconds = is_numeric($retryAfter) ? min(30.0, (float) $retryAfter) : min(8.0, 0.5 * (2 ** $attempt)) + (mt_rand(0, 250) / 1000);
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
            return;
        }
        usleep((int) ($seconds * 1_000_000));
    }

    private function copy(?string $facilityId = null, ?string $onBehalfOf = null): self
    {
        $clone = new self(
            $this->apiKey,
            $this->baseUrl,
            $facilityId ?? $this->facilityId,
            $onBehalfOf ?? $this->onBehalfOf,
            $this->timeout,
            $this->maxRetries,
            $this->transport,
            $this->sleeper,
        );
        $clone->token = $this->token;
        $clone->tokenExpiresAt = $this->tokenExpiresAt;
        return $clone;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
