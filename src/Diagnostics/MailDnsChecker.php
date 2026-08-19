<?php

declare(strict_types=1);

namespace SecondStay\Diagnostics;

/**
 * Contrôle SPF / DKIM / DMARC du domaine d'expédition (SPECIFICATIONS.md §18).
 *
 * SecondStay ne signe pas lui-même : DKIM est assuré par le fournisseur SMTP.
 * Le rôle du diagnostic est donc d'indiquer au propriétaire si son domaine
 * publie bien les enregistrements attendus.
 *
 * La résolution DNS est injectable : les tests n'ont jamais besoin de réseau.
 */
final class MailDnsChecker
{
    /** @var callable(string, int): (list<array<string, mixed>>|false) */
    private $resolver;

    /**
     * @param (callable(string, int): (list<array<string, mixed>>|false))|null $resolver
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host, int $type) {
            /** @var list<array<string, mixed>>|false $records */
            $records = @dns_get_record($host, $type);

            return $records;
        };
    }

    /**
     * @return array{
     *     domain: string,
     *     spf: array{status: string, value: string},
     *     dkim: array{status: string, value: string, selector: string},
     *     dmarc: array{status: string, value: string, policy: string}
     * }
     */
    public function check(string $domain, string $dkimSelector = ''): array
    {
        $domain = strtolower(trim($domain));

        return [
            'domain' => $domain,
            'spf' => $this->checkSpf($domain),
            'dkim' => $this->checkDkim($domain, trim($dkimSelector)),
            'dmarc' => $this->checkDmarc($domain),
        ];
    }

    public static function domainOf(string $address): string
    {
        $parts = explode('@', trim($address));

        return count($parts) === 2 ? strtolower($parts[1]) : '';
    }

    /**
     * @return array{status: string, value: string}
     */
    private function checkSpf(string $domain): array
    {
        if ($domain === '') {
            return ['status' => 'unknown', 'value' => ''];
        }

        foreach ($this->txtRecords($domain) as $record) {
            if (stripos($record, 'v=spf1') === 0) {
                // `+all` accepte n'importe quel émetteur : la protection est nulle.
                $permissive = preg_match('/[\s]\+?all\b/i', $record) === 1
                    && preg_match('/[\s][-~]all\b/i', $record) !== 1;

                return ['status' => $permissive ? 'weak' : 'ok', 'value' => $record];
            }
        }

        return ['status' => 'missing', 'value' => ''];
    }

    /**
     * @return array{status: string, value: string, selector: string}
     */
    private function checkDkim(string $domain, string $selector): array
    {
        if ($domain === '' || $selector === '') {
            // Sans sélecteur connu, l'enregistrement n'est pas localisable :
            // ce n'est pas une erreur, seulement une vérification impossible.
            return ['status' => 'unknown', 'value' => '', 'selector' => $selector];
        }

        foreach ($this->txtRecords($selector . '._domainkey.' . $domain) as $record) {
            if (stripos($record, 'v=DKIM1') === 0 || str_contains($record, 'p=')) {
                $revoked = preg_match('/\bp=\s*(;|$)/', $record) === 1;

                return [
                    'status' => $revoked ? 'weak' : 'ok',
                    // La clé publique n'apporte rien à l'écran : on n'affiche
                    // que les paramètres.
                    'value' => preg_replace('/p=[A-Za-z0-9+\/=]+/', 'p=…', $record) ?? '',
                    'selector' => $selector,
                ];
            }
        }

        return ['status' => 'missing', 'value' => '', 'selector' => $selector];
    }

    /**
     * @return array{status: string, value: string, policy: string}
     */
    private function checkDmarc(string $domain): array
    {
        if ($domain === '') {
            return ['status' => 'unknown', 'value' => '', 'policy' => ''];
        }

        foreach ($this->txtRecords('_dmarc.' . $domain) as $record) {
            if (stripos($record, 'v=DMARC1') !== 0) {
                continue;
            }

            $policy = 'none';
            if (preg_match('/\bp=\s*(none|quarantine|reject)\b/i', $record, $matches) === 1) {
                $policy = strtolower($matches[1]);
            }

            return [
                'status' => $policy === 'none' ? 'weak' : 'ok',
                'value' => $record,
                'policy' => $policy,
            ];
        }

        return ['status' => 'missing', 'value' => '', 'policy' => ''];
    }

    /**
     * @return list<string>
     */
    private function txtRecords(string $host): array
    {
        $records = ($this->resolver)($host, DNS_TXT);
        if ($records === false) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            // Un TXT long est découpé en segments qu'il faut réassembler.
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', array_map(static fn (mixed $e): string => (string) $e, $record['entries']));
                continue;
            }
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return $values;
    }
}
