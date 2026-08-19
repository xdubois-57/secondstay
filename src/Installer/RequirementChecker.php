<?php

declare(strict_types=1);

namespace SecondStay\Installer;

use SecondStay\Core\Paths;

/**
 * Contrôle des prérequis d'hébergement mutualisé.
 */
final class RequirementChecker
{
    public const MIN_PHP = '8.2.0';

    /** @var list<string> */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'openssl', 'sodium', 'json', 'zip', 'dom', 'intl', 'fileinfo',
    ];

    /** @var list<string> */
    public const RECOMMENDED_EXTENSIONS = ['gd', 'curl', 'exif'];

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @return list<array{id: string, ok: bool, required: bool, detail: string}>
     */
    public function check(): array
    {
        $results = [];

        $results[] = [
            'id' => 'php_version',
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'required' => true,
            'detail' => PHP_VERSION,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $results[] = [
                'id' => 'ext_' . $extension,
                'ok' => extension_loaded($extension),
                'required' => true,
                'detail' => $extension,
            ];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $extension) {
            $results[] = [
                'id' => 'ext_' . $extension,
                'ok' => extension_loaded($extension),
                'required' => false,
                'detail' => $extension,
            ];
        }

        $configDirectory = $this->paths->root('config');
        $results[] = [
            'id' => 'config_writable',
            'ok' => is_dir($configDirectory) && is_writable($configDirectory),
            'required' => true,
            'detail' => $configDirectory,
        ];

        $storage = $this->paths->storage();
        $storageWritable = is_dir($storage)
            ? is_writable($storage)
            : (is_dir(dirname($storage)) && is_writable(dirname($storage)));
        $results[] = [
            'id' => 'storage_writable',
            'ok' => $storageWritable,
            'required' => true,
            'detail' => $storage,
        ];

        $freeBytes = @disk_free_space($this->paths->root());
        $results[] = [
            'id' => 'disk_space',
            'ok' => $freeBytes === false || $freeBytes > 100 * 1024 * 1024,
            'required' => false,
            'detail' => $freeBytes === false ? 'unknown' : self::humanBytes((int) $freeBytes),
        ];

        return $results;
    }

    public function isSatisfied(): bool
    {
        foreach ($this->check() as $result) {
            if ($result['required'] && !$result['ok']) {
                return false;
            }
        }

        return true;
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%.1f %s', $value, $units[$index]);
    }
}
