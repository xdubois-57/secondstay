<?php

declare(strict_types=1);

namespace SecondStay\Inspection;

use SecondStay\Database\Database;

/**
 * Zones du logement et leurs libellés localisés.
 */
final class ZoneRepository
{
    /**
     * Zones proposées à l'installation.
     *
     * L'ordre est celui d'un parcours réel : on entre, on traverse, on
     * termine par l'extérieur. Le propriétaire peut tout changer, mais partir
     * d'une liste vide n'aide personne.
     *
     * @var array<string, array{position: int, photo_required: bool}>
     */
    public const DEFAULTS = [
        'entrance' => ['position' => 10, 'photo_required' => false],
        'living_room' => ['position' => 20, 'photo_required' => true],
        'kitchen' => ['position' => 30, 'photo_required' => true],
        'bedrooms' => ['position' => 40, 'photo_required' => true],
        'bathrooms' => ['position' => 50, 'photo_required' => true],
        'outdoor' => ['position' => 60, 'photo_required' => false],
        'meters' => ['position' => 70, 'photo_required' => true],
    ];

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Zones actives, dans l'ordre du parcours, libellées dans une langue.
     *
     * Le libellé de la langue demandée est utilisé s'il existe ; sinon la
     * jointure laisse le champ vide et l'affichage retombe sur la clé de
     * traduction du code.
     *
     * @return list<Zone>
     */
    public function active(string $locale): array
    {
        return array_map(
            static fn (array $row): Zone => Zone::fromRow($row),
            $this->database->fetchAll(
                'SELECT z.*, t.`name`, t.`instructions` FROM `inspection_zone` z '
                . 'LEFT JOIN `inspection_zone_translation` t ON t.`zone_id` = z.`id` AND t.`locale` = :locale '
                . 'WHERE z.`active` = 1 ORDER BY z.`position`, z.`id`',
                ['locale' => $locale]
            )
        );
    }

    /**
     * @return list<Zone>
     */
    public function all(string $locale): array
    {
        return array_map(
            static fn (array $row): Zone => Zone::fromRow($row),
            $this->database->fetchAll(
                'SELECT z.*, t.`name`, t.`instructions` FROM `inspection_zone` z '
                . 'LEFT JOIN `inspection_zone_translation` t ON t.`zone_id` = z.`id` AND t.`locale` = :locale '
                . 'ORDER BY z.`position`, z.`id`',
                ['locale' => $locale]
            )
        );
    }

    public function find(int $id, string $locale = 'fr'): ?Zone
    {
        $row = $this->database->fetchOne(
            'SELECT z.*, t.`name`, t.`instructions` FROM `inspection_zone` z '
            . 'LEFT JOIN `inspection_zone_translation` t ON t.`zone_id` = z.`id` AND t.`locale` = :locale '
            . 'WHERE z.`id` = :id',
            ['id' => $id, 'locale' => $locale]
        );

        return $row === null ? null : Zone::fromRow($row);
    }

    public function findByCode(string $code, string $locale = 'fr'): ?Zone
    {
        $row = $this->database->fetchOne(
            'SELECT z.*, t.`name`, t.`instructions` FROM `inspection_zone` z '
            . 'LEFT JOIN `inspection_zone_translation` t ON t.`zone_id` = z.`id` AND t.`locale` = :locale '
            . 'WHERE z.`code` = :code',
            ['code' => $code, 'locale' => $locale]
        );

        return $row === null ? null : Zone::fromRow($row);
    }

    /**
     * Crée les zones proposées si aucune n'existe encore.
     *
     * Rejouable : une installation déjà configurée n'est jamais écrasée.
     */
    public function seedDefaults(): int
    {
        if ($this->database->fetchValue('SELECT COUNT(*) FROM `inspection_zone`') > 0) {
            return 0;
        }

        $created = 0;
        foreach (self::DEFAULTS as $code => $definition) {
            $this->database->insert('inspection_zone', [
                'code' => $code,
                'position' => $definition['position'],
                'photo_required' => $definition['photo_required'] ? 1 : 0,
                'active' => 1,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $code, array $data): int
    {
        $existing = $this->database->fetchOne(
            'SELECT `id` FROM `inspection_zone` WHERE `code` = :code',
            ['code' => $code]
        );

        if ($existing === null) {
            return $this->database->insert('inspection_zone', $data + [
                'code' => mb_substr($code, 0, 32),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $this->database->update('inspection_zone', $data, ['id' => (int) $existing['id']]);

        return (int) $existing['id'];
    }

    public function saveTranslation(int $zoneId, string $locale, string $name, string $instructions): void
    {
        $existing = $this->database->fetchOne(
            'SELECT `id` FROM `inspection_zone_translation` WHERE `zone_id` = :zone AND `locale` = :locale',
            ['zone' => $zoneId, 'locale' => $locale]
        );

        $data = [
            'name' => mb_substr($name, 0, 190),
            'instructions' => $instructions === '' ? null : $instructions,
        ];

        if ($existing === null) {
            $this->database->insert(
                'inspection_zone_translation',
                $data + ['zone_id' => $zoneId, 'locale' => $locale]
            );

            return;
        }

        $this->database->update('inspection_zone_translation', $data, ['id' => (int) $existing['id']]);
    }

    /**
     * Langues dans lesquelles chaque zone est nommée.
     *
     * @return array<string, list<string>>
     */
    public function completeness(): array
    {
        $state = [];

        foreach ($this->database->fetchAll(
            'SELECT z.`code`, t.`locale`, t.`name` FROM `inspection_zone` z '
            . 'LEFT JOIN `inspection_zone_translation` t ON t.`zone_id` = z.`id` '
            . 'WHERE z.`active` = 1 ORDER BY z.`position`'
        ) as $row) {
            $code = (string) $row['code'];
            $state[$code] ??= [];

            if ($row['locale'] !== null && trim((string) $row['name']) !== '') {
                $state[$code][] = (string) $row['locale'];
            }
        }

        return $state;
    }

    /**
     * Photos de référence d'une zone.
     *
     * @return list<int> identifiants de documents
     */
    public function referenceDocuments(int $zoneId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['document_id'],
            $this->database->fetchAll(
                'SELECT `document_id` FROM `inspection_reference` WHERE `zone_id` = :zone ORDER BY `position`, `id`',
                ['zone' => $zoneId]
            )
        );
    }

    public function addReference(int $zoneId, int $documentId): void
    {
        $this->database->insert('inspection_reference', [
            'zone_id' => $zoneId,
            'document_id' => $documentId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
