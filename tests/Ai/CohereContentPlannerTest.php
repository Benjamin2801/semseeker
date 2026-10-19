<?php

namespace App\Tests\Ai;

use App\Ai\AiException;
use App\Ai\CohereChat;
use App\Ai\CohereContentPlanner;
use App\Ai\Intent;
use App\Ai\KeywordCategory;
use App\Ai\Plan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CohereContentPlannerTest extends TestCase
{
    private const STRUCTURE = ['groups' => [
        ['name' => 'Portes de garage', 'pages' => [
            ['main_keyword' => 'installation porte de garage', 'intent' => 'commercial', 'sections' => ['Porte sectionnelle', 'Porte basculante']],
        ]],
        ['name' => 'Achat', 'pages' => [
            ['main_keyword' => 'porte de garage leroy merlin', 'intent' => 'navigational', 'sections' => []],
        ]],
    ]];

    private const KEYWORDS = ['installation porte de garage', 'porte sectionnelle pose', 'porte basculante', 'porte de garage leroy merlin'];

    private const SCORES = [62, 41, 33, 30];

    public function testDesignsTheStructureThenAssignsEachKeyword(): void
    {
        $bodies = [];
        $durations = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, &$durations): MockResponse {
            $bodies[] = json_decode($options['body'], true);
            $durations[] = $options['max_duration'];

            return 1 === \count($bodies) ? self::reply(self::STRUCTURE) : self::reply(['results' => [
                ['id' => 0, 'page' => 'P1', 'section' => ''],
                ['id' => 1, 'page' => 'P1', 'section' => 'S1'],
                ['id' => 2, 'page' => 'p1', 'section' => '2'],
                ['id' => 3, 'page' => 'P2', 'section' => ''],
            ]]);
        });

        $planner = new CohereContentPlanner(new CohereChat($client, 'key', 'model'));
        $plan = $planner->assign($planner->draft('porte', self::KEYWORDS, self::SCORES, 'fr', 10));

        self::assertSame([10.0, 10.0], $durations, 'La structure prend le temps accordé, le rangement le sien.');
        self::assertStringContainsString("Target number of pages: 3\nKeywords (popularity):\ninstallation porte de garage (62)\n", $bodies[0]['messages'][1]['content']);
        self::assertStringContainsString("Plan:\nP1. installation porte de garage\n  S1. Porte sectionnelle\n  S2. Porte basculante\nP2. porte de garage leroy merlin\n", $bodies[1]['messages'][1]['content']);

        self::assertSame([
            ['name' => 'Portes de garage', 'pages' => [[
                'title' => 'installation porte de garage',
                'intent' => Intent::Commercial,
                'keywords' => [0],
                'sections' => [['title' => 'Porte sectionnelle', 'keywords' => [1]], ['title' => 'Porte basculante', 'keywords' => [2]]],
            ]]],
            ['name' => 'Achat', 'pages' => [['title' => 'porte de garage leroy merlin', 'intent' => null, 'keywords' => [3], 'sections' => []]]],
        ], $plan->groups, 'Une intention hors de la liste est ignorée.');
        self::assertSame([], $plan->rejected);
    }

    public function testInvalidCodesFallBackToThePageOrAreIgnored(): void
    {
        $plan = self::plan([self::reply(self::STRUCTURE), self::reply(['results' => [
            ['id' => 0, 'page' => 'P1', 'section' => 'S9'],
            ['id' => 1, 'page' => 'P7', 'section' => ''],
            ['id' => 9, 'page' => 'P1', 'section' => ''],
        ]])]);

        self::assertSame([0], $plan->groups[0]['pages'][0]['keywords'], 'Section inconnue : le mot-clé reste au niveau de la page.');
        self::assertSame([], $plan->groups[1]['pages'][0]['keywords'], 'Page inconnue ou numéro invalide : ignoré.');
        self::assertSame([], $plan->rejected, 'Un mot-clé ignoré n\'est pas pour autant écarté.');
    }

    public function testAFailedAssignmentIsAnAiException(): void
    {
        $this->expectException(AiException::class);

        self::plan([self::reply(self::STRUCTURE), new MockResponse('', ['http_code' => 500])]);
    }

    public function testAFailedStructureIsAnAiException(): void
    {
        $this->expectException(AiException::class);

        self::plan([new MockResponse('', ['http_code' => 503])]);
    }

    public function testSetsAsideTheKeywordsTheModelRejects(): void
    {
        $plan = self::plan([self::reply(self::STRUCTURE), self::reply(['results' => [
            ['id' => 0, 'page' => 'P1', 'section' => '', 'category' => 'core'],
            ['id' => 1, 'page' => 'None', 'section' => '', 'category' => 'doubtful'],
            ['id' => 3, 'page' => 'none', 'section' => 'S1'],
        ]])]);

        self::assertSame([1, 3], $plan->rejected);
        self::assertSame([0 => KeywordCategory::Core, 1 => KeywordCategory::Doubtful], $plan->categories, 'Un mot-clé écarté garde sa catégorie.');
        self::assertSame([0], $plan->groups[0]['pages'][0]['keywords']);
    }

    public function testOnlyPopularKeywordsDrawTheStructureButAllAreAssigned(): void
    {
        $bodies = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode($options['body'], true);

            return 1 === \count($bodies) ? self::reply(self::STRUCTURE) : self::reply(['results' => []]);
        });
        $planner = new CohereContentPlanner(new CohereChat($client, 'key', 'model'));

        $planner->assign($planner->draft('porte', self::KEYWORDS, [62, 41, 0, 5], 'fr', 10));

        self::assertStringEndsWith("Keywords (popularity):\ninstallation porte de garage (62)\nporte sectionnelle pose (41)\n", $bodies[0]['messages'][1]['content']);
        self::assertStringContainsString("Count: 4\nCore target: 1\nKeywords:\n0. installation porte de garage\n1. porte sectionnelle pose\n2. porte basculante\n3. porte de garage leroy merlin\n", $bodies[1]['messages'][1]['content']);
    }

    public function testNeedsAtLeastTwoKeywords(): void
    {
        $client = new MockHttpClient();

        $planner = new CohereContentPlanner(new CohereChat($client, 'key', 'model'));
        self::assertSame([], $planner->assign($planner->draft('porte', ['porte'], [10], 'fr', 10))->groups);
        self::assertSame(0, $client->getRequestsCount());
    }

    /** @param list<MockResponse> $responses */
    private static function plan(array $responses): Plan
    {
        $planner = new CohereContentPlanner(new CohereChat(new MockHttpClient($responses), 'key', 'model'));

        return $planner->assign($planner->draft('porte', self::KEYWORDS, self::SCORES, 'fr', 10));
    }

    /** @param array<string, mixed> $json */
    private static function reply(array $json): MockResponse
    {
        return new MockResponse(json_encode(['message' => ['content' => [['type' => 'text', 'text' => json_encode($json, JSON_THROW_ON_ERROR)]]]], \JSON_THROW_ON_ERROR));
    }
}
