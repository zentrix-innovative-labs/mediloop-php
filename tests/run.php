<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Mediloop\{Client, Transport, Webhook, Pkce, ConnectionException, MediloopException, ApiException, NotFoundException, PermissionException, RateLimitException, ValidationException, AuthenticationException};

final class Fake implements Transport
{
    /** @var list<array{string,string,array<string,string>,?string}> */
    public array $calls = [];
    /** @param list<array{status:int,headers?:array<string,string>,body?:string}|ConnectionException> $script */
    public function __construct(public array $script)
    {
    }
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $this->calls[] = [$method, $url, $headers, $body];
        $next = array_shift($this->script);
        if ($next instanceof ConnectionException) {
            throw $next;
        }
        if ($next === null) {
            throw new \LogicException('Unexpected call: ' . $method . ' ' . $url);
        }
        return ['status' => $next['status'], 'headers' => $next['headers'] ?? [], 'body' => $next['body'] ?? ''];
    }
}

$failures = 0;
function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}
function raises(callable $fn, string $class): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class ? $e : null;
    }
    return null;
}

$tok = ['status' => 200, 'body' => json_encode(['access_token' => 'tok1', 'expires_in' => 600])];
$ok = ['status' => 200, 'body' => '{"results":[1]}'];
$slept = [];
$sleep = function (float $s) use (&$slept): void { $slept[] = $s; };
$make = fn (Fake $t, array $extra = []) => new Client(...array_merge(['apiKey' => 'sk_live_x', 'transport' => $t, 'sleeper' => $sleep], $extra));

// token is fetched once and reused
$t = new Fake([$tok, $ok, $ok]);
$c = $make($t);
$c->get('/api/v1/a/');
$c->get('/api/v1/b/');
check('token fetched once for two calls', count($t->calls) === 3 && str_ends_with($t->calls[0][1], '/api/v1/auth/token/'));
check('bearer token is sent', ($t->calls[1][2]['Authorization'] ?? '') === 'Bearer tok1');
check('a read carries no idempotency key', !isset($t->calls[1][2]['Idempotency-Key']));

// scoping headers
$t = new Fake([$tok, $ok]);
$make($t)->forFacility('F1')->onBehalfOf('UG1')->get('/api/v1/x/');
check('facility and person headers', ($t->calls[1][2]['X-Facility-ID'] ?? '') === 'F1' && ($t->calls[1][2]['X-On-Behalf-Of'] ?? '') === 'UG1');

// a write has a key, and a retry re-sends the SAME key
$t = new Fake([$tok, ['status' => 503], ['status' => 502], ['status' => 201, 'body' => '{"id":"r1"}']]);
$r = $make($t)->post('/api/v1/things/', ['a' => 1]);
$keys = array_map(fn ($c) => $c[2]['Idempotency-Key'] ?? null, array_slice($t->calls, 1));
check('write retried to success', $r === ['id' => 'r1'] && count($t->calls) === 4);
check('the same idempotency key on every attempt', count(array_unique($keys)) === 1 && $keys[0] !== null);
check('a given key is honoured', (function () use ($tok, $make) {
    $t = new Fake([$tok, ['status' => 201, 'body' => '{}']]);
    $make($t)->post('/x/', [], 'mine-1');
    return $t->calls[1][2]['Idempotency-Key'] === 'mine-1';
})());

// network failure is retried, then raised
$t = new Fake([$tok, new ConnectionException('down'), new ConnectionException('down'), new ConnectionException('down')]);
check('connection failure raised after retries', raises(fn () => $make($t)->get('/x/'), ConnectionException::class) !== null && count($t->calls) === 4);

// 429 honours Retry-After
$slept = [];
$t = new Fake([$tok, ['status' => 429, 'headers' => ['retry-after' => '3']], $ok]);
$make($t)->get('/x/');
check('Retry-After is respected', $slept === [3.0]);

// other refusals are not retried, and carry the reason
$t = new Fake([$tok, ['status' => 403, 'body' => '{"error":"This route is not open to outside software.","code":"route_not_open"}']]);
$e = raises(fn () => $make($t)->get('/x/'), PermissionException::class);
check('403 is a PermissionException with the reason code', $e instanceof ApiException && $e->errorCode === 'route_not_open' && count($t->calls) === 2);
$t = new Fake([$tok, ['status' => 404, 'body' => '{"detail":"No Claim matches the given query."}']]);
check('404 is NotFoundException', raises(fn () => $make($t)->get('/x/'), NotFoundException::class) !== null);
$t = new Fake([$tok, ['status' => 400, 'body' => '{"message":"bad"}']]);
check('400 is ValidationException, not retried', raises(fn () => $make($t)->post('/x/', []), ValidationException::class) !== null && count($t->calls) === 2);
$t = new Fake([$tok, ['status' => 429], ['status' => 429], ['status' => 429]]);
check('429 after retries is RateLimitException', raises(fn () => $make($t)->get('/x/'), RateLimitException::class) !== null);

