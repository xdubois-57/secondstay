<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use SecondStay\Auth\PasswordHasher;
use SecondStay\Content\ContentRepository;
use SecondStay\Content\ContentSeeder;
use SecondStay\Auth\Role;
use SecondStay\Auth\UserRepository;
use SecondStay\Auth\UserStatus;
use SecondStay\Core\Container;
use SecondStay\Core\Http\Request;
use SecondStay\Core\Http\Response;
use SecondStay\Core\Kernel;
use SecondStay\Core\Session;
use SecondStay\Security\Csrf;
use SecondStay\I18n\Translator;
use SecondStay\Security\Encryptor;

/**
 * Application réellement installée dans un bac à sable.
 *
 * Le Kernel est démarré sur une racine de projet temporaire disposant de sa
 * propre `config/local.php` et de son propre `storage/`, mais partageant le
 * code, les templates et les traductions du dépôt. Cela permet de tester les
 * parcours complets (installation terminée, authentification, autorisations,
 * CSRF, maintenance) sans toucher au dépôt.
 */
abstract class InstalledAppTestCase extends DatabaseTestCase
{
    protected string $appRoot;

    protected Kernel $kernel;

    protected Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appRoot = sys_get_temp_dir() . '/secondstay-app-' . bin2hex(random_bytes(6));
        mkdir($this->appRoot . '/config', 0o750, true);

        $source = self::projectRoot();
        foreach (['templates', 'translations', 'migrations', 'public', 'vendor'] as $shared) {
            symlink($source . '/' . $shared, $this->appRoot . '/' . $shared);
        }

        copy($source . '/config/app.php', $this->appRoot . '/config/app.php');
        file_put_contents($this->appRoot . '/VERSION', "1.0.0\n");

        $config = self::databaseConfig();
        self::assertNotNull($config);

        file_put_contents($this->appRoot . '/config/local.php', "<?php\n\nreturn " . var_export([
            'app' => ['env' => 'testing', 'debug' => false],
            'database' => [
                'host' => $config->host,
                'port' => $config->port,
                'name' => $config->name,
                'user' => $config->user,
                'password' => $config->password,
                'charset' => $config->charset,
            ],
            'security' => [
                'encryption_keys' => ['k1' => Encryptor::generateKey()],
                'active_encryption_key' => 'k1',
            ],
        ], true) . ";\n");

        $this->kernel = new Kernel($this->appRoot);
        $this->container = $this->kernel->boot();
        $this->container->get(\SecondStay\Core\Paths::class)->ensureStorageDirectories();

        // Session en mémoire : elle joue le rôle du navigateur d'un bout à
        // l'autre du test, sans dépendre de `$_SESSION` en CLI.
        $session = new Session();
        $session->start();
        $session->regenerate();
        $this->container->instance(Session::class, $session);

        $this->createAdministrator();

        // L'installation réelle crée l'arborescence de contenu par défaut :
        // les tests travaillent donc sur un site complet.
        (new ContentSeeder(
            new ContentRepository($this->database),
            new Translator(self::projectRoot() . '/translations'),
            $this->database,
        ))->seed();
    }

    protected function tearDown(): void
    {
        foreach (['templates', 'translations', 'migrations', 'public', 'vendor'] as $shared) {
            $link = $this->appRoot . '/' . $shared;
            if (is_link($link)) {
                unlink($link);
            }
        }
        self::removeDirectory($this->appRoot);

        parent::tearDown();
    }

    protected const ADMIN_EMAIL = 'owner@example.test';
    protected const ADMIN_PASSWORD = 'Marée-Haute-2026!';
    protected const MANAGER_EMAIL = 'manager@example.test';
    protected const CUSTOMER_EMAIL = 'guest@example.test';

    protected function createAdministrator(): int
    {
        return (new UserRepository($this->database))->create(
            self::ADMIN_EMAIL,
            (new PasswordHasher())->hash(self::ADMIN_PASSWORD),
            'Claire',
            'Dubois',
            '',
            Role::Administrator,
            'fr',
            UserStatus::Active,
        );
    }

    protected function createUser(string $email, Role $role, string $locale = 'fr'): int
    {
        return (new UserRepository($this->database))->create(
            $email,
            (new PasswordHasher())->hash(self::ADMIN_PASSWORD),
            'Test',
            'Utilisateur',
            '',
            $role,
            $locale,
            UserStatus::Active,
        );
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $query
     */
    protected function request(
        string $path,
        string $method = 'GET',
        array $post = [],
        array $server = [],
        array $cookies = [],
        array $query = [],
    ): Response {
        return $this->kernel->handle(
            new Request($method, $path, $query, $post, $server, $cookies)
        );
    }

    protected function session(): Session
    {
        return $this->container->get(Session::class);
    }

    protected function csrfToken(): string
    {
        return $this->container->get(Csrf::class)->token();
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array<string, mixed>
     */
    protected function withCsrf(array $post): array
    {
        return $post + [Csrf::FIELD => $this->csrfToken()];
    }

    protected function loginAs(string $email = self::ADMIN_EMAIL): void
    {
        $response = $this->request('/fr/login', 'POST', $this->withCsrf([
            'email' => $email,
            'password' => self::ADMIN_PASSWORD,
        ]));

        self::assertSame(302, $response->status(), 'La connexion aurait dû réussir.');
    }
}
