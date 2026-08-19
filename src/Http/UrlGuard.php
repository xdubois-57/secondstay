<?php

declare(strict_types=1);

namespace SecondStay\Http;

/**
 * Protection SSRF (SECURITY.md §16).
 *
 * Bloque localhost, loopback, link-local, réseaux privés, protocoles non
 * HTTP(S) et toute redirection vers une cible interdite. La résolution DNS est
 * vérifiée : un nom public pointant vers une IP privée est refusé.
 */
final class UrlGuard
{
    /** @var list<array{0: string, 1: int}> plages IPv4 interdites (CIDR) */
    private const BLOCKED_IPV4 = [
        ['0.0.0.0', 8],
        ['10.0.0.0', 8],
        ['100.64.0.0', 10],
        ['127.0.0.0', 8],
        ['169.254.0.0', 16],
        ['172.16.0.0', 12],
        ['192.0.0.0', 24],
        ['192.0.2.0', 24],
        ['192.168.0.0', 16],
        ['198.18.0.0', 15],
        ['198.51.100.0', 24],
        ['203.0.113.0', 24],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
    ];

    /** @var (callable(string): list<string>)|null */
    private $resolver;

    /**
     * @param list<string>                         $allowedHosts liste blanche optionnelle
     * @param (callable(string): list<string>)|null $resolver     résolution DNS injectable
     */
    public function __construct(private readonly array $allowedHosts = [], ?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @return array{ok: true, host: string, ips: list<string>}|array{ok: false, reason: string}
     */
    public function inspect(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'])) {
            return ['ok' => false, 'reason' => 'ssrf.invalid_url'];
        }

        // Le protocole est vérifié avant l'hôte : `file:///etc/passwd` doit être
        // refusé comme protocole interdit, pas comme URL malformée.
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['ok' => false, 'reason' => 'ssrf.scheme_not_allowed'];
        }

        if (!isset($parts['host'])) {
            return ['ok' => false, 'reason' => 'ssrf.invalid_url'];
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '') {
            return ['ok' => false, 'reason' => 'ssrf.invalid_url'];
        }

        if ($this->allowedHosts !== [] && !$this->hostIsAllowed($host)) {
            return ['ok' => false, 'reason' => 'ssrf.host_not_allowed'];
        }

        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost'], true)) {
            return ['ok' => false, 'reason' => 'ssrf.private_target'];
        }

        // Un hostname interne sans point est traité comme cible privée.
        if (!str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'reason' => 'ssrf.private_target'];
        }

        $ips = $this->resolve($host);
        if ($ips === []) {
            return ['ok' => false, 'reason' => 'ssrf.dns_failed'];
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                return ['ok' => false, 'reason' => 'ssrf.private_target'];
            }
        }

        return ['ok' => true, 'host' => $host, 'ips' => $ips];
    }

    public function isAllowed(string $url): bool
    {
        return $this->inspect($url)['ok'] === true;
    }

    private function hostIsAllowed(string $host): bool
    {
        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Les tests injectent leur propre résolution : la vérification des
        // plages privées reste appliquée à l'identique.
        if ($this->resolver !== null) {
            return ($this->resolver)($host);
        }

        $ips = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            foreach ($v4 as $ip) {
                $ips[] = $ip;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    public function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_IPV4 as [$network, $bits]) {
                if ($this->ipv4InRange($ip, $network, $bits)) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $normalised = strtolower((string) inet_ntop((string) inet_pton($ip)));
            if ($normalised === '::1' || $normalised === '::') {
                return true;
            }
            // fc00::/7 (unique local), fe80::/10 (link-local), ::ffff:0:0/96 (mapped IPv4)
            $binary = inet_pton($ip);
            if ($binary === false) {
                return true;
            }
            $first = ord($binary[0]);
            if (($first & 0xFE) === 0xFC) {
                return true;
            }
            if ($first === 0xFE && (ord($binary[1]) & 0xC0) === 0x80) {
                return true;
            }
            if (str_starts_with($normalised, '::ffff:')) {
                $mapped = substr($normalised, 7);

                return $this->isBlockedIp($mapped);
            }

            return false;
        }

        return true;
    }

    private function ipv4InRange(string $ip, string $network, int $bits): bool
    {
        $ipLong = ip2long($ip);
        $networkLong = ip2long($network);
        if ($ipLong === false || $networkLong === false) {
            return true;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($networkLong & $mask);
    }
}
