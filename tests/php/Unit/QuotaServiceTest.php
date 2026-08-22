<?php

declare(strict_types=1);

namespace SecondStay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecondStay\Core\Paths;
use SecondStay\Quota\QuotaService;
use SecondStay\Security\Encryptor;
use SecondStay\Settings\SettingRegistry;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InMemorySettingsRepository;

/**
 * Quotas de stockage (ROADMAP.md itération 14).
 *
 * Un disque plein casse tout, y compris la sauvegarde qui aurait permis de
 * s'en sortir. Le produit refuse donc d'écrire **avant** la limite, et un
 * quota non réglé ne bloque rien.
 */
final class QuotaServiceTest extends TestCase
{
    private string $storage = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/ss-quota-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0o750, true)) {
            self::fail('Impossible de créer le stockage de test.');
        }

        $this->storage = $base;
    }

    protected function tearDown(): void
    {
        if ($this->storage === '' || !is_dir($this->storage)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    /**
     * @param array<string, string> $settings
     */
    private function service(array $settings = []): QuotaService
    {
        $store = new SettingsService(
            new SettingRegistry(),
            new InMemorySettingsRepository(),
            Encryptor::fromSingleKey(Encryptor::generateKey()),
        );
        if ($settings !== []) {
            $store->setMany($settings);
        }

        return new QuotaService(new Paths(dirname(__DIR__, 3), $this->storage), $store);
    }

    private function write(string $category, string $name, int $bytes): void
    {
        $directory = $this->storage . '/' . $category;
        if (!is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }

        file_put_contents($directory . '/' . $name, str_repeat('x', $bytes));
    }

    public function testAnAbsentDirectoryOccupiesNothing(): void
    {
        self::assertSame(0, $this->service()->bytesIn('media'));
    }

    public function testFilesAreCountedRecursively(): void
    {
        $this->write('media', 'a.bin', 1000);
        $this->write('media/thumbs', 'b.bin', 500);

        self::assertSame(1500, $this->service()->bytesIn('media'));
    }

    public function testWithoutAQuotaEverythingIsAllowed(): void
    {
        $this->write('documents', 'a.bin', 10_000);

        // Zéro veut dire « pas de limite » : c'est la configuration par
        // défaut, et elle ne doit rien empêcher.
        self::assertTrue($this->service()->allows('documents', 5_000_000));
    }

    public function testAWriteThatWouldExceedTheQuotaIsRefused(): void
    {
        $service = $this->service(['quota.documents_mb' => '1']);
        $this->write('documents', 'a.bin', 1024 * 1024 - 100);

        self::assertTrue($service->allows('documents', 100));
        self::assertFalse($service->allows('documents', 101));
    }

    public function testAnUnknownCategoryIsNeverBlocked(): void
    {
        self::assertTrue($this->service(['quota.media_mb' => '1'])->allows('unknown', 99_999_999));
    }

    public function testUsageReportsEveryCategory(): void
    {
        $usage = $this->service()->usage();

        self::assertSame(
            array_keys(QuotaService::CATEGORIES),
            array_map(static fn (array $entry): string => $entry['category'], $usage)
        );
    }

    public function testUsageWarnsBeforeItRefuses(): void
    {
        $this->write('media', 'a.bin', (int) (1024 * 1024 * 0.85));
        $usage = $this->service(['quota.media_mb' => '1'])->usage();
        $media = $usage[0];

        self::assertSame('media', $media['category']);
        self::assertTrue($media['warning']);
        self::assertFalse($media['exceeded']);
        self::assertGreaterThanOrEqual(QuotaService::WARNING_PERCENT, $media['percent']);
    }

    public function testAFullCategoryIsReportedAsExceededNotAsAWarning(): void
    {
        $this->write('media', 'a.bin', 1024 * 1024);
        $media = $this->service(['quota.media_mb' => '1'])->usage()[0];

        self::assertTrue($media['exceeded']);
        self::assertFalse($media['warning']);
        self::assertSame(['media'], $this->service(['quota.media_mb' => '1'])->exceeded());
    }

    public function testWithoutAQuotaNothingIsExceeded(): void
    {
        $this->write('media', 'a.bin', 5_000_000);

        self::assertSame([], $this->service()->exceeded());
        self::assertSame(0.0, $this->service()->usage()[0]['percent']);
    }

    public function testTheTotalAddsEveryCategory(): void
    {
        $this->write('media', 'a.bin', 1000);
        $this->write('documents', 'b.bin', 2000);
        $this->write('backups', 'c.bin', 4000);
        $this->write('mail-attachments', 'd.bin', 8000);

        self::assertSame(15_000, $this->service()->totalBytes());
    }
}
