<?php

declare(strict_types=1);

namespace Mediloop;

/** Sends one HTTP request. Swap it for your own (a framework client, or a fake in tests). */
interface Transport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     * @throws ConnectionException when no answer came back at all
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array;
}
