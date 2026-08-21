<?php

declare(strict_types=1);

namespace SecondStay\Legal;

use PDOException;
use SecondStay\Database\Database;

final class LegalDocumentRepository
{
    private const INTEGRITY_VIOLATION = '23000';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Publie une version, ou renvoie celle qui existe déjà.
     *
     * Republier la même version ne réécrit rien : une version publiée est un
     * instantané, pas un brouillon.
     *
     * @return array{id: int, created: bool}
     */
    public function publish(
        LegalDocumentType $type,
        string $locale,
        string $version,
        string $title,
        string $body,
        ?int $userId = null,
    ): array {
        try {
            $id = $this->database->insert('legal_document', [
                'type' => $type->value,
                'locale' => $locale,
                'version' => mb_substr($version, 0, 32),
                'title' => mb_substr($title, 0, 190),
                'body' => $body,
                'sha256' => hash('sha256', $body),
                'published_at' => gmdate('Y-m-d H:i:s'),
                'published_by' => $userId,
            ]);

            return ['id' => $id, 'created' => true];
        } catch (PDOException $exception) {
            if ($exception->getCode() !== self::INTEGRITY_VIOLATION) {
                throw $exception;
            }

            $existing = $this->find($type, $locale, $version);

            return ['id' => $existing === null ? 0 : $existing->id, 'created' => false];
        }
    }

    public function find(LegalDocumentType $type, string $locale, string $version): ?LegalDocument
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `legal_document` '
            . 'WHERE `type` = :type AND `locale` = :locale AND `version` = :version',
            ['type' => $type->value, 'locale' => $locale, 'version' => $version]
        );

        return $row === null ? null : LegalDocument::fromRow($row);
    }

    public function findById(int $id): ?LegalDocument
    {
        $row = $this->database->fetchOne('SELECT * FROM `legal_document` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : LegalDocument::fromRow($row);
    }

    /**
     * Dernière version publiée d'un texte, dans une langue.
     */
    public function current(LegalDocumentType $type, string $locale): ?LegalDocument
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `legal_document` WHERE `type` = :type AND `locale` = :locale '
            . 'ORDER BY `published_at` DESC, `id` DESC LIMIT 1',
            ['type' => $type->value, 'locale' => $locale]
        );

        return $row === null ? null : LegalDocument::fromRow($row);
    }

    /**
     * @return list<LegalDocument>
     */
    public function versions(LegalDocumentType $type): array
    {
        return array_map(
            static fn (array $row): LegalDocument => LegalDocument::fromRow($row),
            $this->database->fetchAll(
                'SELECT * FROM `legal_document` WHERE `type` = :type ORDER BY `published_at` DESC, `id` DESC',
                ['type' => $type->value]
            )
        );
    }

    /**
     * Langues dans lesquelles chaque version d'un texte existe.
     *
     * @return array<string, list<string>> version => langues
     */
    public function coverage(LegalDocumentType $type): array
    {
        $coverage = [];
        foreach ($this->database->fetchAll(
            'SELECT `version`, `locale` FROM `legal_document` WHERE `type` = :type ORDER BY `published_at` DESC',
            ['type' => $type->value]
        ) as $row) {
            $coverage[(string) $row['version']][] = (string) $row['locale'];
        }

        return $coverage;
    }
}
