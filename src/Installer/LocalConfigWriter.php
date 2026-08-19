<?php

declare(strict_types=1);

namespace SecondStay\Installer;

use RuntimeException;
use SecondStay\Database\DatabaseConfig;
use SensitiveParameter;

/**
 * Écrit `config/local.php`.
 *
 * Ce fichier contient les identifiants de base et la clé de chiffrement : il
 * n'est jamais versionné, jamais servi par le serveur web et ses permissions
 * sont restreintes.
 */
final class LocalConfigWriter
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @param array<string, string> $encryptionKeys identifiant => clé hexadécimale
     */
    public function write(
        DatabaseConfig $database,
        #[SensitiveParameter] array $encryptionKeys,
        string $activeKeyId,
        string $environment = 'production',
        bool $debug = false,
    ): void {
        $content = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "/**\n"
            . " * Configuration locale de SecondStay — générée à l'installation.\n"
            . " *\n"
            . " * NE JAMAIS versionner ni publier ce fichier : il contient les identifiants\n"
            . " * de base de données et les clés de chiffrement de l'installation.\n"
            . " */\n\n"
            . "return [\n"
            . "    'app' => [\n"
            . '        ' . $this->exportPair('env', $environment)
            . '        ' . $this->exportPair('debug', $debug)
            . "    ],\n"
            . "    'database' => [\n"
            . '        ' . $this->exportPair('host', $database->host)
            . '        ' . $this->exportPair('port', $database->port)
            . '        ' . $this->exportPair('name', $database->name)
            . '        ' . $this->exportPair('user', $database->user)
            . '        ' . $this->exportPair('password', $database->password)
            . '        ' . $this->exportPair('charset', $database->charset)
            . "    ],\n"
            . "    'security' => [\n"
            . "        'encryption_keys' => [\n";

        foreach ($encryptionKeys as $id => $key) {
            $content .= '            ' . var_export((string) $id, true) . ' => ' . var_export($key, true) . ",\n";
        }

        $content .= "        ],\n"
            . '        ' . $this->exportPair('active_encryption_key', $activeKeyId)
            . "    ],\n"
            . "];\n";

        $directory = dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Le répertoire de configuration n’est pas accessible en écriture.');
        }

        $temporary = $this->path . '.tmp';
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Écriture de la configuration locale impossible.');
        }

        @chmod($temporary, 0o600);

        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('Installation de la configuration locale impossible.');
        }
    }

    public function backupExisting(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }

        $backup = $this->path . '.' . gmdate('Ymd-His') . '.bak';
        if (!copy($this->path, $backup)) {
            throw new RuntimeException('Sauvegarde de la configuration locale impossible.');
        }
        @chmod($backup, 0o600);

        return $backup;
    }

    private function exportPair(string $key, mixed $value): string
    {
        return var_export($key, true) . ' => ' . var_export($value, true) . ",\n";
    }
}