// a 401 renews the token once
$t = new Fake([$tok, ['status' => 401, 'body' => '{"error":"expired"}'], ['status' => 200, 'body' => json_encode(['access_token' => 'tok2', 'expires_in' => 600])], $ok]);
$make($t)->get('/x/');
check('401 renews the token once', ($t->calls[3][2]['Authorization'] ?? '') === 'Bearer tok2');
$t = new Fake([$tok, ['status' => 401], ['status' => 200, 'body' => json_encode(['access_token' => 'tok2', 'expires_in' => 600])], ['status' => 401]]);
check('a second 401 is raised', raises(fn () => $make($t)->get('/x/'), AuthenticationException::class) !== null);

// paging
$t = new Fake([$tok,
    ['status' => 200, 'body' => json_encode(['results' => [1, 2], 'next' => 'https://api.mediloop.co/api/v1/p/?page=2'])],
    ['status' => 200, 'body' => json_encode(['results' => [3], 'next' => null])]]);
check('paginate follows next', iterator_to_array($make($t)->paginate('/api/v1/p/'), false) === [1, 2, 3]);
$t = new Fake([$tok, ['status' => 200, 'body' => json_encode(['results' => [1], 'next' => 'https://evil.example/steal'])]]);
check('a paging link off Mediloop is refused', raises(function () use ($make, $t) { foreach ($make($t)->paginate('/api/v1/p/') as $_) { } }, MediloopException::class) !== null);

// settings
check('http base URL is refused', raises(fn () => new Client('k', 'http://api.mediloop.co'), MediloopException::class) !== null);
check('an empty key is refused', raises(fn () => new Client(''), MediloopException::class) !== null);

// webhooks
$secret = 'whsec_test'; $body = '{"event":"payment.succeeded"}'; $now = 1763366400;
$sig = fn (string $b, int $ts, string $s) => "t=$ts,v1=" . hash_hmac('sha256', "$ts.$b", $s);
check('a good webhook verifies', raises(fn () => Webhook::verify($body, $sig($body, $now, $secret), $secret, 300, $now), \Throwable::class) === null);
check('a changed body is refused', raises(fn () => Webhook::verify($body . ' ', $sig($body, $now, $secret), $secret, 300, $now), MediloopException::class) !== null);
check('a wrong secret is refused', raises(fn () => Webhook::verify($body, $sig($body, $now, 'other'), $secret, 300, $now), MediloopException::class) !== null);
check('an old delivery is refused', raises(fn () => Webhook::verify($body, $sig($body, $now - 1000, $secret), $secret, 300, $now), MediloopException::class) !== null);
check('a second signature is accepted', raises(fn () => Webhook::verify($body, 't=' . $now . ',v1=bad,v1=' . hash_hmac('sha256', "$now.$body", $secret), $secret, 300, $now), \Throwable::class) === null);
check('a malformed header is refused', raises(fn () => Webhook::verify($body, 'garbage', $secret, 300, $now), MediloopException::class) !== null);

// PKCE
$p = Pkce::start();
check('the challenge is the SHA-256 of the verifier', $p['challenge'] === rtrim(strtr(base64_encode(hash('sha256', $p['verifier'], true)), '+/', '-_'), '='));
$url = Pkce::authorizeUrl('ext-app', 'https://app.example/cb', $p['challenge'], $p['state']);
check('the sign-in address names S256 and the state', str_contains($url, 'code_challenge_method=S256') && str_contains($url, 'state=' . $p['state']));

$t = new Fake([['status' => 200, 'body' => json_encode(['access_token' => 'a', 'refresh_token' => 'r'])], ['status' => 200, 'body' => json_encode(['access_token' => 'b'])], ['status' => 400, 'body' => '{}']]);
$tokens = Pkce::exchange('ext-app', 'https://app.example/cb', 'code1', $p['verifier'], $t, 'secret1');
check('the code exchange sends the client secret and the verifier', $tokens['access_token'] === 'a' && str_contains($t->calls[0][3], 'client_secret=secret1') && str_contains($t->calls[0][3], 'code_verifier='));
check('a refresh token gets a new access token', Pkce::refresh('ext-app', 'r', $t, 'secret1')['access_token'] === 'b');
check('a refused refresh is raised', raises(fn () => Pkce::refresh('ext-app', 'r', $t, 'secret1'), MediloopException::class) !== null);
echo $failures === 0 ? "\nAll passed.\n" : "\n$failures failed.\n";
exit($failures === 0 ? 0 : 1);
