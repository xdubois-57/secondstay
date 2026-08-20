<?php

declare(strict_types=1);

namespace SecondStay\Imap;

use RuntimeException;

/**
 * Boîte aux lettres factice (TESTING.md §8).
 *
 * Les messages sont déposés dans un répertoire, un fichier par UID : le
 * parcours « réponse par e-mail → document du séjour » est donc jouable de
 * bout en bout sans serveur IMAP, y compris à travers plusieurs requêtes HTTP.
 */
final class FakeImapProvider implements ImapProvider
{
    public const NAME = 'fake';

    public function __construct(
        private readonly string $directory,
        private readonly string $mailbox = 'INBOX',
        private readonly int $uidValidity = 1,
    ) {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    public function verify(): array
    {
        return ['ok' => true, 'detail' => 'mailbox.verify.ok'];
    }

    public function mailbox(): string
    {
        return $this->mailbox;
    }

    public function uidValidity(): int
    {
        return $this->uidValidity;
    }

    /**
     * Dépose un message et renvoie son UID.
     */
    public function deliver(string $raw): int
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Impossible de créer la boîte de test : ' . $this->directory);
        }

        $uid = 1;
        foreach ($this->uids() as $existing) {
            $uid = max($uid, $existing + 1);
        }

        file_put_contents($this->path($uid), $raw, LOCK_EX);

        return $uid;
    }

    /**
     * @return list<int>
     */
    public function listNewUids(int $sinceUid, int $limit = 50): array
    {
        $uids = array_values(array_filter(
            $this->uids(),
            static fn (int $uid): bool => $uid > $sinceUid
        ));

        sort($uids);

        return array_slice($uids, 0, max(1, $limit));
    }

    public function fetch(int $uid): ?string
    {
        $path = $this->path($uid);
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function purge(): void
    {
        foreach (glob($this->directory . '/*.eml') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @return list<int>
     */
    private function uids(): array
    {
        $uids = [];

        foreach (glob($this->directory . '/*.eml') ?: [] as $file) {
            $name = basename($file, '.eml');
            if (ctype_digit($name)) {
                $uids[] = (int) $name;
            }
        }

        sort($uids);

        return $uids;
    }

    private function path(int $uid): string
    {
        return sprintf('%s/%d.eml', rtrim($this->directory, '/'), $uid);
    }
}
