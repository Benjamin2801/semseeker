<?php

namespace App\Suggest;

use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class SourceFailure
{
    public const TIMEOUT = 'timeout';
    public const BLOCKED = 'blocked';
    public const RATE_LIMITED = 'rate_limited';
    public const SERVER_ERROR = 'server_error';
    public const INVALID_RESPONSE = 'invalid_response';
    public const NETWORK = 'network';

    public function __construct(
        public readonly string $source,

        public readonly string $acronym,

        public readonly string $reason,

        public readonly string $detail = '',
    ) {
    }

    public static function reasonOf(\Throwable $error): string
    {
        return match (true) {
            $error instanceof TimeoutExceptionInterface => self::TIMEOUT,
            $error instanceof HttpExceptionInterface => match ($error->getResponse()->getStatusCode()) {
                401, 403 => self::BLOCKED,
                429 => self::RATE_LIMITED,
                default => $error->getResponse()->getStatusCode() >= 500 ? self::SERVER_ERROR : self::INVALID_RESPONSE,
            },

            $error instanceof TransportExceptionInterface => str_contains($error->getMessage(), 'timed out') ? self::TIMEOUT : self::NETWORK,
            default => self::INVALID_RESPONSE,
        };
    }
}
