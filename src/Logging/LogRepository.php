<?php

declare(strict_types=1);

namespace SecondStay\Logging;

use SecondStay\Database\Database;

/**
 * Lecture du journal applicatif.
 *
 * L'écran des journaux et le tableau « À faire » posent deux questions
 * différentes au même endroit ; les laisser écrire leur propre SQL les
 * ferait dériver l'un de l'autre le jour où la table changera.
 */
final class LogRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Entrées d'au moins ce niveau de gravité depuis un horodatage.
     *
     * Le filtre porte sur la gravité et non sur le niveau exact : une panne
     * critique doit compter parmi les erreurs, sans quoi le tableau « À
     * faire » resterait vide au pire moment.
     */
    public function countAtLeast(LogLevel $minimum, string $since): int
    {
        $levels = array_values(array_filter(
            LogLevel::cases(),
            static fn (LogLevel $level): bool => $level->severity() >= $minimum->severity()
        ));

        if ($levels === []) {
            return 0;
        }

        $placeholders = [];
        $parameters = ['since' => $since];
        foreach ($levels as $index => $level) {
            $placeholders[] = ':level' . $index;
            $parameters['level' . $index] = $level->value;
        }

        return (int) $this->database->fetchValue(
            'SELECT COUNT(*) FROM `app_log` WHERE `created_at` >= :since '
            . 'AND `level` IN (' . implode(', ', $placeholders) . ')',
            $parameters
        );
    }

    /**
     * @param array{level?: string, category?: string, q?: string} $filters
     *
     * @return array{entries: list<array<string, mixed>>, total: int}
     */
    public function page(array $filters, int $page, int $pageSize): array
    {
        [$where, $parameters] = $this->where($filters);

        $total = (int) $this->database->fetchValue('SELECT COUNT(*) FROM `app_log`' . $where, $parameters);
        $offset = max(0, ($page - 1) * $pageSize);

        $entries = $this->database->fetchAll(
            'SELECT * FROM `app_log`' . $where
            . sprintf(' ORDER BY `id` DESC LIMIT %d OFFSET %d', max(1, $pageSize), $offset),
            $parameters
        );

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['category'],
            $this->database->fetchAll('SELECT DISTINCT `category` FROM `app_log` ORDER BY `category`')
        );
    }

    /**
     * @param array{level?: string, category?: string, q?: string} $filters
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function where(array $filters): array
    {
        $conditions = [];
        $parameters = [];

        $level = $filters['level'] ?? '';
        if ($level !== '' && LogLevel::tryFrom($level) !== null) {
            $conditions[] = '`level` = :level';
            $parameters['level'] = $level;
        }

        $category = $filters['category'] ?? '';
        if ($category !== '') {
            $conditions[] = '`category` = :category';
            $parameters['category'] = $category;
        }

        $search = $filters['q'] ?? '';
        if ($search !== '') {
            $conditions[] = '`message` LIKE :search';
            // Les jokers saisis par l'humain ne doivent pas devenir des
            // jokers SQL : sinon un `%` cherché ne cherche plus rien.
            $parameters['search'] = '%' . addcslashes($search, '%_\\') . '%';
        }

        return [
            $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            $parameters,
        ];
    }
}
