<?php

namespace App\Search;

use App\Suggest\SuggestionMerger;

/**
 * Ce que les règles sans IA savent du sens d'une recherche : l'expression, et les noms que Wikipédia y reconnaît.
 * « Prix Goncourt », « Test de grossesse » ou « Nouvelle-Calédonie » sont des noms : les mots qui les composent ne sont
 * ni des indices (prix, test, nouvelle) ni des villes. Indices, filtre, garde-fou d'intention et catégorie commerciale
 * lisent tous le texte à travers ce contexte.
 */
final class LexicalContext
{
    // Remplace un nom masqué : un mot à part, que ni les indices ni les villes ne reconnaissent.
    private const MASK = '_';

    /**
     * @param list<list<string>> $names mots racinés de chaque nom, d'au moins deux mots
     */
    private function __construct(
        public readonly string $seed,
        private readonly array $names,
    ) {
    }

    /** @param list<string> $titles titres d'articles Wikipédia trouvés pour la recherche */
    public static function of(string $seed, array $titles = []): self
    {
        $names = [];
        foreach ($titles as $title) {
            // « Pilote (aviation) » : la précision entre parenthèses ne fait pas partie du nom.
            $words = self::tokens((string) preg_replace('/\s*\([^)]*\)$/', '', $title));
            // Un nom d'un seul mot (« Test », « Prix ») est souvent l'indice lui-même : il ne masque rien.
            if (\count(array_diff($words, Lexicon::LINK_WORDS)) >= 2) {
                $names[implode(' ', $words)] = array_map(self::stem(...), $words);
            }
        }

        return new self($seed, array_values($names));
    }

    public static function none(): self
    {
        return new self('', []);
    }

    // Le texte normalisé, chaque nom reconnu remplacé par un mot neutre.
    public function read(string $text): string
    {
        $normalized = SuggestionMerger::normalize($text);
        if ([] === $this->names) {
            return $normalized;
        }
        $words = self::tokens($text);
        $stems = array_map(self::stem(...), $words);
        $masked = false;
        foreach ($this->names as $name) {
            $length = \count($name);
            for ($start = 0; $start + $length <= \count($stems); ++$start) {
                if (\array_slice($stems, $start, $length) === $name) {
                    array_splice($stems, $start, $length, [self::MASK]);
                    array_splice($words, $start, $length, [self::MASK]);
                    $masked = true;
                }
            }
        }

        return $masked ? implode(' ', $words) : $normalized;
    }

    public function readSeed(): string
    {
        return $this->read($this->seed);
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        return preg_split('/[\s\'’-]+/', SuggestionMerger::normalize($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    // « soignante » et « soignant », « calédonies » et « calédonie » : même mot pour reconnaître un nom.
    private static function stem(string $word): string
    {
        return \strlen($word) > 3 ? (string) preg_replace('/(es|s|x|e)$/', '', $word) : $word;
    }
}
