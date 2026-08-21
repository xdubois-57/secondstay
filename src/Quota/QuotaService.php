<?php

declare(strict_types=1);

namespace SecondStay\Quota;

use SecondStay\Core\Paths;
use SecondStay\Settings\SettingsService;

/**
 * Quotas de stockage (ROADMAP.md itération 14, SPECIFICATIONS.md §67).
 *
 * Un hébergement mutualisé a un disque fini, et un disque plein casse tout —
 * y compris la sauvegarde qui aurait permis de s'en sortir. Le produit mesure
 * donc ce qu'il occupe, catégorie par catégorie, et **refuse d'écrire** avant
 * d'atteindre la limite plutôt qu'après.
 *
 * La mesure est faite à la demande, pas en continu : parcourir quelques
 * milliers de fichiers coûte moins cher qu'un compteur qu'il faudrait tenir à
 * jour à chaque écriture et qui finirait par mentir.
 */
final class QuotaService
{
    /**
     * Répertoires mesurés, avec leur réglage de quota.
     *
     * @var array<string, string> catégorie => clé de réglage (Mo)
     */
    public const CATEGORIES = [
        'media' => 'quota.media_mb',
        'documents' => 'quota.documents_mb',
        'backups' => 'quota.backups_mb',
        'mail-attachments' => 'quota.attachments_mb',
    ];

    /** Seuil d'alerte : au-delà, l'écran prévient sans encore refuser. */
    public const WARNING_PERCENT = 80;

    public function __construct(
        private readonly Paths $paths,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Usage de chaque catégorie.
     *
     * @return list<array{
     *     category: string,
     *     used_bytes: int,
     *     limit_bytes: int,
     *     percent: float,
     *     exceeded: bool,
     *     warning: bool
     * }>
     */
    public function usage(): array
    {
        $usage = [];

        foreach (self::CATEGORIES as $category => $setting) {
            $used = $this->bytesIn($category);
            $limit = $this->limitBytes($setting);
            $percent = $limit > 0 ? round(($used / $limit) * 100, 1) : 0.0;

            $usage[] = [
                'category' => $category,
                'used_bytes' => $used,
                'limit_bytes' => $limit,
                'percent' => $percent,
                'exceeded' => $limit > 0 && $used >= $limit,
                'warning' => $limit > 0 && $percent >= self::WARNING_PERCENT && $used < $limit,
            ];
        }

        return $usage;
    }

    /**
     * Une écriture de cette taille tient-elle dans le quota ?
     *
     * Un quota à zéro signifie « pas de limite » : c'est la configuration par
     * défaut, et le produit ne doit pas empêcher d'écrire tant que le
     * propriétaire n'a rien décidé.
     */
    public function allows(string $category, int $bytes = 0): bool
    {
        $setting = self::CATEGORIES[$category] ?? null;
        if ($setting === null) {
            return true;
        }

        $limit = $this->limitBytes($setting);
        if ($limit <= 0) {
            return true;
        }

        return ($this->bytesIn($category) + max(0, $bytes)) <= $limit;
    }

    /**
     * Catégories qui ont atteint leur quota.
     *
     * @return list<string>
     */
    public function exceeded(): array
    {
        $exceeded = [];
        foreach ($this->usage() as $entry) {
            if ($entry['exceeded']) {
                $exceeded[] = $entry['category'];
            }
        }

        return $exceeded;
    }

    /**
     * Total occupé par le stockage applicatif.
     */
    public function totalBytes(): int
    {
        $total = 0;
        foreach (array_keys(self::CATEGORIES) as $category) {
            $total += $this->bytesIn($category);
        }

        return $total;
    }

    /**
     * Octets occupés par une catégorie.
     */
    public function bytesIn(string $category): int
    {
        $path = $this->paths->storage($category);
        if (!is_dir($path)) {
            return 0;
        }

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    private function limitBytes(string $setting): int
    {
        return max(0, $this->settings->int($setting)) * 1024 * 1024;
    }
}
