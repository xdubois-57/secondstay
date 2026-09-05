<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

// `bootstrap/bootstrap.php` s'exécute normalement dès qu'il est chargé : c'est
// un installeur, pas une bibliothèque. La constante ci-dessous neutralise cet
// appel et rend le fichier chargeable comme n'importe quel autre.
if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 4) . '/bootstrap/bootstrap.php';

/**
 * Fonctions pures de l'installeur autonome.
 *
 * L'installeur tourne sur un hébergement dont personne ici ne sait rien, une
 * seule fois, chez quelqu'un qui n'a que du FTP pour réparer. Les décisions
 * qu'il prend — refuser une archive, annuler une installation, juger qu'un
 * fichier est protégé — sont donc testées ici une par une, y compris celles
 * dont l'échec est invisible tant qu'il ne s'est jamais produit.
 */
final class BootstrapTest extends TestCase
{
    private string $temporaryDirectory = '';

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . '/secondstay-bootstrap-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0o700, true);
    }

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory !== '' && is_dir($this->temporaryDirectory)) {
            bootstrap_remove_directory($this->temporaryDirectory);
        }
    }

    // -------------------------------------------------------------------
    // Préflight
    // -------------------------------------------------------------------

    public function testTheInstallerRefusesToRunFromASubdirectory(): void
    {
        self::assertTrue(bootstrap_check_location(['SCRIPT_NAME' => '/bootstrap.php'])['ok']);
        self::assertFalse(bootstrap_check_location(['SCRIPT_NAME' => '/site/bootstrap.php'])['ok']);
    }

    /**
     * Le plancher est celui de `composer.json`, pas celui de la machine de
     * développement : un installeur plus exigeant que l'application
     * refuserait des hébergements sur lesquels elle tourne.
     */
    public function testThePhpFloorIsTheOneComposerDeclares(): void
    {
        self::assertSame('8.2.0', BOOTSTRAP_MIN_PHP_VERSION);
        self::assertTrue(bootstrap_check_php_version('8.2.0')['ok']);
        self::assertTrue(bootstrap_check_php_version('8.4.1')['ok']);
        self::assertFalse(bootstrap_check_php_version('8.1.29')['ok']);
    }

    public function testAMissingExtensionIsNamed(): void
    {
        $result = bootstrap_check_extensions(['pdo', 'json', 'mbstring', 'openssl', 'dom', 'fileinfo']);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('intl', $result['detail']);
        self::assertStringContainsString('sodium', $result['detail']);
        self::assertStringContainsString('curl', $result['detail']);
    }

    public function testEveryRequiredExtensionSatisfiesTheCheck(): void
    {
        self::assertTrue(bootstrap_check_extensions(BOOTSTRAP_REQUIRED_EXTENSIONS)['ok']);
    }

    public function testOutboundHttpsIsJudgedByTheProbeAlone(): void
    {
        self::assertTrue(bootstrap_check_outbound_https(static fn (): bool => true)['ok']);
        self::assertFalse(bootstrap_check_outbound_https(static fn (): bool => false)['ok']);
    }

    /**
     * `is_writable()` ment sous ACL et sous `open_basedir`. La sonde écrit,
     * relit, supprime, puis crée et retire un dossier — et ne laisse rien.
     */
    public function testTheWritableProbeWritesReadsAndLeavesNothingBehind(): void
    {
        self::assertTrue(bootstrap_probe_writable($this->temporaryDirectory));
        self::assertSame([], array_values(array_diff(
            scandir($this->temporaryDirectory) ?: [],
            ['.', '..']
        )));

        self::assertFalse(bootstrap_probe_writable($this->temporaryDirectory . '/absent'));
    }

    /**
     * Chacun des trois indices suffit seul : une installation dont le
     * `VERSION` a été supprimé à la main reste une installation.
     */
    public function testAnExistingInstallationIsNeverOverwritten(): void
    {
        self::assertFalse(bootstrap_already_installed($this->temporaryDirectory));

        file_put_contents($this->temporaryDirectory . '/VERSION', "1.0.0\n");
        self::assertTrue(bootstrap_already_installed($this->temporaryDirectory));

        unlink($this->temporaryDirectory . '/VERSION');
        mkdir($this->temporaryDirectory . '/src');
        self::assertTrue(bootstrap_already_installed($this->temporaryDirectory));

        rmdir($this->temporaryDirectory . '/src');
        mkdir($this->temporaryDirectory . '/config');
        file_put_contents($this->temporaryDirectory . '/config/local.php', '<?php return [];');
        self::assertTrue(bootstrap_already_installed($this->temporaryDirectory));
    }

    // -------------------------------------------------------------------
    // Résolution de la release
    // -------------------------------------------------------------------

    /**
     * GitHub trie les assets par nom : `bootstrap.php`, publié sur la même
     * release, arrive avant `secondstay-X.Y.Z.zip`. Prendre `assets[0]`
     * téléchargerait l'installeur lui-même.
     */
    public function testTheArchiveIsChosenByNameAndNotByPosition(): void
    {
        $archive = bootstrap_resolve_archive_url([
            'assets' => [
                ['name' => 'bootstrap.php', 'browser_download_url' => 'https://example.test/bootstrap.php', 'size' => 1],
                ['name' => 'secondstay-1.2.3.zip', 'browser_download_url' => 'https://example.test/a.zip', 'size' => 42],
            ],
            'zipball_url' => 'https://example.test/zipball',
        ]);

        self::assertSame('https://example.test/a.zip', $archive['url']);
        self::assertSame(42, $archive['size']);
        self::assertSame('asset', $archive['source']);
    }

    /**
     * Le pack de preuves est publié sur la même release et porte lui aussi
     * l'extension `.zip`. Il n'est pas installable.
     */
    public function testTheEvidencePackIsNeverMistakenForTheArtefact(): void
    {
        $archive = bootstrap_resolve_archive_url([
            'assets' => [
                ['name' => 'evidence.zip', 'browser_download_url' => 'https://example.test/evidence.zip', 'size' => 9],
                ['name' => 'secondstay-1.2.3.zip', 'browser_download_url' => 'https://example.test/a.zip', 'size' => 42],
            ],
        ]);

        self::assertSame('https://example.test/a.zip', $archive['url']);
    }

    public function testTheZipballIsTheFallbackWhenNoAssetIsPublished(): void
    {
        $archive = bootstrap_resolve_archive_url(['assets' => [], 'zipball_url' => 'https://example.test/z']);

        self::assertSame('zipball', $archive['source']);
        self::assertSame(0, $archive['size']);
    }

    public function testAReleaseWithoutAnyArchiveIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        bootstrap_resolve_archive_url(['assets' => []]);
    }

    /**
     * Le dépouillement du dossier racine dépend du **type de source**, jamais
     * du nombre d'entrées : un artefact qui n'aurait qu'une seule entrée de
     * premier niveau ne doit pas être dépouillé.
     */
    public function testOnlyAZipballHasItsSingleTopLevelDirectoryStripped(): void
    {
        mkdir($this->temporaryDirectory . '/xdubois-57-secondstay-abc1234/src', 0o700, true);

        self::assertSame(
            $this->temporaryDirectory . '/xdubois-57-secondstay-abc1234',
            bootstrap_resolve_archive_root($this->temporaryDirectory, 'zipball')
        );
        self::assertSame(
            $this->temporaryDirectory,
            bootstrap_resolve_archive_root($this->temporaryDirectory, 'asset')
        );
    }

    public function testTheDownloadIsRetriedAndTheLastFailureIsTheOneReported(): void
    {
        $attempts = 0;
        $destination = $this->temporaryDirectory . '/artifact.zip';

        bootstrap_download_with_retry(
            'https://example.test/a.zip',
            $destination,
            static function (string $url, string $path) use (&$attempts): void {
                $attempts++;
                if ($attempts < 3) {
                    throw new RuntimeException('coupure réseau');
                }
                file_put_contents($path, 'PK contenu');
            }
        );

        self::assertSame(3, $attempts);
        self::assertFileExists($destination);
    }

    public function testAnEmptyDownloadIsAFailureAndNotASuccess(): void
    {
        $this->expectException(RuntimeException::class);

        bootstrap_download_with_retry(
            'https://example.test/a.zip',
            $this->temporaryDirectory . '/artifact.zip',
            static function (string $url, string $path): void {
                file_put_contents($path, '');
            }
        );
    }

    /**
     * Une mesure impossible ne bloque pas l'installation : elle la laisse
     * passer en le disant. Une mesure possible et insuffisante l'arrête.
     */
    public function testDiskSpaceBlocksOnlyWhenItIsBothMeasurableAndInsufficient(): void
    {
        $tight = bootstrap_check_disk_space($this->temporaryDirectory, 100, static fn (): float => 200.0);
        self::assertFalse($tight['ok']);
        self::assertFalse($tight['degraded']);

        $roomy = bootstrap_check_disk_space($this->temporaryDirectory, 100, static fn (): float => 100000.0);
        self::assertTrue($roomy['ok']);

        $unmeasurable = bootstrap_check_disk_space($this->temporaryDirectory, 100, static fn (): bool => false);
        self::assertTrue($unmeasurable['ok']);
        self::assertTrue($unmeasurable['degraded']);

        $unknownSize = bootstrap_check_disk_space($this->temporaryDirectory, 0, static fn (): float => 1.0);
        self::assertTrue($unknownSize['ok']);
        self::assertTrue($unknownSize['degraded']);
    }

    // -------------------------------------------------------------------
    // Extraction
    // -------------------------------------------------------------------

    /**
     * @return list<array{0: string, 1: bool}>
     */
    public static function zipEntryProvider(): array
    {
        return [
            ['src/Core/Kernel.php', false],
            ['.htaccess', false],
            ['../etc/passwd', true],
            ['a/../../b', true],
            ['/etc/passwd', true],
            ['C:/windows/system32', true],
            ['a/..', true],
            ['..', true],
            ['a\\..\\..\\b', true],
        ];
    }

    #[DataProvider('zipEntryProvider')]
    public function testZipSlipEntriesAreRecognised(string $entry, bool $dangerous): void
    {
        self::assertSame($dangerous, bootstrap_is_zip_slip($entry));
    }

    /**
     * L'archive est validée **entièrement** avant que le premier octet ne
     * soit écrit : une extraction partielle suivie d'un refus laisserait sur
     * le disque exactement ce qu'on voulait éviter.
     */
    public function testADangerousArchiveIsRefusedWithoutWritingAnything(): void
    {
        $zipPath = $this->temporaryDirectory . '/evil.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('innocent.txt', 'bonjour');
        $zip->addFromString('../evade.txt', 'échappée');
        $zip->close();

        $destination = $this->temporaryDirectory . '/out';
        mkdir($destination, 0o700, true);

        try {
            bootstrap_extract_zip_safely($zipPath, $destination);
            self::fail("L'archive dangereuse aurait dû être refusée.");
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('evade.txt', $exception->getMessage());
        }

        self::assertFileDoesNotExist($destination . '/innocent.txt');
    }

    public function testASoundArchiveIsExtracted(): void
    {
        $zipPath = $this->temporaryDirectory . '/ok.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE) === true);
        $zip->addFromString('.htaccess', 'Require all denied');
        $zip->addFromString('public/index.php', '<?php');
        $zip->close();

        $destination = $this->temporaryDirectory . '/out';
        mkdir($destination, 0o700, true);
        bootstrap_extract_zip_safely($zipPath, $destination);

        self::assertFileExists($destination . '/.htaccess');
        self::assertFileExists($destination . '/public/index.php');
    }

    public function testAnUnreadableArchiveIsRefused(): void
    {
        file_put_contents($this->temporaryDirectory . '/not-a.zip', 'pas du tout un zip');

        $this->expectException(RuntimeException::class);

        bootstrap_extract_zip_safely($this->temporaryDirectory . '/not-a.zip', $this->temporaryDirectory);
    }

    /**
     * `.htaccess` en tête de la liste n'est pas décoratif : c'est le seul
     * rempart de l'arborescence unique. Une archive qui n'en contient pas ne
     * s'installe pas.
     */
    public function testTheArtefactIsRefusedWhenTheServerRulesAreMissing(): void
    {
        $root = $this->temporaryDirectory . '/artefact';
        $this->writeArtefact($root);
        unlink($root . '/.htaccess');

        $result = bootstrap_verify_artifact($root);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('.htaccess', $result['detail']);
    }

    public function testACompleteArtefactPassesVerification(): void
    {
        $root = $this->temporaryDirectory . '/artefact';
        $this->writeArtefact($root);

        self::assertTrue(bootstrap_verify_artifact($root)['ok']);
    }

    // -------------------------------------------------------------------
    // Copie et suppression
    // -------------------------------------------------------------------

    /**
     * `glob()` ignore silencieusement les fichiers cachés, et `.htaccess` est
     * précisément ce que cette installation ne peut pas se permettre de
     * perdre.
     */
    public function testTheCopyPreservesDotfilesAndHonoursExclusions(): void
    {
        $source = $this->temporaryDirectory . '/src-tree';
        mkdir($source . '/public/assets', 0o700, true);
        mkdir($source . '/storage', 0o700, true);
        file_put_contents($source . '/.htaccess', 'racine');
        file_put_contents($source . '/VERSION', "9.9.9\n");
        file_put_contents($source . '/public/.htaccess', 'public');
        file_put_contents($source . '/public/assets/app.css', 'body{}');
        file_put_contents($source . '/storage/ancien.log', 'à ne pas copier');

        $destination = $this->temporaryDirectory . '/dest';
        $copied = bootstrap_copy_tree($source, $destination, ['storage', 'VERSION']);

        sort($copied);
        self::assertSame(['.htaccess', 'public'], $copied);
        self::assertSame('racine', file_get_contents($destination . '/.htaccess'));
        self::assertSame('public', file_get_contents($destination . '/public/.htaccess'));
        self::assertFileExists($destination . '/public/assets/app.css');
        self::assertFileDoesNotExist($destination . '/VERSION');
        self::assertDirectoryDoesNotExist($destination . '/storage');
    }

    public function testRemovalTakesDotfilesAndNestedDirectoriesWithIt(): void
    {
        $tree = $this->temporaryDirectory . '/tree';
        mkdir($tree . '/a/b', 0o700, true);
        file_put_contents($tree . '/a/b/.hidden', 'x');
        file_put_contents($tree . '/a/file.txt', 'y');

        bootstrap_remove_path($tree);

        self::assertDirectoryDoesNotExist($tree);
    }

    // -------------------------------------------------------------------
    // storage/, VERSION, jeton
    // -------------------------------------------------------------------

    /**
     * La liste doit rester la copie exacte de `Paths::ensureStorageDirectories()` :
     * le portail d'acceptation prouve qu'ils ne sont pas lisibles depuis le
     * web, et un dossier absent de cette liste ne serait jamais prouvé.
     */
    public function testTheStorageLayoutMirrorsTheApplication(): void
    {
        $created = bootstrap_create_storage_dirs($this->temporaryDirectory);

        self::assertSame(BOOTSTRAP_STORAGE_SUBDIRS, $created);
        foreach (BOOTSTRAP_STORAGE_SUBDIRS as $subdirectory) {
            self::assertDirectoryExists($this->temporaryDirectory . '/storage/' . $subdirectory);
        }

        // Aucun bit « autres » : ces dossiers portent des pièces d'identité.
        $mode = fileperms($this->temporaryDirectory . '/storage/documents');
        self::assertIsInt($mode);
        self::assertSame(0, $mode & 0o007);
    }

    public function testCreatingStorageTwiceIsHarmless(): void
    {
        bootstrap_create_storage_dirs($this->temporaryDirectory);

        self::assertSame(BOOTSTRAP_STORAGE_SUBDIRS, bootstrap_create_storage_dirs($this->temporaryDirectory));
    }

    /**
     * Format identique à celui qu'écrit `UpdateService` : `Kernel` relit ce
     * fichier tel quel.
     */
    public function testTheVersionFileCarriesATrailingNewline(): void
    {
        bootstrap_write_version($this->temporaryDirectory, '1.2.3');

        self::assertSame("1.2.3\n", file_get_contents($this->temporaryDirectory . '/VERSION'));
    }

    public function testTheTokenIsThirtyTwoRandomBytes(): void
    {
        $first = bootstrap_generate_token();
        $second = bootstrap_generate_token();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertNotSame($first, $second);
    }

    /**
     * Le fichier de jeton est du PHP valide qui répond 404 : un fichier de
     * secret doit rester inoffensif même exécuté, et pas seulement
     * inaccessible.
     */
    public function testTheTokenFileIsHarmlessEvenWhenExecuted(): void
    {
        $token = bootstrap_generate_token();
        $content = bootstrap_token_file_content($token);

        self::assertStringStartsWith('<?php', $content);
        self::assertStringContainsString('http_response_code(404);', $content);
        self::assertStringContainsString('exit;', $content);
        self::assertStringContainsString(BOOTSTRAP_TOKEN_MARKER . ': ' . $token, $content);

        $path = $this->temporaryDirectory . '/token.php';
        file_put_contents($path, $content);
        exec('php -l ' . escapeshellarg($path), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }

    // -------------------------------------------------------------------
    // Portail d'acceptation — partie serveur
    // -------------------------------------------------------------------

    public function testTheServerSideChecksAgreeOnACompleteInstallation(): void
    {
        $base = $this->temporaryDirectory . '/site';
        $this->writeArtefact($base);
        file_put_contents($base . '/VERSION', "1.2.3\n");
        bootstrap_create_storage_dirs($base);

        foreach ([
            bootstrap_check_s1($base),
            bootstrap_check_s2($base),
            bootstrap_check_s3($base),
            bootstrap_check_s4($base),
            bootstrap_check_s5($base),
            bootstrap_check_s6($base),
            bootstrap_check_s7($base),
            bootstrap_check_s8($base . '/.tmp-absent'),
        ] as $check) {
            self::assertTrue($check['ok'], $check['id'] . ' : ' . $check['detail']);
        }
    }

    public function testAnEmptyVersionFileIsNotAVersionFile(): void
    {
        $base = $this->temporaryDirectory . '/site';
        mkdir($base, 0o700, true);
        file_put_contents($base . '/VERSION', "   \n");

        self::assertFalse(bootstrap_check_s1($base)['ok']);
    }

    /**
     * Les deux `.htaccess` sont livrés par l'artefact et ne sont jamais
     * écrits par l'installeur : ce contrôle vérifie qu'ils sont arrivés.
     */
    public function testBothServerRuleFilesAreRequired(): void
    {
        $base = $this->temporaryDirectory . '/site';
        $this->writeArtefact($base);
        self::assertTrue(bootstrap_check_s4($base)['ok']);

        unlink($base . '/public/.htaccess');
        $result = bootstrap_check_s4($base);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('public/.htaccess', $result['detail']);
    }

    /**
     * Une archive porteuse d'un `config/local.php` transporte les identifiants
     * de base et les clés de chiffrement d'une autre installation.
     */
    public function testAnInheritedLocalConfigurationIsRefused(): void
    {
        $base = $this->temporaryDirectory . '/site';
        $this->writeArtefact($base);
        self::assertTrue(bootstrap_check_s7($base)['ok']);

        file_put_contents($base . '/config/local.php', '<?php return [];');
        $result = bootstrap_check_s7($base);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('local.php', $result['detail']);
    }

    public function testAStorageDirectoryThatCannotBeWrittenFailsTheGate(): void
    {
        $base = $this->temporaryDirectory . '/site';
        mkdir($base . '/storage', 0o700, true);

        self::assertFalse(bootstrap_check_s6($base)['ok']);
    }

    public function testALeftoverTemporaryDirectoryFailsTheGate(): void
    {
        $temporary = $this->temporaryDirectory . '/.tmp-abc';
        mkdir($temporary, 0o700, true);

        self::assertFalse(bootstrap_check_s8($temporary)['ok']);
        bootstrap_remove_directory($temporary);
        self::assertTrue(bootstrap_check_s8($temporary)['ok']);
    }

    // -------------------------------------------------------------------
    // Portail d'acceptation — partie navigateur
    // -------------------------------------------------------------------

    public function testThePositiveControlDemandsTheExactContent(): void
    {
        self::assertTrue(bootstrap_evaluate_control_probe(200, 'temoin', 'temoin'));
        self::assertFalse(bootstrap_evaluate_control_probe(200, 'autre chose', 'temoin'));
        self::assertFalse(bootstrap_evaluate_control_probe(404, 'temoin', 'temoin'));
        self::assertFalse(bootstrap_evaluate_control_probe(0, '', 'temoin'));
    }

    /**
     * Si Apache sert `public/index.php` en clair, tout le dépôt est lisible,
     * jeton compris. C'est la panne la plus grave que ce portail cherche.
     */
    public function testServingPhpSourceIsNeverMistakenForExecutingIt(): void
    {
        self::assertTrue(bootstrap_evaluate_php_execution_probe(200, '<!DOCTYPE html><html>…'));
        self::assertFalse(bootstrap_evaluate_php_execution_probe(200, "<?php\n\ndeclare(strict_types=1);"));
        self::assertFalse(bootstrap_evaluate_php_execution_probe(200, 'declare(strict_types=1);'));
        self::assertFalse(bootstrap_evaluate_php_execution_probe(500, '<!DOCTYPE html>'));
        self::assertFalse(bootstrap_evaluate_php_execution_probe(0, ''));
    }

    /**
     * Jamais le seul code de statut : un hébergeur qui renvoie sa propre page
     * en 200 pour tout ce qu'il refuse existe, et le lire comme un succès
     * transformerait une exposition en réussite.
     */
    public function testProtectionIsJudgedOnTheBodyAndNotOnTheStatusAlone(): void
    {
        self::assertTrue(bootstrap_evaluate_protection_probe(403, '', 'secret'));
        self::assertTrue(bootstrap_evaluate_protection_probe(404, 'Page introuvable', 'secret'));
        self::assertTrue(bootstrap_evaluate_protection_probe(200, 'Page introuvable', 'secret'));
        self::assertFalse(bootstrap_evaluate_protection_probe(200, 'secret', 'secret'));
        self::assertFalse(bootstrap_evaluate_protection_probe(0, '', 'secret'));
        self::assertFalse(bootstrap_evaluate_protection_probe(500, '', 'secret'));
    }

    public function testADirectoryListingIsRecognised(): void
    {
        self::assertTrue(bootstrap_evaluate_no_directory_listing(403, ''));
        self::assertTrue(bootstrap_evaluate_no_directory_listing(200, '<html>Rien à voir</html>'));
        self::assertFalse(bootstrap_evaluate_no_directory_listing(200, '<h1>Index of /storage</h1>'));
        self::assertFalse(bootstrap_evaluate_no_directory_listing(0, ''));
    }

    public function testTheFunctionalProbeLooksForTheWizardMarker(): void
    {
        self::assertTrue(bootstrap_evaluate_functional_probe(200, '<form data-testid="install-form">', BOOTSTRAP_WIZARD_MARKER));
        self::assertFalse(bootstrap_evaluate_functional_probe(200, '<h1>Bienvenue chez votre hébergeur</h1>', BOOTSTRAP_WIZARD_MARKER));
        self::assertFalse(bootstrap_evaluate_functional_probe(403, '<form data-testid="install-form">', BOOTSTRAP_WIZARD_MARKER));
    }

    /**
     * Le témoin en échec invalide tout le reste : si un fichier volontairement
     * public n'est pas servi, « inaccessible » ne prouve plus rien. Et
     * l'installation est annulée, pas seulement suspendue.
     */
    public function testAFailedPositiveControlInvalidatesEveryOtherCheckAndRollsBack(): void
    {
        [$docRoot, $state] = $this->installedStateWithProbes();

        $result = bootstrap_evaluate_gate_report($docRoot, $state, [
            ['id' => 'B1', 'status' => 404, 'body' => ''],
        ]);

        self::assertFalse($result['gate_passed']);
        self::assertSame('B1', $result['gate_aborted_at']);
        foreach ($result['b_checks'] as $check) {
            self::assertFalse($check['ok']);
        }
        self::assertDirectoryDoesNotExist($docRoot . '/src');
        self::assertFileDoesNotExist($docRoot . '/token.php');
    }

    public function testPhpServedAsSourceAbortsImmediatelyAndRemovesTheTree(): void
    {
        [$docRoot, $state] = $this->installedStateWithProbes();

        $result = bootstrap_evaluate_gate_report(
            $docRoot,
            $state,
            $this->probeResults($state, ['B2' => ['status' => 200, 'body' => "<?php declare(strict_types=1);"]])
        );

        self::assertFalse($result['gate_passed']);
        self::assertSame('B2', $result['gate_aborted_at']);
        self::assertDirectoryDoesNotExist($docRoot . '/src');
        self::assertDirectoryDoesNotExist($docRoot . '/storage');
        self::assertFileDoesNotExist($docRoot . '/VERSION');
        self::assertFileDoesNotExist($docRoot . '/token.php');
    }

    /**
     * Une seule exposition suffit à tout annuler. Une installation à moitié
     * faite qu'un contrôle vient de déclarer dangereuse ne doit pas rester
     * configurable.
     */
    public function testASingleExposedResourceCancelsTheWholeInstallation(): void
    {
        [$docRoot, $state] = $this->installedStateWithProbes();
        $exposed = bootstrap_probe_expected($state, 'B4');

        $result = bootstrap_evaluate_gate_report(
            $docRoot,
            $state,
            $this->probeResults($state, ['B4' => ['status' => 200, 'body' => $exposed]])
        );

        self::assertFalse($result['gate_passed']);
        $failed = array_values(array_filter($result['b_checks'], static fn (array $c): bool => !$c['ok']));
        self::assertCount(1, $failed);
        self::assertSame('B4', $failed[0]['id']);
        self::assertDirectoryDoesNotExist($docRoot . '/config');
    }

    public function testAnUnreachableWizardCancelsTheInstallation(): void
    {
        [$docRoot, $state] = $this->installedStateWithProbes();

        $result = bootstrap_evaluate_gate_report(
            $docRoot,
            $state,
            $this->probeResults($state, ['F1' => ['status' => 200, 'body' => "<h1>Page d'accueil de l'hébergeur</h1>"]])
        );

        self::assertFalse($result['gate_passed']);
        self::assertFalse($result['f_checks'][0]['ok']);
        self::assertDirectoryDoesNotExist($docRoot . '/src');
    }

    /**
     * Le cas nominal : tout passe, les fichiers témoins disparaissent, le
     * jeton reste, et le rapport est déposé dans l'installation — la seule
     * trace de ce qui a été réellement mesuré sur cet hébergement.
     */
    public function testASuccessfulGateClearsItsProbesKeepsTheTokenAndLeavesAReport(): void
    {
        [$docRoot, $state] = $this->installedStateWithProbes();

        $result = bootstrap_evaluate_gate_report($docRoot, $state, $this->probeResults($state));

        self::assertTrue($result['gate_passed']);
        self::assertTrue($result['done_gate']);
        self::assertFalse($result['awaiting_gate_report']);
        self::assertFileExists($docRoot . '/token.php');
        self::assertFileExists($docRoot . '/storage/logs/install-report.json');

        foreach ($state['probes'] as $probe) {
            if ($probe['file'] !== null) {
                self::assertFileDoesNotExist((string) $probe['file'], (string) $probe['id']);
            }
        }
    }

    /**
     * Un résultat manquant n'est pas un succès silencieux : un navigateur qui
     * ne rapporte rien pour une sonde n'a rien prouvé.
     */
    public function testAMissingProbeResultCountsAsAFailure(): void
    {
        [$docRoot, $state] = $this->installedStateWithProbes();
        $results = array_values(array_filter(
            $this->probeResults($state),
            static fn (array $result): bool => $result['id'] !== 'B6'
        ));

        $verdict = bootstrap_evaluate_gate_report($docRoot, $state, $results);

        self::assertFalse($verdict['gate_passed']);
    }

    public function testTheGateReportSurvivesGarbageFromTheBrowser(): void
    {
        [$docRoot, $state] = $this->installedStateWithProbes();

        $verdict = bootstrap_evaluate_gate_report($docRoot, $state, ['pas un tableau', ['sans identifiant' => 1]]);

        self::assertFalse($verdict['gate_passed']);
        self::assertSame('B1', $verdict['gate_aborted_at']);
    }

    /**
     * Le jeton ne quitte jamais le serveur autrement que par l'adresse de
     * l'assistant, et seulement après un portail réussi.
     */
    public function testThePublicStateNeverCarriesTheRawTokenOrAbsolutePaths(): void
    {
        $public = bootstrap_public_state([
            'token' => 'a1b2',
            'doc_root' => '/home/quelquun/www',
            'temp_dir' => '/home/quelquun/www/.tmp-1',
            'artifact_path' => '/home/quelquun/www/.tmp-1/artifact.zip',
            'source_root' => '/home/quelquun/www/.tmp-1/extracted',
            'install_target' => '/home/quelquun/www',
            'installed_entries' => ['src'],
            'label' => 'Contrôles',
            'percent' => 50,
            'probes' => [
                ['id' => 'B1', 'kind' => 'control', 'url' => '/c.txt', 'expected' => 'secret', 'file' => '/abs/c.txt'],
            ],
        ]);

        self::assertArrayNotHasKey('token', $public);
        self::assertArrayNotHasKey('doc_root', $public);
        self::assertArrayNotHasKey('temp_dir', $public);
        self::assertArrayNotHasKey('install_target', $public);
        self::assertArrayNotHasKey('installed_entries', $public);
        self::assertSame('Contrôles', $public['label']);

        // Les sondes partent sans leur contenu attendu ni leur chemin disque :
        // le navigateur n'a besoin que de l'URL à demander.
        self::assertSame([['id' => 'B1', 'kind' => 'control', 'url' => '/c.txt']], $public['probes']);
    }

    public function testErrorMessagesNeverPublishTheHostingLayout(): void
    {
        $sanitised = bootstrap_sanitize_error_for_client(
            'Impossible de créer /home/quelquun/www/storage/logs.',
            '/home/quelquun/www'
        );

        self::assertStringNotContainsString('/home/quelquun', $sanitised);
        self::assertStringContainsString('/storage/logs', $sanitised);
    }

    // -------------------------------------------------------------------
    // État et verrou
    // -------------------------------------------------------------------

    /**
     * L'état est du PHP valide dont les données vivent dans un commentaire :
     * posé à la racine d'un hébergement, il ne divulgue rien même servi.
     */
    public function testTheStateFileIsInertPhpAndSurvivesARoundTrip(): void
    {
        $path = $this->temporaryDirectory . '/.bootstrap-state.php';
        $state = ['version' => '1.2.3', 'installed_entries' => ['src', 'public'], 'percent' => 50];

        bootstrap_write_state($path, $state);

        $raw = (string) file_get_contents($path);
        self::assertStringStartsWith("<?php\n", $raw);
        exec('php -l ' . escapeshellarg($path), $output, $code);
        self::assertSame(0, $code, implode("\n", $output));

        self::assertSame($state, bootstrap_read_state($path));
    }

    public function testAnAbsentOrCorruptStateReadsAsEmpty(): void
    {
        self::assertSame([], bootstrap_read_state($this->temporaryDirectory . '/absent.php'));

        $path = $this->temporaryDirectory . '/corrompu.php';
        file_put_contents($path, "<?php\n/*\nceci n'est pas du JSON\n*/\n");
        self::assertSame([], bootstrap_read_state($path));
    }

    /**
     * Un verrou frais bloque une seconde tentative ; un verrou périmé ne
     * bloque plus rien — sans quoi une tentative interrompue condamnerait
     * l'installation jusqu'à une intervention FTP.
     */
    public function testAFreshLockBlocksAndAStaleOneDoesNot(): void
    {
        $lock = $this->temporaryDirectory . '/.bootstrap.lock';

        self::assertTrue(bootstrap_acquire_lock($lock));
        self::assertFalse(bootstrap_acquire_lock($lock));

        touch($lock, time() - BOOTSTRAP_LOCK_STALE_SECONDS - 1);
        self::assertTrue(bootstrap_acquire_lock($lock));

        bootstrap_release_lock($lock);
        self::assertFileDoesNotExist($lock);
    }

    // -------------------------------------------------------------------
    // Étapes
    // -------------------------------------------------------------------

    /**
     * L'étape de nettoyage supprime l'état **et** l'installeur. Quand la
     * suppression échoue, elle le dit plutôt que de laisser croire le
     * contraire : le client n'auto-redirige alors pas.
     */
    public function testTheCleanupStepReportsAFailedSelfDeletionRatherThanHidingIt(): void
    {
        $stateFile = $this->temporaryDirectory . '/' . BOOTSTRAP_STATE_FILE;
        file_put_contents($stateFile, "<?php\n/*\n{}\n*/\n");

        $deleted = bootstrap_step_cleanup($this->temporaryDirectory, [], static fn (): bool => true);
        self::assertTrue($deleted['self_deleted']);
        self::assertTrue($deleted['done']);
        self::assertArrayNotHasKey('cleanup_warning', $deleted);
        self::assertFileDoesNotExist($stateFile);

        $kept = bootstrap_step_cleanup($this->temporaryDirectory, [], static fn (): bool => false);
        self::assertFalse($kept['self_deleted']);
        self::assertStringContainsString('FTP', $kept['cleanup_warning']);
    }

    public function testTheTokenStepRefusesToRunBeforeTheGateHasPassed(): void
    {
        $this->expectException(RuntimeException::class);

        bootstrap_step_token($this->temporaryDirectory, ['gate_passed' => false]);
    }

    /**
     * Le jeton disparu après le portail n'enferme pas l'opérateur dehors : le
     * contenu exact à déposer par FTP lui est rendu.
     */
    public function testALostTokenFileYieldsTheContentToRecreateByFtp(): void
    {
        $token = bootstrap_generate_token();

        $state = bootstrap_step_token($this->temporaryDirectory, ['gate_passed' => true, 'token' => $token]);

        self::assertFalse($state['token_written']);
        self::assertStringContainsString($token, $state['token_manual_content']);
        self::assertStringContainsString($token, $state['wizard_url']);
    }

    public function testTheTokenStepConfirmsAFileThatIsActuallyThere(): void
    {
        $token = bootstrap_generate_token();
        file_put_contents(
            $this->temporaryDirectory . '/' . BOOTSTRAP_TOKEN_FILE,
            bootstrap_token_file_content($token)
        );

        $state = bootstrap_step_token($this->temporaryDirectory, ['gate_passed' => true, 'token' => $token]);

        self::assertTrue($state['token_written']);
        self::assertArrayNotHasKey('token_write_warning', $state);
        self::assertSame(
            '/fr/install?' . BOOTSTRAP_TOKEN_PARAMETER . '=' . $token,
            $state['wizard_url']
        );
    }

    // -------------------------------------------------------------------
    // Cohérence avec l'application
    // -------------------------------------------------------------------

    /**
     * Ces trois valeurs sont dupliquées entre l'installeur et l'application
     * parce que l'installeur ne peut charger aucune classe du projet. Une
     * dérive silencieuse rendrait le jeton inutilisable au moment précis où
     * il compte.
     */
    public function testTheInstallerAndTheApplicationAgreeOnTheTokenContract(): void
    {
        self::assertSame(\SecondStay\Installer\InstallToken::REQUEST_PARAMETER, BOOTSTRAP_TOKEN_PARAMETER);
        self::assertSame(\SecondStay\Installer\InstallToken::MARKER, BOOTSTRAP_TOKEN_MARKER);
        self::assertSame(\SecondStay\Installer\InstallToken::FILE_NAME, BOOTSTRAP_TOKEN_FILE);
    }

    /**
     * Le marqueur cherché par le contrôle fonctionnel doit exister dans le
     * gabarit de l'assistant, sans quoi le portail échouerait sur une
     * installation parfaitement saine.
     */
    public function testTheWizardMarkerExistsInTheTemplate(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/templates/install/install.html.twig');

        self::assertStringContainsString(BOOTSTRAP_WIZARD_MARKER, $template);
    }

    /**
     * La liste des sous-dossiers de stockage est une copie de celle de
     * `Paths` : ce test est ce qui empêche les deux de diverger.
     */
    public function testTheStorageSubdirectoriesMatchThePathsClass(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Core/Paths.php');
        self::assertSame(
            1,
            preg_match('/ensureStorageDirectories\(\).*?foreach \(\[(.*?)\] as \$directory\)/s', $source, $matches)
        );

        $group = $matches[1] ?? '';
        self::assertNotSame('', $group);

        preg_match_all("/'([^']*)'/", $group, $found);
        $expected = array_values(array_filter($found[1], static fn (string $entry): bool => $entry !== ''));

        self::assertSame($expected, BOOTSTRAP_STORAGE_SUBDIRS);
    }

    /**
     * Chaque entrée exigée par l'installeur doit être une entrée que la
     * politique de release garantit : exiger un fichier que l'artefact ne
     * livre pas rendrait toute installation impossible.
     */
    public function testEveryRequiredArtefactEntryIsGuaranteedByTheReleasePolicy(): void
    {
        foreach (BOOTSTRAP_REQUIRED_ARTIFACT_ENTRIES as $entry) {
            self::assertContains(
                $entry,
                \SecondStay\Release\ReleaseArtifactPolicy::REQUIRED_ENTRIES,
                $entry . " n'est pas garanti par la politique de release."
            );
        }
    }

    // -------------------------------------------------------------------

    /**
     * Écrit une arborescence minimale ayant la forme d'un artefact installé.
     */
    private function writeArtefact(string $root): void
    {
        foreach ([
            '.htaccess' => "Require all denied\n",
            'public/index.php' => "<?php\n",
            'public/.htaccess' => "Options -Indexes\n",
            'src/Core/Kernel.php' => "<?php\n",
            'config/app.php' => "<?php return [];\n",
            'templates/layout/base.html.twig' => "{# base #}\n",
            'translations/fr/common.php' => "<?php return [];\n",
            'vendor/autoload.php' => "<?php\n",
        ] as $relative => $content) {
            $path = $root . '/' . $relative;
            $directory = dirname($path);
            if (!is_dir($directory)) {
                mkdir($directory, 0o700, true);
            }
            file_put_contents($path, $content);
        }
    }

    /**
     * Une installation complète, jeton écrit et sondes posées — l'état exact
     * dans lequel `bootstrap_step_gate_prepare()` laisse les choses.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function installedStateWithProbes(): array
    {
        $docRoot = $this->temporaryDirectory . '/site';
        $this->writeArtefact($docRoot);
        file_put_contents($docRoot . '/VERSION', "1.2.3\n");
        bootstrap_create_storage_dirs($docRoot);

        $token = bootstrap_generate_token();
        file_put_contents($docRoot . '/token.php', bootstrap_token_file_content($token));

        $state = [
            'install_target' => $docRoot,
            'installed_entries' => ['.htaccess', 'public', 'src', 'config', 'templates', 'translations', 'vendor'],
            'token' => $token,
        ];
        $state['probes'] = bootstrap_write_gate_probes($docRoot, $state);
        $state['awaiting_gate_report'] = true;

        return [$docRoot, $state];
    }

    /**
     * Les résultats qu'un hébergement correctement configuré rapporterait,
     * avec la possibilité d'en remplacer un pour décrire une panne.
     *
     * @param array<string, mixed> $state
     * @param array<string, array{status: int, body: string}> $overrides
     *
     * @return list<array{id: string, status: int, body: string}>
     */
    private function probeResults(array $state, array $overrides = []): array
    {
        $results = [];
        foreach ($state['probes'] as $probe) {
            $id = (string) $probe['id'];

            if (isset($overrides[$id])) {
                $results[] = ['id' => $id, 'status' => $overrides[$id]['status'], 'body' => $overrides[$id]['body']];
                continue;
            }

            $results[] = match ((string) $probe['kind']) {
                'control' => ['id' => $id, 'status' => 200, 'body' => (string) $probe['expected']],
                'php_exec' => ['id' => $id, 'status' => 200, 'body' => '<!DOCTYPE html><html lang="fr">'],
                'functional' => ['id' => $id, 'status' => 200, 'body' => '<form ' . BOOTSTRAP_WIZARD_MARKER . '>'],
                default => ['id' => $id, 'status' => 403, 'body' => ''],
            };
        }

        return $results;
    }
}
