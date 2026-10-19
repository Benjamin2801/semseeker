<?php

namespace App\Search;

final class TooManySearchesException extends \RuntimeException
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Limite de recherches atteinte.');
    }
}
