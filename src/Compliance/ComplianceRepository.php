<?php

declare(strict_types=1);

namespace SecondStay\Compliance;

use SecondStay\Database\Database;

final class ComplianceRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Tous les sujets, dans l'ordre d'affichage, y compris ceux jamais saisis.
     *
     * Un sujet absent de la base n'est pas un sujet absent du droit : il est
     * simplement « à vérifier ». L'assistant ne peut donc pas donner
     * l'impression qu'il n'y a rien à faire.
     *
     * @return list<ComplianceItem>
     */
    public function all(): array
    {
        $stored = [];
        foreach ($this->database->fetchAll('SELECT * FROM `compliance_item`') as $row) {
            $stored[(string) $row['topic']] = ComplianceItem::fromRow($row);
        }

        $items = [];
        foreach (ComplianceTopic::cases() as $topic) {
            $items[] = $stored[$topic->value] ?? $this->blank($topic);
        }

        return $items;
    }

    public function find(ComplianceTopic $topic): ComplianceItem
    {
        $row = $this->database->fetchOne(
            'SELECT * FROM `compliance_item` WHERE `topic` = :topic',
            ['topic' => $topic->value]
        );

        return $row === null ? $this->blank($topic) : ComplianceItem::fromRow($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(ComplianceTopic $topic, array $data, ?int $userId = null): int
    {
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_by'] = $userId;

        $existing = $this->database->fetchOne(
            'SELECT `id` FROM `compliance_item` WHERE `topic` = :topic',
            ['topic' => $topic->value]
        );

        if ($existing === null) {
            return $this->database->insert('compliance_item', $data + ['topic' => $topic->value]);
        }

        $this->database->update('compliance_item', $data, ['id' => (int) $existing['id']]);

        return (int) $existing['id'];
    }

    /**
     * Sujets réclamant une action : à vérifier, ou revue dépassée.
     *
     * @return list<ComplianceItem>
     */
    public function outstanding(?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');

        return array_values(array_filter(
            $this->all(),
            static fn (ComplianceItem $item): bool => $item->needsAction($today)
        ));
    }

    /**
     * Un sujet jamais saisi : il existe, et il reste à vérifier.
     */
    private function blank(ComplianceTopic $topic): ComplianceItem
    {
        return new ComplianceItem(
            0,
            $topic,
            ComplianceStatus::ToVerify,
            '',
            '',
            '',
            null,
            null,
            null,
            '',
        );
    }
}
