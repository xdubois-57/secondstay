<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use PHPUnit\Framework\TestCase;
use SecondStay\Core\Http\Request;
use SecondStay\Core\Http\Response;
use SecondStay\Core\Kernel;
use SecondStay\Core\Session;

/**
 * Noyau démarré sur une racine de projet temporaire SANS `config/local.php`.
 *
 * L'état décrit est donc celui d'une archive fraîchement déployée par FTP.
 * Le bac à sable évite toute dépendance à l'état du dépôt de travail (une
 * campagne E2E peut y avoir laissé une installation réelle).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<string> répertoires partagés avec le dépôt */
    private const SHARED = ['templates', 'translations', 'migrations', 'public', 'vendor'];

    private ?Kernel $bootedKernel = null;

    protected string $appRoot;

    protected function setUp(): void
    {
        $this->appRoot = sys_get_temp_dir() . '/secondstay-kernel-' . bin2hex(random_bytes(6));
        mkdir($this->appRoot . '/config', 0o750, true);
        mkdir($this->appRoot . '/storage', 0o750, true);

        $source = $this->repositoryRoot();
        foreach (self::SHARED as $shared) {
            symlink($source . '/' . $shared, $this->appRoot . '/' . $shared);
        }

        copy($source . '/config/app.php', $this->appRoot . '/config/app.php');
        copy($source . '/VERSION', $this->appRoot . '/VERSION');
    }

    protected function tearDown(): void
    {
        $this->bootedKernel = null;

        foreach (self::SHARED as $shared) {
            $link = $this->appRoot . '/' . $shared;
            if (is_link($link)) {
                unlink($link);
            }
        }

        self::removeDirectory($this->appRoot);
    }

    protected function repositoryRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function projectRoot(): string
    {
        return $this->appRoot;
    }

    protected function kernel(): Kernel
    {
        // Une seule instance par test : la session en mémoire joue le rôle du
        // navigateur d'une requête à l'autre.
        if ($this->bootedKernel === null) {
            $this->bootedKernel = new Kernel($this->projectRoot());
            $container = $this->bootedKernel->boot();
            $session = new Session();
            $session->start();
            $session->regenerate();
            $container->instance(Session::class, $session);
        }

        return $this->bootedKernel;
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $post
     */
    protected function request(
        string $path,
        string $method = 'GET',
        array $server = [],
        array $cookies = [],
        array $post = [],
    ): Request {
        return new Request($method, $path, [], $post, $server, $cookies);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    protected function get(string $path, array $server = [], array $cookies = []): Response
    {
        return $this->kernel()->handle($this->request($path, 'GET', $server, $cookies));
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
