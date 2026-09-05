<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use PHPUnit\Framework\TestCase;
use SecondStay\Auth\PasswordHasher;
use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\DatabaseConfig;
use SecondStay\Database\Migrator;

/**
 * Base des tests d'intégration base de données.
 *
 * La base de test est explicitement configurée par variables d'environnement
 * (TESTING.md §5) : jamais de base de production, jamais de valeur par défaut
 * pointant vers une installation réelle.
 *
 * ## Pourquoi une base absente est un échec et non un test ignoré
 *
 * Un test qui s'ignore ne fait pas échouer la suite. Sans base, cette suite
 * n'était pourtant **pas** verte : 705 tests ignorés, et 34 erreurs venues de
 * sous-classes dont le `tearDown()` touche une propriété que le `setUp()`
 * interrompu n'avait pas initialisée. Le compte rendu disait donc « Typed
 * property $sandboxRoot must not be accessed before initialization » — un
 * message qui envoie chercher un défaut d'initialisation dans le test, jamais
 * une base éteinte.
 *
 * Deux garde-fous, pour deux situations différentes :
 *
 * - **base injoignable** : toujours un échec, jamais un `skip`. Avoir dit où
 *   elle se trouve et ne pas l'y trouver est une panne, pas une absence de
 *   configuration. Chaque test nomme alors la vraie cause ;
 * - **base non configurée** : un `skip` en local — quelqu'un lance `phpunit`
 *   sur un portable — mais un échec dès que `SECONDSTAY_TEST_DB_REQUIRED=1`.
 *
 * Ce second garde-fou couvre un trou **latent**, pas actuel : ce sont les 34
 * erreurs incidentes ci-dessus qui rendent aujourd'hui la suite rouge. Que
 * quelqu'un assainisse ces `tearDown()` — un nettoyage parfaitement
 * raisonnable — et il resterait 739 tests ignorés, tous verts, en n'ayant
 * touché aucune base. La variable rend cet état impossible d'avance.
 *
 * La question à poser devant n'importe quelle gate : à quoi ressemblerait un
 * vert si la chose n'avait pas tourné du tout ? Quand la réponse est
 * « pareil », le signal n'en est pas un.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;

    protected Paths $paths;

    protected string $storagePath;

    protected function setUp(): void
    {
        $config = self::databaseConfig();
        if ($config === null) {
            // Une base absente est une situation locale légitime : quelqu'un
            // lance `phpunit` sur un portable sans base de test. En CI, c'est
            // un harnais cassé, et `SECONDSTAY_TEST_DB_REQUIRED` fait la
            // différence — voir l'en-tête de cette classe.
            if (self::databaseIsRequired()) {
                self::fail(
                    'SECONDSTAY_TEST_DB_REQUIRED=1 mais aucune base de test n’est configurée '
                    . '(SECONDSTAY_TEST_DB_NAME). Cette exécution n’aurait rien prouvé.'
                );
            }

            self::markTestSkipped('Base de test non configurée (SECONDSTAY_TEST_DB_NAME).');
        }

        $this->database = new Database($config);
        if (!$this->database->isReachable()) {
            // Jamais un `skip`, nulle part : avoir dit où se trouve la base et
            // ne pas l’y trouver n’est pas une absence de configuration, c’est
            // une panne. La sauter rendrait la suite verte au moment précis où
            // elle ne teste plus rien.
            self::fail(sprintf(
                'Base de test injoignable : %s. La configuration la désigne, elle doit répondre.',
                $config->name
            ));
        }

        // `realpath()` sur le dossier temporaire, pas seulement sur le bac à
        // sable : sur macOS `sys_get_temp_dir()` rend `/var/folders/…`, un lien
        // symbolique vers `/private/var/folders/…`. Le produit, lui, résout ses
        // chemins avant de les comparer à la racine du stockage — c'est ce qui
        // empêche un chemin corrompu de sortir du bac à sable. Un test qui
        // garde la forme non résolue compare alors deux écritures du même
        // dossier et échoue sur un produit correct.
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false) {
            self::markTestSkipped('Dossier temporaire introuvable.');
        }

        $this->storagePath = $temporary . '/secondstay-test-' . bin2hex(random_bytes(6));
        $this->paths = new Paths(self::projectRoot(), $this->storagePath);
        $this->paths->ensureStorageDirectories();

        $this->resetSchema();
    }

    /**
     * La CI et `scripts/check.sh --db` posent cette variable. Elle transforme
     * « pas de base, donc rien à tester » en échec, parce qu'une exécution
     * automatisée qui n'a touché aucune base n'a pas fait son travail.
     */
    protected static function databaseIsRequired(): bool
    {
        return getenv('SECONDSTAY_TEST_DB_REQUIRED') === '1';
    }

    protected function tearDown(): void
    {
        // `setUp()` peut avoir marqué le test ignoré avant d'ouvrir un bac à
        // sable : le nettoyage ne doit pas masquer la raison réelle.
        if (isset($this->storagePath)) {
            self::removeDirectory($this->storagePath);
        }
    }

    /**
     * Empreintes déjà calculées, par mot de passe.
     *
     * @var array<string, string>
     */
    private static array $passwordHashes = [];

    /**
     * Empreinte d'un mot de passe, calculée une seule fois par exécution.
     *
     * `password_hash` est lent **par construction** : c'est sa raison d'être,
     * et le produit doit le payer. Un test qui a seulement besoin d'un compte
     * utilisable, non — il payait jusqu'ici deux cents millisecondes par
     * compte créé, soit plus de deux minutes sur la suite.
     *
     * La valeur reste une vraie empreinte, vérifiable par `password_verify` :
     * seul le nombre de calculs change, jamais leur force. Les tests qui
     * portent sur le hachage lui-même appellent `PasswordHasher` directement.
     */
    protected static function passwordHash(string $password): string
    {
        return self::$passwordHashes[$password] ??= (new PasswordHasher())->hash($password);
    }

    /**
     * Tables attendues après migration, mémorisées au premier passage.
     *
     * @var list<string>
     */
    private static array $schemaTables = [];

    /**
     * Lignes de suivi des migrations, telles qu'elles existent juste après
     * une migration complète.
     *
     * @var list<array<string, mixed>>
     */
    private static array $migrationRows = [];

    /**
     * Rend la base à l'état d'une installation fraîchement migrée.
     *
     * Reconstruire le schéma — supprimer vingt tables puis rejouer quatorze
     * migrations — coûtait plusieurs secondes **par test**, soit l'essentiel
     * de la durée de la suite. Or aucune migration n'insère de donnée : l'état
     * après migration est donc entièrement décrit par « toutes les tables
     * vides, plus le suivi des migrations ». `TRUNCATE` le reproduit
     * exactement, compteurs d'auto-incrément compris.
     *
     * La reconstruction complète reste faite au premier test, et refaite dès
     * que le schéma ne correspond plus à ce qui est attendu — un test qui
     * crée ou supprime une table se répare donc tout seul.
     */
    protected function resetSchema(): void
    {
        $tables = $this->database->tables();

        if (self::$schemaTables === [] || $tables !== self::$schemaTables) {
            $this->rebuildSchema();

            return;
        }

        $this->truncateSchema($tables);
    }

    /**
     * Reconstruction complète : suppression puis migration.
     */
    protected function rebuildSchema(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->database->tables() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $this->migrator()->migrate();

        self::$schemaTables = $this->database->tables();
        self::$migrationRows = $this->database->fetchAll(
            'SELECT * FROM `' . Migrator::TABLE . '` ORDER BY `version`'
        );
    }

    /**
     * @param list<string> $tables
     */
    private function truncateSchema(array $tables): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            $pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        // Le suivi des migrations fait partie de l'état attendu : sans lui,
        // le produit croirait la base non migrée.
        foreach (self::$migrationRows as $row) {
            $this->database->insert(Migrator::TABLE, $row);
        }
    }

    protected function migrator(): Migrator
    {
        return new Migrator($this->database, self::projectRoot() . '/migrations');
    }

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function databaseConfig(): ?DatabaseConfig
    {
        $name = getenv('SECONDSTAY_TEST_DB_NAME');
        if ($name === false || $name === '') {
            return null;
        }

        return new DatabaseConfig(
            (string) (getenv('SECONDSTAY_TEST_DB_HOST') ?: '127.0.0.1'),
            (int) (getenv('SECONDSTAY_TEST_DB_PORT') ?: '3306'),
            $name,
            (string) (getenv('SECONDSTAY_TEST_DB_USER') ?: 'root'),
            (string) (getenv('SECONDSTAY_TEST_DB_PASSWORD') ?: ''),
        );
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
