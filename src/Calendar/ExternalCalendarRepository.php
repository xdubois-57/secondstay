<?php

declare(strict_types=1);

namespace SecondStay\Calendar;

use SecondStay\Database\Database;

final class ExternalCalendarRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return list<ExternalCalendar>
     */
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM `external_calendar` '
            . ($onlyActive ? 'WHERE `active` = 1 ' : '')
            . 'ORDER BY `id`';

        return array_map(
            static fn (array $row): ExternalCalendar => ExternalCalendar::fromRow($row),
            $this->database->fetchAll($sql)
        );
    }

    public function find(int $id): ?ExternalCalendar
    {
        $row = $this->database->fetchOne('SELECT * FROM `external_calendar` WHERE `id` = :id', ['id' => $id]);

        return $row === null ? null : ExternalCalendar::fromRow($row);
    }

    public function findByUrl(string $url): ?ExternalCalendar
    {
        $row = $this->database->fetchOne('SELECT * FROM `external_calendar` WHERE `url` = :url', ['url' => $url]);

        return $row === null ? null : ExternalCalendar::fromRow($row);
    }

    public function create(string $url, string $label, string $provider): int
    {
        return $this->database->insert('external_calendar', [
            'url' => mb_substr($url, 0, 500),
            'label' => mb_substr($label, 0, 190),
            'provider' => in_array($provider, ExternalCalendar::PROVIDERS, true) ? $provider : 'other',
            'active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->database->update('external_calendar', ['active' => $active ? 1 : 0], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        // Les blocages issus de ce flux partent avec lui : sans leur source,
        // ils deviendraient des blocages sans provenance.
        $this->database->delete('external_calendar', ['id' => $id]);
    }

    public function recordSync(int $id, string $status, int $events): void
    {
        $this->database->update('external_calendar', [
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
            'last_status' => mb_substr($status, 0, 48),
            'last_events' => max(0, $events),
        ], ['id' => $id]);
    }
}
