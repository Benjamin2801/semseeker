<?php

namespace App\Tests\Search;

use App\Search\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    public function testFingerprintsTheShapeOfTheCachedObjects(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', SearchResult::format());
        self::assertSame(SearchResult::format(), SearchResult::format(), 'Stable tant que les objets ne changent pas.');
    }
}
