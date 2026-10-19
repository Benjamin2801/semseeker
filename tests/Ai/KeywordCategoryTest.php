<?php

namespace App\Tests\Ai;

use App\Ai\KeywordCategory;
use PHPUnit\Framework\TestCase;

final class KeywordCategoryTest extends TestCase
{
    public function testAKeywordSetAsideIsToBeChecked(): void
    {
        self::assertSame(KeywordCategory::Doubtful, KeywordCategory::decide(KeywordCategory::Core, 'pompe à chaleur', true));
    }

    public function testPriceQuoteOrPurchaseWordsMakeACommercialOpportunity(): void
    {
        self::assertSame(KeywordCategory::Commercial, KeywordCategory::decide(KeywordCategory::Core, 'pompe à chaleur prix', false));
        self::assertSame(KeywordCategory::Commercial, KeywordCategory::decide(KeywordCategory::Complementary, 'devis pompe à chaleur', false));
        self::assertSame(KeywordCategory::Commercial, KeywordCategory::decide(null, 'heat pump cost', false));
    }

    public function testOtherwiseTheModelDecides(): void
    {
        self::assertSame(KeywordCategory::Complementary, KeywordCategory::decide(KeywordCategory::Complementary, 'comment installer une pompe à chaleur', false), '« installer » est une question, pas un achat.');
        self::assertNull(KeywordCategory::decide(null, 'pompe à chaleur air eau', false));
        self::assertSame(KeywordCategory::Core, KeywordCategory::decide(KeywordCategory::Core, 'meilleur grille pain 4 tranches', false, 'meilleur grille pain'), 'Le mot commercial de l\'expression ne décide pas.');
        self::assertSame(KeywordCategory::Commercial, KeywordCategory::decide(KeywordCategory::Core, 'meilleur grille pain prix', false, 'meilleur grille pain'));
    }
}
