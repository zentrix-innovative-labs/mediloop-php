<?php

declare(strict_types=1);

namespace Mediloop;

/** Checks that a webhook really came from Mediloop and was not changed or replayed. */
final class Webhook
{
    /**
     * @param string $rawBody the body exactly as received. Do not decode and re-encode it first.
     * @param string $signatureHeader the X-MediLoop-Signature header, "t=<seconds>,v1=<hex>"
     * @param int $toleranceSeconds how old a delivery may be
     * @throws MediloopException when the delivery is not authentic
     */
    public static function verify(string $rawBody, string $signatureHeader, string $secret, int $toleranceSeconds = 300, ?int $now = null): void
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $signatureHeader) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }
        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            throw new MediloopException('The webhook signature header is not valid.');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        $matched = false;
        foreach ($signatures as $candidate) {
            // Compare every candidate in constant time, and do not stop at the first.
            $matched = hash_equals($expected, $candidate) || $matched;
        }
        if (!$matched) {
            throw new MediloopException('The webhook signature does not match.');
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            throw new MediloopException('The webhook is too old. It may be a replay.');
        }
    }

    /** The decoded event, after it has been verified. @return array<string,mixed> */
    public static function event(string $rawBody, string $signatureHeader, string $secret, int $toleranceSeconds = 300): array
    {
        self::verify($rawBody, $signatureHeader, $secret, $toleranceSeconds);
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new MediloopException('The webhook body is not JSON.');
        }
        return $data;
    }
}
