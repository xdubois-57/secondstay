<?php

declare(strict_types=1);

namespace SecondStay\Llm;

use SecondStay\LocalContent\ActivitySchema;

/**
 * Modèle factice, déterministe (TESTING.md §8).
 *
 * Il ne « simule » pas un modèle : il **lit réellement** les sources placées
 * dans le prompt et en extrait les activités. Un test qui passe prouve donc
 * que la page récupérée est bien arrivée jusqu'au prompt, et que le pipeline
 * de bout en bout fonctionne — ce qu'une réponse figée ne prouverait pas.
 *
 * La convention de lecture est volontairement celle d'une page réelle : une
 * ligne « Titre — 2026-07-08 » ou « Titre — 2026-07-08 → 2026-07-10 »,
 * suffixée de « (réservation) » quand il faut réserver.
 *
 * Comme les autres fournisseurs factices du produit, il n'est activable que
 * par variable d'environnement : jamais depuis l'interface.
 */
final class FakeLlmProvider implements LlmProvider
{
    public const NAME = 'fake';

    /** Une activité par ligne, avec sa ou ses dates. */
    private const LINE = '/^(?<title>.+?)\s+—\s+(?<start>\d{4}-\d{2}-\d{2})'
        . '(?:\s*→\s*(?<end>\d{4}-\d{2}-\d{2}))?(?<tail>.*)$/u';

    /** @var list<LlmPrompt> demandes reçues, pour inspection dans les tests */
    public array $prompts = [];

    public function __construct(private readonly bool $configured = true)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function complete(LlmPrompt $prompt): LlmResult
    {
        $this->prompts[] = $prompt;

        if (!$this->configured) {
            return LlmResult::failure('llm.error.not_configured');
        }

        $activities = [];
        foreach ($this->sections($prompt->user) as $url => $text) {
            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                $activity = $this->activityFrom(trim($line), $url);
                if ($activity !== null) {
                    $activities[] = $activity;
                }

                if (count($activities) >= ActivitySchema::MAX_ITEMS) {
                    break 2;
                }
            }
        }

        return LlmResult::success(['activities' => $activities], 'fake-model', 0, 0);
    }

    /**
     * Contenu de chaque source, indexé par URL.
     *
     * @return array<string, string>
     */
    private function sections(string $user): array
    {
        $matched = preg_match_all(
            '/^\[SOURCE (\d+)\] (\S+)$(?<body>.*?)^\[FIN SOURCE \1\]$/ms',
            $user,
            $matches,
            PREG_SET_ORDER
        );

        if ($matched === false || $matched === 0) {
            return [];
        }

        $sections = [];
        foreach ($matches as $match) {
            $sections[$match[2]] = $match['body'];
        }

        return $sections;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activityFrom(string $line, string $url): ?array
    {
        if (preg_match(self::LINE, $line, $found) !== 1) {
            return null;
        }

        $tail = trim($found['tail']);
        // Sans date de fin, l'activité tient sur une journée.
        $end = $found['end'] !== '' ? $found['end'] : $found['start'];

        return [
            'title' => trim($found['title']),
            'summary' => $tail === '' ? trim($found['title']) : trim($found['title']) . ' ' . $tail,
            'category' => $this->categoryOf($line),
            'starts_on' => $found['start'],
            'ends_on' => $end,
            'booking_required' => str_contains($line, '(réservation)'),
            'location' => '',
            'source_url' => $url,
        ];
    }

    private function categoryOf(string $line): string
    {
        $lower = mb_strtolower($line);

        foreach ([
            'market' => ['marché', 'markt', 'market'],
            'festival' => ['festival', 'fête', 'feest', 'fest'],
            'museum' => ['musée', 'museum'],
            'nature' => ['randonnée', 'wandeling', 'wanderung', 'hike'],
        ] as $category => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lower, $needle)) {
                    return $category;
                }
            }
        }

        return 'other';
    }
}
