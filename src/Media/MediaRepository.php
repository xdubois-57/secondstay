<?php

declare(strict_types=1);

namespace SecondStay\Media;

use SecondStay\Database\Database;

final class MediaRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return list<MediaItem>
     */
    public function all(): array
    {
        return $this->hydrate($this->database->fetchAll(
            'SELECT * FROM `media` ORDER BY `position`, `id`'
        ));
    }

    /**
     * @return list<MediaItem>
     */
    public function published(?string $category = null): array
    {
        $sql = 'SELECT * FROM `media` WHERE `is_published` = 1 AND `is_private` = 0';
        $parameters = [];

        if ($category !== null && $category !== '') {
            $sql .= ' AND `category` = :category';
            $parameters['category'] = $category;
        }

        return $this->hydrate($this->database->fetchAll($sql . ' ORDER BY `position`, `id`', $parameters));
    }

    public function findById(int $id): ?MediaItem
    {
        $row = $this->database->fetchOne('SELECT * FROM `media` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * Plusieurs médias en une fois, indexés par identifiant.
     *
     * Le livret affiche jusqu'à huit blocs illustrés : les résoudre un par un
     * ferait seize requêtes sur la page la plus consultée par les voyageurs,
     * et celle qu'ils ouvrent avec une barre de réseau.
     *
     * @param list<int> $ids
     *
     * @return array<int, MediaItem>
     */
    public function findManyById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':id' . $index;
            $parameters['id' . $index] = $id;
        }

        $items = [];
        foreach ($this->hydrate($this->database->fetchAll(
            'SELECT * FROM `media` WHERE `id` IN (' . implode(', ', $placeholders) . ')',
            $parameters
        )) as $item) {
            $items[$item->id] = $item;
        }

        return $items;
    }

    public function findByFilename(string $filename): ?MediaItem
    {
        $row = $this->database->fetchOne('SELECT * FROM `media` WHERE `filename` = :filename', ['filename' => $filename]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    public function findByHash(string $hash): ?MediaItem
    {
        $row = $this->database->fetchOne('SELECT * FROM `media` WHERE `hash` = :hash LIMIT 1', ['hash' => $hash]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        $rows = $this->database->fetchAll('SELECT DISTINCT `category` FROM `media` ORDER BY `category`');

        return array_map(static fn (array $row): string => (string) $row['category'], $rows);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->database->insert('media', $data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('media', $data + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->database->delete('media', ['id' => $id]);
    }

    public function saveTranslation(int $mediaId, string $locale, string $caption, string $altText): void
    {
        $this->database->execute(
            'INSERT INTO `media_translation` (`media_id`, `locale`, `caption`, `alt_text`, `updated_at`) '
            . 'VALUES (:media, :locale, :caption, :alt_text, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE `caption` = VALUES(`caption`), `alt_text` = VALUES(`alt_text`), '
            . '`updated_at` = VALUES(`updated_at`)',
            [
                'media' => $mediaId,
                'locale' => $locale,
                'caption' => $caption,
                'alt_text' => $altText,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    public function nextPosition(): int
    {
        return ((int) $this->database->fetchValue('SELECT COALESCE(MAX(`position`), 0) FROM `media`')) + 10;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<MediaItem>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];
        foreach ($rows as $index => $row) {
            $placeholders[] = ':id' . $index;
            $parameters['id' . $index] = (int) $row['id'];
        }

        $translationRows = $this->database->fetchAll(
            'SELECT * FROM `media_translation` WHERE `media_id` IN (' . implode(', ', $placeholders) . ')',
            $parameters
        );

        /** @var array<int, array<string, MediaTranslation>> $byMedia */
        $byMedia = [];
        foreach ($translationRows as $row) {
            $byMedia[(int) $row['media_id']][(string) $row['locale']] = MediaTranslation::fromRow($row);
        }

        return array_map(
            static fn (array $row): MediaItem => MediaItem::fromRow($row, $byMedia[(int) $row['id']] ?? []),
            $rows
        );
    }
}
