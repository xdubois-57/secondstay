<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use SecondStay\Database\Database;
use SecondStay\I18n\Locales;

/**
 * Livret d'accueil : un enregistrement par bloc et par langue.
 */
final class StayInfoRepository
{
    /**
     * Blocs proposés à l'installation (SPECIFICATIONS.md §44 et §45).
     *
     * L'ordre est celui dans lequel ils s'affichent, et la phase indique quand
     * le bloc a un sens.
     *
     * @var array<string, array{phase: string, position: int}>
     */
    public const BLOCKS = [
        'welcome' => ['phase' => StayPhase::ANY, 'position' => 10],
        'access' => ['phase' => 'arrival', 'position' => 20],
        'wifi' => ['phase' => StayPhase::ANY, 'position' => 30],
        'appliances' => ['phase' => 'during', 'position' => 40],
        'waste' => ['phase' => StayPhase::ANY, 'position' => 50],
        'rules' => ['phase' => StayPhase::ANY, 'position' => 60],
        'safety' => ['phase' => StayPhase::ANY, 'position' => 70],
        'checkout' => ['phase' => 'departure', 'position' => 80],
    ];

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Blocs publiés d'une langue, dans l'ordre d'affichage.
     *
     * @return list<StayInfoBlock>
     */
    public function published(string $locale): array
    {
        return array_map(
            static fn (array $row): StayInfoBlock => StayInfoBlock::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `stay_info` WHERE `locale` = :locale AND `published` = 1 '
                . 'ORDER BY `position`, `id`',
                ['locale' => $locale]
            )
        );
    }

    /**
     * Tous les blocs d'une langue, publiés ou non.
     *
     * @return array<string, StayInfoBlock>
     */
    public function forLocale(string $locale): array
    {
        $blocks = [];

        foreach ($this->database->fetchAll(
            'SELECT * FROM `stay_info` WHERE `locale` = :locale ORDER BY `position`, `id`',
            ['locale' => $locale]
        ) as $row) {
            $blocks[(string) $row['code']] = StayInfoBlock::fromRow($row);
        }

        return $blocks;
    }

    public function find(string $code, string $locale): ?StayInfoBlock
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `stay_info` WHERE `code` = :code AND `locale` = :locale',
            ['code' => $code, 'locale' => $locale]
        );

        return $row === null ? null : StayInfoBlock::fromRow($row);
    }

    /**
     * Enregistre un bloc, en le créant au besoin.
     */
    public function save(
        string $code,
        string $locale,
        string $title,
        string $body,
        bool $published = true,
        bool $public = false,
        ?int $mediaId = null,
    ): void {
        $definition = self::BLOCKS[$code] ?? ['phase' => StayPhase::ANY, 'position' => 900];

        $data = [
            'title' => mb_substr($title, 0, 190),
            'body' => $body === '' ? null : $body,
            'media_id' => $mediaId,
            'phase' => $definition['phase'],
            'position' => $definition['position'],
            'published' => $published ? 1 : 0,
            'public' => $public ? 1 : 0,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($this->find($code, $locale) === null) {
            $this->database->insert('stay_info', $data + ['code' => $code, 'locale' => $locale]);

            return;
        }

        $this->database->update('stay_info', $data, ['code' => $code, 'locale' => $locale]);
    }

    /**
     * Bloc lisible à son adresse publique stable (SPECIFICATIONS.md §47).
     *
     * Rien n'est servi qui ne soit explicitement public **et** publié **et**
     * renseigné : un QR ne doit jamais ouvrir une page vide, et un bloc retiré
     * du livret ne doit pas survivre à une adresse oubliée.
     */
    public function findPublic(string $code, string $locale): ?StayInfoBlock
    {
        $block = $this->find($code, $locale);

        return $block !== null && $block->isPubliclyReadable() ? $block : null;
    }

    /**
     * Codes publiés à une adresse publique, quelle que soit la langue.
     *
     * @return list<string>
     */
    public function publicCodes(): array
    {
        $codes = [];
        foreach ($this->database->fetchAll(
            'SELECT DISTINCT `code` FROM `stay_info` WHERE `public` = 1 AND `published` = 1'
        ) as $row) {
            $codes[] = (string) $row['code'];
        }

        return $codes;
    }

    /**
     * Langues dans lesquelles un bloc est renseigné.
     *
     * Sert à l'état de complétude : un livret utile en français mais vide en
     * allemand doit se voir.
     *
     * @return array<string, list<string>> code => langues renseignées
     */
    public function completeness(): array
    {
        $state = [];

        foreach (array_keys(self::BLOCKS) as $code) {
            $state[$code] = [];
        }

        foreach ($this->database->fetchAll(
            'SELECT `code`, `locale`, `title`, `body` FROM `stay_info` WHERE `published` = 1'
        ) as $row) {
            $code = (string) $row['code'];
            $filled = trim((string) $row['title']) !== '' || trim((string) ($row['body'] ?? '')) !== '';

            if ($filled && Locales::isSupported((string) $row['locale'])) {
                $state[$code][] = (string) $row['locale'];
            }
        }

        return $state;
    }
}
