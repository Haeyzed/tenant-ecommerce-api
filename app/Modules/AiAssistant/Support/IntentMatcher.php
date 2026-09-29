<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Support;

use App\Modules\AiAssistant\Models\AiAssistantIntent;

/**
 * Normalised phrase and keyword matching (spec §62.3), with no external
 * NLP. A sample phrase found whole in the question scores highest; else
 * every meaningful word of a phrase must appear. Nothing is guessed: no
 * match returns null.
 */
final class IntentMatcher
{
    /** Words that carry no meaning for matching. */
    private const array STOP_WORDS = [
        'a', 'an', 'the', 'what', 'whats', 'which', 'is', 'are', 'was', 'were', 'be', 'show', 'me', 'my', 'our', 'we', 'us', 'i', 'you',
        'did', 'do', 'does', 'how', 'much', 'many', 'for', 'of', 'to', 'in', 'on', 'at', 'please', 'list', 'give', 'tell', 'about',
        'any', 'have', 'has', 'can', 'could', 'there', 'so', 'far', 'and', 'or', 'all', 'with', 'by', 'it', 'its', 'this', 'that',
    ];

    /**
     * @param  iterable<AiAssistantIntent>  $intents  the candidates, in priority order
     */
    public function match(string $question, iterable $intents): ?AiAssistantIntent
    {
        $query = self::normalise($question);
        $words = self::keywords($query);

        if ($query === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($intents as $intent) {
            foreach ((array) $intent->sample_phrases as $phrase) {
                $score = self::score($query, $words, self::normalise((string) $phrase));

                if ($score > $bestScore) {
                    [$best, $bestScore] = [$intent, $score];
                }
            }
        }

        return $best;
    }

    private static function score(string $query, array $words, string $phrase): int
    {
        if ($phrase === '') {
            return 0;
        }

        // The whole phrase, on word boundaries.
        if (str_contains(' '.$query.' ', ' '.$phrase.' ')) {
            return 1000 + strlen($phrase);
        }

        $needed = self::keywords($phrase);

        if ($needed === [] || array_diff($needed, $words) !== []) {
            return 0;
        }

        return 100 + 10 * count($needed);
    }

    /**
     * Lower case, apostrophes dropped ("today's" → "todays"), everything
     * else not a letter or digit to a space.
     */
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(["'", '’', '`'], '', $text);
        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Meaningful words, crudely singularised ("orders" → "order", "todays" → "today").
     *
     * @return list<string>
     */
    public static function keywords(string $normalised): array
    {
        $words = [];

        foreach ($normalised === '' ? [] : explode(' ', $normalised) as $word) {
            if (in_array($word, self::STOP_WORDS, true)) {
                continue;
            }

            $words[] = mb_strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;
        }

        return array_values(array_unique($words));
    }
}
