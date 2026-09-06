<?php

declare(strict_types=1);

namespace SecondStay\Content;

use SecondStay\Database\Database;

final class ContentRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return list<ContentPage>
     */
    public function all(): array
    {
        return $this->hydrate($this->database->fetchAll(
            'SELECT * FROM `content_page` ORDER BY `position`, `id`'
        ));
    }

    /**
     * @return list<ContentPage>
     */
    public function published(): array
    {
        return $this->hydrate($this->database->fetchAll(
            'SELECT * FROM `content_page` WHERE `is_published` = 1 ORDER BY `position`, `id`'
        ));
    }

    public function findBySlug(string $slug): ?ContentPage
    {
        $row = $this->database->fetchOne('SELECT * FROM `content_page` WHERE `slug` = :slug', ['slug' => $slug]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    public function findById(int $id): ?ContentPage
    {
        $row = $this->database->fetchOne('SELECT * FROM `content_page` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    public function findByKind(PageKind $kind): ?ContentPage
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `content_page` WHERE `kind` = :kind ORDER BY `position`, `id` LIMIT 1',
            ['kind' => $kind->value]
        );

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->database->insert(
            'content_page',
            $data + [
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $this->database->update('content_page', $data + ['updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->database->delete('content_page', ['id' => $id]);
    }

    /**
     * @param array<string, string> $values
     */
    public function saveTranslation(int $pageId, string $locale, array $values): void
    {
        $this->database->execute(
            'INSERT INTO `content_translation` '
            . '(`content_page_id`, `locale`, `title`, `menu_label`, `lead`, `body`, '
            . '`meta_title`, `meta_description`, `updated_at`) '
            . 'VALUES (:page, :locale, :title, :menu_label, :lead, :body, :meta_title, :meta_description, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `menu_label` = VALUES(`menu_label`), '
            . '`lead` = VALUES(`lead`), `body` = VALUES(`body`), `meta_title` = VALUES(`meta_title`), '
            . '`meta_description` = VALUES(`meta_description`), `updated_at` = VALUES(`updated_at`)',
            [
                'page' => $pageId,
                'locale' => $locale,
                'title' => $values['title'] ?? '',
                'menu_label' => $values['menu_label'] ?? '',
                'lead' => $values['lead'] ?? '',
                'body' => $values['body'] ?? '',
                'meta_title' => $values['meta_title'] ?? '',
                'meta_description' => $values['meta_description'] ?? '',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    public function nextPosition(?int $parentId): int
    {
        $value = $parentId === null
            ? $this->database->fetchValue(
                'SELECT COALESCE(MAX(`position`), 0) FROM `content_page` WHERE `parent_id` IS NULL'
            )
            : $this->database->fetchValue(
                'SELECT COALESCE(MAX(`position`), 0) FROM `content_page` WHERE `parent_id` = :parent',
                ['parent' => $parentId]
            );

        return ((int) $value) + 10;
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM `content_page` WHERE `slug` = :slug';
        $parameters = ['slug' => $slug];

        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :id';
            $parameters['id'] = $exceptId;
        }

        return (int) $this->database->fetchValue($sql, $parameters) > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ContentPage>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':id' . $index;
            $parameters['id' . $index] = $id;
        }

        $translationRows = $this->database->fetchAll(
            'SELECT * FROM `content_translation` WHERE `content_page_id` IN (' . implode(', ', $placeholders) . ')',
            $parameters
        );

        /** @var array<int, array<string, PageTranslation>> $byPage */
        $byPage = [];
        foreach ($translationRows as $row) {
            $byPage[(int) $row['content_page_id']][(string) $row['locale']] = PageTranslation::fromRow($row);
        }

        return array_map(
            static fn (array $row): ContentPage => ContentPage::fromRow($row, $byPage[(int) $row['id']] ?? []),
            $rows
        );
    }
}
