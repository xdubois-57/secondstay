<?php

declare(strict_types=1);

/**
 * Remet l'environnement E2E dans l'état « archive fraîchement déployée » :
 * base de test vide, aucune configuration locale, stockage runtime nettoyé.
 *
 * Ce script refuse de s'exécuter sans base de test explicitement configurée
 * (TESTING.md §5) : il ne peut donc jamais toucher une base de production.
 */

require __DIR__ . '/../vendor/autoload.php';

use SecondStay\Database\Database;
use SecondStay\Database\DatabaseConfig;

$root = dirname(__DIR__);

$name = getenv('SECONDSTAY_TEST_DB_NAME');
if ($name === false || $name === '') {
    fwrite(STDERR, "SECONDSTAY_TEST_DB_NAME est requis pour réinitialiser l'environnement E2E.\n");
    exit(1);
}

$config = new DatabaseConfig(
    (string) (getenv('SECONDSTAY_TEST_DB_HOST') ?: '127.0.0.1'),
    (int) (getenv('SECONDSTAY_TEST_DB_PORT') ?: '3306'),
    $name,
    (string) (getenv('SECONDSTAY_TEST_DB_USER') ?: 'root'),
    (string) (getenv('SECONDSTAY_TEST_DB_PASSWORD') ?: ''),
);

$database = new Database($config);
if (!$database->isReachable()) {
    fwrite(STDERR, "Base de test injoignable.\n");
    exit(1);
}

$pdo = $database->pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($database->tables() as $table) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// Uniquement la configuration locale générée : jamais le modèle versionné
// `config/local.php.dist`.
foreach (array_merge(
    [$root . '/config/local.php'],
    glob($root . '/config/local.php.*.bak') ?: []
) as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$storage = $root . '/storage';
foreach (['media', 'documents', 'inspections', 'mail-attachments', 'backups', 'logs', 'cache/twig', 'cache/icons', 'temp'] as $directory) {
    $path = $storage . '/' . $directory;
    if (!is_dir($path)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
}

@unlink($storage . '/maintenance.json');

fwrite(STDOUT, "Environnement E2E réinitialisé (base « " . $name . " »).\n");
