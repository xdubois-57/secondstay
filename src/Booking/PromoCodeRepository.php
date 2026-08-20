<?php

declare(strict_types=1);

namespace SecondStay\Booking;

use SecondStay\Database\Database;

final class PromoCodeRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function find(string $code): ?PromoCode
    {
        $normalised = PromoCode::normalise($code);
        if ($normalised === '') {
            return null;
        }

        $row = $this->database->fetchOne(
            'SELECT * FROM `promo_code` WHERE `code` = :code',
            ['code' => $normalised]
        );

        return $row === null ? null : PromoCode::fromRow($row);
    }

    /**
     * @return list<PromoCode>
     */
    public function all(): array
    {
        return array_map(
            static fn (array $row): PromoCode => PromoCode::fromRow($row),
            $this->database->fetchAll('SELECT * FROM `promo_code` ORDER BY `code`')
        );
    }

    public function create(
        string $code,
        string $kind,
        int $value,
        ?string $startsOn = null,
        ?string $endsOn = null,
        ?int $maxUses = null,
        string $label = '',
    ): int {
        return $this->database->insert('promo_code', [
            'code' => PromoCode::normalise($code),
            'kind' => $kind === PromoCode::KIND_FIXED ? PromoCode::KIND_FIXED : PromoCode::KIND_PERCENT,
            'value' => max(0, $value),
            'is_active' => 1,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'max_uses' => $maxUses,
            'label' => mb_substr($label, 0, 190),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('promo_code', ['is_active' => $active ? 1 : 0], ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        return $this->database->delete('promo_code', ['id' => $id]) > 0;
    }

    /**
     * Consommation d'un usage.
     *
     * Le compteur est incrémenté sous condition : deux réservations
     * simultanées ne peuvent pas dépasser ensemble la limite d'usage.
     */
    public function consume(int $id): bool
    {
        return $this->database->execute(
            'UPDATE `promo_code` SET `used_count` = `used_count` + 1 '
            . 'WHERE `id` = :id AND (`max_uses` IS NULL OR `used_count` < `max_uses`)',
            ['id' => $id]
        )->rowCount() > 0;
    }

    public function release(int $id): void
    {
        $this->database->execute(
            'UPDATE `promo_code` SET `used_count` = GREATEST(`used_count` - 1, 0) WHERE `id` = :id',
            ['id' => $id]
        );
    }
}
