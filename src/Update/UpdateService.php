<?php

declare(strict_types=1);

namespace SecondStay\Update;

use RuntimeException;
use SecondStay\Audit\AuditTrail;
use SecondStay\Backup\BackupService;
use SecondStay\Core\Paths;
use SecondStay\Database\Database;
use SecondStay\Database\Migrator;
use SecondStay\Logging\Logger;
use SecondStay\Maintenance\MaintenanceMode;
use SecondStay\Release\ReleaseArtifactInspector;
use Throwable;
use ZipArchive;

/**
 * Mise à jour intégrée (RELEASE.md §11).
 *
 * Flux : check → download → empreinte → validate → extraction préparée →
 * maintenance → backup → permutation → migrations → VERSION → health check →
 * rollback (fichiers **et** base) si échec.
 *
 * Trois choix tiennent ce flux debout sur un hébergement mutualisé :
 *
 *  - **l'archive est extraite à côté, avant la maintenance.** Le site reste
 *    servi pendant la partie lente, et une extraction interrompue ne touche
 *    à rien de ce qui est en ligne ;
 *  - **les répertoires gérés sont permutés par renommage**, pas écrasés
 *    fichier par fichier. Un fichier supprimé dans la nouvelle version
 *    disparaît donc vraiment, et l'ancienne version reste intacte, à un
 *    renommage près, jusqu'à la fin ;
 *  - **un journal décrit l'opération en cours.** Un processus tué par
 *    l'hébergeur (limite de temps, redémarrage) ne passe par aucun `finally` ;
 *    le journal, lui, reste, et la requête suivante remet le site dans l'état
 *    d'avant (`recoverInterrupted()`).
 *
 * Les données persistantes (`storage/`, `config/local.php`) ne sont jamais
 * remplacées.
 */
final class UpdateService
{
    /** @var list<string> chemins permutés par la mise à jour */
    public const MANAGED_PATHS = ['public', 'src', 'templates', 'translations', 'migrations', 'vendor', '.htaccess'];

    /**
     * Fichiers remplacés un par un : `config/` contient `local.php`, qui ne
     * se permute pas avec le reste.
     *
     * @var list<string>
     */
    private const MANAGED_FILE_ROOTS = ['config', 'LICENSE'];

    /** Validité du résultat d'une vérification, en secondes. */
    public const CHECK_TTL_SECONDS = 6 * 3600;

    /**
     * Âge au-delà duquel un journal est tenu pour orphelin quand le système
     * de fichiers ne sait pas verrouiller.
     */
    private const ORPHAN_JOURNAL_SECONDS = 3600;

    private const PHASE_SWAPPING = 'swapping';
    private const PHASE_MIGRATING = 'migrating';

    /** @var resource|null */
    private $lockHandle = null;

    private bool $lockIsReal = false;

    /** @var (callable(): int)|null */
    private $clock;

    /**
     * @param (callable(): int)|null $clock horloge, injectable pour les tests
     */
    public function __construct(
        private readonly ReleaseProvider $provider,
        private readonly Paths $paths,
        private readonly Database $database,
        private readonly BackupService $backups,
        private readonly MaintenanceMode $maintenance,
        private readonly Logger $logger,
        private readonly ?AuditTrail $audit = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    public function currentVersion(): string
    {
        $file = $this->paths->root('VERSION');
        clearstatcache(true, $file);
        if (!is_file($file)) {
            return '0.0.0';
        }

        $content = file_get_contents($file);

        return $content === false ? '0.0.0' : trim($content);
    }

    /**
     * Vérification en direct auprès de la source des releases.
     *
     * @return array{available: bool, current: string, latest: ?ReleaseInfo}
     */
    public function check(bool $allowPrerelease = false): array
    {
        $current = $this->currentVersion();
        $latest = $this->provider->latest($allowPrerelease);

        return [
            'available' => $latest !== null && version_compare($latest->version, $current, '>'),
            'current' => $current,
            'latest' => $latest,
        ];
    }

    /**
     * Vérifie en direct et mémorise le résultat.
     *
     * @return array{available: bool, current: string, latest: ?ReleaseInfo, checked_at: int}
     */
    public function refresh(bool $allowPrerelease = false): array
    {
        $state = $this->check($allowPrerelease);
        $checkedAt = $this->now();

        $this->writeJson($this->updateFile('last-check.json'), [
            'checked_at' => $checkedAt,
            'channel' => $allowPrerelease ? 'prerelease' : 'stable',
            'current' => $state['current'],
            'latest' => $state['latest']?->toStorage(),
        ]);

        return $state + ['checked_at' => $checkedAt];
    }

    /**
     * Dernier résultat mémorisé, s'il est encore valable : même canal, même
     * version installée, moins de `CHECK_TTL_SECONDS`.
     *
     * Afficher la page des mises à jour ne doit pas coûter un appel à l'API
     * GitHub — limitée à soixante appels par heure et par adresse sans
     * authentification, adresse que partagent souvent des centaines de sites
     * sur un mutualisé.
     *
     * @return array{available: bool, current: string, latest: ?ReleaseInfo, checked_at: int}|null
     */
    public function cachedState(bool $allowPrerelease = false): ?array
    {
        $data = $this->readJson($this->updateFile('last-check.json'));
        if ($data === null) {
            return null;
        }

        $checkedAt = is_int($data['checked_at'] ?? null) ? $data['checked_at'] : 0;
        $current = $this->currentVersion();
        if (($data['channel'] ?? null) !== ($allowPrerelease ? 'prerelease' : 'stable')
            || ($data['current'] ?? null) !== $current
            || $this->now() - $checkedAt > self::CHECK_TTL_SECONDS
        ) {
            return null;
        }

        $latest = is_array($data['latest'] ?? null) ? ReleaseInfo::fromStorage($data['latest']) : null;

        return [
            'available' => $latest !== null && version_compare($latest->version, $current, '>'),
            'current' => $current,
            'latest' => $latest,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * Résultat mémorisé s'il est valable, sinon vérification en direct.
     *
     * @return array{available: bool, current: string, latest: ?ReleaseInfo, checked_at: int}
     */
    public function state(bool $allowPrerelease = false): array
    {
        return $this->cachedState($allowPrerelease) ?? $this->refresh($allowPrerelease);
    }

    /**
     * Valide l'archive téléchargée avant toute installation.
     *
     * @return list<string> problèmes bloquants
     */
    public function validateArtifact(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            return ['update.error.asset_missing'];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['update.error.asset_unreadable'];
        }

        $version = $zip->getFromName('VERSION');
        $zip->close();

        if ($version === false || preg_match('/^\d+\.\d+\.\d+$/', trim($version)) !== 1) {
            return ['update.error.asset_no_version'];
        }

        $problems = (new ReleaseArtifactInspector())->inspect($zipPath);

        return array_map(
            static fn (string $problem): string => 'update.error.artifact:' . $problem,
            $problems
        );
    }

    /**
     * Compare l'archive téléchargée à l'empreinte annoncée par la source.
     *
     * L'empreinte arrive par l'API, l'archive par le service de
     * téléchargement : deux canaux distincts. Une archive tronquée, corrompue
     * en route ou substituée ne correspond plus. Sans empreinte annoncée,
     * rien ne permet de le savoir : la release est refusée plutôt que
     * supposée intègre — c'est la règle de l'installeur autonome.
     *
     * @return list<string> problèmes bloquants
     */
    public function verifyDigest(ReleaseInfo $release, string $zipPath): array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $release->digest) !== 1) {
            return ['update.error.digest_missing'];
        }

        $actual = is_file($zipPath) ? hash_file('sha256', $zipPath) : false;
        if ($actual === false || !hash_equals($release->digest, $actual)) {
            return ['update.error.digest_mismatch'];
        }

        return [];
    }

    /**
     * Installe une release.
     *
     * @return array{installed: bool, version: string, migrations: list<string>, backup: ?string, rolled_back: bool}
     */
    public function install(ReleaseInfo $release, ?string $actorLabel = null, ?int $actorId = null): array
    {
        // Une mise à jour ne doit pas s'arrêter à mi-chemin parce que la
        // limite de temps par défaut est de trente secondes, ou parce que
        // l'administrateur a fermé son onglet. Ce n'est qu'un effort : un
        // hébergeur peut l'interdire, et c'est pour ce cas que le journal
        // existe.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        ignore_user_abort(true);

        if (!$this->acquireLock()) {
            throw new RuntimeException('Une mise à jour est déjà en cours.');
        }

        try {
            // Une mise à jour précédente tuée en route est d'abord défaite :
            // on ne construit pas une nouvelle version sur un état inconnu.
            $this->recoverWhileLocked();

            return $this->installLocked($release, $actorLabel, $actorId);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Défait une mise à jour interrompue sans être passée par son propre
     * retour arrière : processus tué, hébergement redémarré.
     *
     * Ne fait rien si aucune mise à jour n'a été interrompue, ni si une mise
     * à jour est en train de s'exécuter dans un autre processus.
     *
     * @return bool vrai si une reprise a eu lieu
     */
    public function recoverInterrupted(): bool
    {
        if (!is_file($this->journalPath())) {
            return false;
        }

        if (!$this->acquireLock()) {
            return false;
        }

        try {
            return $this->recoverWhileLocked();
        } finally {
            $this->releaseLock();
        }
    }

    public function hasInterruptedUpdate(): bool
    {
        return is_file($this->journalPath());
    }

    public function healthCheck(): bool
    {
        try {
            if (!$this->database->isReachable()) {
                return false;
            }
            if (!is_file($this->paths->public('index.php'))) {
                return false;
            }
            if (!is_file($this->paths->root('vendor/autoload.php'))) {
                return false;
            }
            $migrator = new Migrator($this->database, $this->paths->migrations());

            return $migrator->pending() === [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{installed: bool, version: string, migrations: list<string>, backup: ?string, rolled_back: bool}
     */
    private function installLocked(ReleaseInfo $release, ?string $actorLabel, ?int $actorId): array
    {
        $previousVersion = $this->currentVersion();
        $workDirectory = $this->paths->storage('temp/update-' . bin2hex(random_bytes(6)));
        $this->makeDirectory($workDirectory);

        $assetPath = $workDirectory . '/' . basename($release->assetName);
        $stagingDirectory = $workDirectory . '/staging';
        $rollbackDirectory = $workDirectory . '/rollback';

        try {
            // Tout ce qui est lent ou refusable se fait site ouvert.
            $this->provider->download($release, $assetPath);

            $problems = array_merge(
                $this->verifyDigest($release, $assetPath),
                $this->validateArtifact($assetPath)
            );
            if ($problems !== []) {
                throw new RuntimeException('Artefact invalide : ' . implode(', ', $problems));
            }

            $this->extractArtifact($assetPath, $stagingDirectory);
            @unlink($assetPath);

            // Les classes dont le retour arrière a besoin sont chargées depuis
            // l'ancienne version, avant que `src/` ne change sous nos pieds.
            class_exists(Migrator::class);
            class_exists(BackupService::class);

            $maintenanceWasActive = $this->maintenance->isActive();
            if (!$maintenanceWasActive) {
                $this->maintenance->enable('maintenance.reason.update');
            }

            try {
                // La sauvegarde est prise site fermé : rien ne s'écrit entre
                // elle et une éventuelle restauration.
                $backup = $this->backups->create(true, $actorLabel, $actorId);
                $backupPath = $backup['path'];

                return $this->applyStaged(
                    $release,
                    $previousVersion,
                    $workDirectory,
                    $stagingDirectory,
                    $rollbackDirectory,
                    $backupPath,
                    $maintenanceWasActive,
                    $actorLabel,
                    $actorId,
                );
            } finally {
                // Un journal encore présent signifie que le retour arrière
                // lui-même a échoué : le site reste fermé, et la copie de
                // l'ancienne version reste là pour la reprise.
                if (!$maintenanceWasActive && !$this->hasInterruptedUpdate()) {
                    $this->maintenance->disable();
                }
            }
        } finally {
            if (!$this->hasInterruptedUpdate()) {
                $this->removePath($workDirectory);
            }
        }
    }

    /**
     * @return array{installed: bool, version: string, migrations: list<string>, backup: ?string, rolled_back: bool}
     */
    private function applyStaged(
        ReleaseInfo $release,
        string $previousVersion,
        string $workDirectory,
        string $stagingDirectory,
        string $rollbackDirectory,
        string $backupPath,
        bool $maintenanceWasActive,
        ?string $actorLabel,
        ?int $actorId,
    ): array {
        $journal = [
            'from' => $previousVersion,
            'to' => $release->version,
            'work_directory' => $workDirectory,
            'rollback_directory' => $rollbackDirectory,
            'backup' => $backupPath,
            'tables_before' => $this->database->tables(),
            'maintenance_was_active' => $maintenanceWasActive,
            'phase' => self::PHASE_SWAPPING,
            'started_at' => $this->now(),
            'actor' => $actorLabel ?? 'system',
        ];
        $this->writeJson($this->journalPath(), $journal);

        try {
            $this->swapIn($stagingDirectory, $rollbackDirectory);

            $journal['phase'] = self::PHASE_MIGRATING;
            $this->writeJson($this->journalPath(), $journal);

            $migrator = new Migrator($this->database, $this->paths->migrations());
            $applied = $migrator->migrate();

            $this->writeVersion($release->version);

            if (!$this->healthCheck()) {
                throw new RuntimeException('Contrôle de santé post-installation en échec.');
            }
        } catch (Throwable $throwable) {
            try {
                $this->rollBack($journal);
            } catch (Throwable $rollbackFailure) {
                // Le journal reste en place : `recoverInterrupted()` reprendra
                // là où ce retour arrière s'est arrêté.
                $this->logger->critical('update', 'Retour arrière en échec', [
                    'reason' => $rollbackFailure->getMessage(),
                ]);

                throw new RuntimeException('Mise à jour et retour arrière en échec.', 0, $throwable);
            }
            $this->deleteJournal();

            $this->logger->error('update', 'Mise à jour annulée', [
                'from' => $previousVersion,
                'to' => $release->version,
                'reason' => $throwable->getMessage(),
            ]);
            $this->audit?->record(
                'update.rolled_back',
                'update',
                $release->version,
                ['version' => $previousVersion],
                ['reason' => $throwable->getMessage()],
                $actorId,
                $actorLabel ?? 'system',
            );

            return [
                'installed' => false,
                'version' => $previousVersion,
                'migrations' => [],
                'backup' => $backupPath,
                'rolled_back' => true,
            ];
        }

        $this->deleteJournal();
        $this->resetOpcache();

        $this->audit?->record(
            'update.installed',
            'update',
            $release->version,
            ['version' => $previousVersion],
            ['version' => $release->version, 'migrations' => count($applied)],
            $actorId,
            $actorLabel ?? 'system',
        );
        $this->logger->info('update', 'Mise à jour installée', [
            'from' => $previousVersion,
            'to' => $release->version,
        ]);

        return [
            'installed' => true,
            'version' => $release->version,
            'migrations' => $applied,
            'backup' => $backupPath,
            'rolled_back' => false,
        ];
    }

    /**
     * Met en place la version préparée. Chaque chemin remplacé part dans
     * `$rollbackDirectory` **avant** que son remplaçant n'arrive : à tout
     * instant, l'ancienne version de chaque chemin est quelque part.
     */
    private function swapIn(string $staging, string $rollback): void
    {
        $this->makeDirectory($rollback);

        foreach (self::MANAGED_PATHS as $relative) {
            $staged = $staging . '/' . $relative;
            if (!file_exists($staged)) {
                // L'archive ne remplace pas ce chemin : il reste en place.
                continue;
            }

            $live = $this->paths->root($relative);
            if (file_exists($live) || is_link($live)) {
                $this->move($live, $rollback . '/' . $relative);
            } else {
                $this->appendLine($rollback . '/added-paths', $relative);
            }
            $this->move($staged, $live);
        }

        foreach ($this->stagedManagedFiles($staging) as $relative) {
            $live = $this->paths->root($relative);
            if (is_file($live)) {
                $this->copyPath($live, $rollback . '/files/' . $relative);
            } else {
                // Nouveau fichier : le retour arrière devra le retirer.
                $this->appendLine($rollback . '/added-files', $relative);
            }
            $this->copyPath($staging . '/' . $relative, $live);
        }
    }

    /**
     * @return list<string> fichiers de l'archive sous `config/` (hors
     *                      `local.php`) et à la racine
     */
    private function stagedManagedFiles(string $staging): array
    {
        $files = [];
        foreach (self::MANAGED_FILE_ROOTS as $root) {
            $absolute = $staging . '/' . $root;
            if (is_file($absolute)) {
                $files[] = $root;
                continue;
            }
            if (!is_dir($absolute)) {
                continue;
            }
            foreach ($this->filesIn($absolute) as $file) {
                $relative = $root . '/' . substr($file, strlen($absolute) + 1);
                if ($relative !== 'config/local.php') {
                    $files[] = $relative;
                }
            }
        }

        return $files;
    }

    /**
     * Remet l'installation dans l'état décrit par le journal : fichiers,
     * version, puis base de données si les migrations ont commencé.
     *
     * @param array<mixed> $journal
     */
    private function rollBack(array $journal): void
    {
        $rollback = is_string($journal['rollback_directory'] ?? null) ? $journal['rollback_directory'] : '';
        $from = is_string($journal['from'] ?? null) ? $journal['from'] : '';

        if ($rollback !== '' && is_dir($rollback)) {
            foreach (self::MANAGED_PATHS as $relative) {
                $saved = $rollback . '/' . $relative;
                if (!file_exists($saved)) {
                    continue;
                }
                $live = $this->paths->root($relative);
                $this->removePath($live);
                $this->move($saved, $live);
            }

            $addedPaths = @file($rollback . '/added-paths', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (is_array($addedPaths) ? $addedPaths : [] as $relative) {
                if (in_array($relative, self::MANAGED_PATHS, true)) {
                    $this->removePath($this->paths->root($relative));
                }
            }

            $savedFiles = $rollback . '/files';
            if (is_dir($savedFiles)) {
                foreach ($this->filesIn($savedFiles) as $file) {
                    $relative = substr($file, strlen($savedFiles) + 1);
                    $this->copyPath($file, $this->paths->root($relative));
                }
            }

            $added = @file($rollback . '/added-files', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (is_array($added) ? $added : [] as $relative) {
                if ($relative !== 'config/local.php' && !str_contains($relative, '..')) {
                    @unlink($this->paths->root($relative));
                }
            }
        }

        if (preg_match('/^\d+\.\d+\.\d+$/', $from) === 1) {
            $this->writeVersion($from);
        }

        if (($journal['phase'] ?? null) === self::PHASE_MIGRATING) {
            $this->restoreDatabase($journal);
        }

        $this->resetOpcache();
    }

    /**
     * Les migrations ont commencé : l'ancien code ne doit pas repartir sur le
     * nouveau schéma. La sauvegarde prise juste avant est restaurée, puis les
     * tables qu'elle ne connaissait pas — créées par les nouvelles migrations
     * — sont retirées, sans quoi la prochaine tentative échouerait sur
     * « table déjà existante ».
     *
     * @param array<mixed> $journal
     */
    private function restoreDatabase(array $journal): void
    {
        $backup = is_string($journal['backup'] ?? null) ? $journal['backup'] : '';
        if ($backup === '' || !is_file($backup)) {
            $this->logger->critical('update', 'Retour arrière de la base impossible : sauvegarde introuvable.');

            return;
        }

        $this->backups->restore($backup, 'update-rollback');

        $before = is_array($journal['tables_before'] ?? null) ? $journal['tables_before'] : [];
        $extra = array_diff($this->database->tables(), $before);
        if ($extra === []) {
            return;
        }

        $pdo = $this->database->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($extra as $table) {
                if (preg_match('/^[A-Za-z0-9_]+$/', $table) === 1) {
                    $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Suppose le verrou acquis.
     */
    private function recoverWhileLocked(): bool
    {
        $journal = $this->readJson($this->journalPath());
        if ($journal === null) {
            if (is_file($this->journalPath())) {
                // Journal illisible : on ne sait pas quoi défaire. Le laisser
                // bloquerait toute mise à jour future sans rien expliquer.
                $this->logger->critical('update', 'Journal de mise à jour illisible, écarté.');
                $this->deleteJournal();
            }

            return false;
        }

        if (!$this->lockIsReal) {
            // Sans verrou fiable, seul l'âge distingue un journal orphelin
            // d'une mise à jour en cours dans un autre processus.
            $started = is_int($journal['started_at'] ?? null) ? $journal['started_at'] : 0;
            if ($this->now() - $started < self::ORPHAN_JOURNAL_SECONDS) {
                return false;
            }
        }

        try {
            $this->rollBack($journal);
        } catch (Throwable $throwable) {
            // Le journal reste : la prochaine requête réessaiera.
            $this->logger->critical('update', 'Reprise de mise à jour en échec', [
                'reason' => $throwable->getMessage(),
            ]);

            return false;
        }

        $workDirectory = is_string($journal['work_directory'] ?? null) ? $journal['work_directory'] : '';
        if ($workDirectory !== '' && str_starts_with($workDirectory, $this->paths->storage('temp') . '/update-')) {
            $this->removePath($workDirectory);
        }

        if (($journal['maintenance_was_active'] ?? false) !== true) {
            $this->maintenance->disable();
        }

        $this->deleteJournal();

        $to = is_string($journal['to'] ?? null) ? $journal['to'] : '';
        $from = is_string($journal['from'] ?? null) ? $journal['from'] : '';
        $this->logger->warning('update', 'Mise à jour interrompue défaite', ['from' => $from, 'to' => $to]);
        $this->audit?->record(
            'update.recovered',
            'update',
            $to,
            ['version' => $from],
            ['phase' => $journal['phase'] ?? null],
            null,
            'system',
        );

        return true;
    }

    private function acquireLock(): bool
    {
        $path = $this->updateFile('update.lock');
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Verrou de mise à jour inaccessible.');
        }

        $wouldBlock = 0;
        if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            $this->lockHandle = $handle;
            $this->lockIsReal = true;

            return true;
        }

        if ($wouldBlock === 1) {
            fclose($handle);

            return false;
        }

        // Système de fichiers sans verrous : on avance, prudemment
        // (voir `recoverWhileLocked()`).
        $this->lockHandle = $handle;
        $this->lockIsReal = false;

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            if ($this->lockIsReal) {
                flock($this->lockHandle, LOCK_UN);
            }
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
        $this->lockIsReal = false;
    }

    private function journalPath(): string
    {
        return $this->updateFile('journal.json');
    }

    private function deleteJournal(): void
    {
        @unlink($this->journalPath());
    }

    private function updateFile(string $name): string
    {
        $directory = $this->paths->storage('update');
        $this->makeDirectory($directory);

        return $directory . '/' . $name;
    }

    private function writeVersion(string $version): void
    {
        if (file_put_contents($this->paths->root('VERSION'), $version . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Écriture de VERSION impossible.');
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $temporary = $path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Écriture impossible : ' . basename($path));
        }
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $path): ?array
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function appendLine(string $path, string $line): void
    {
        if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Écriture impossible : ' . basename($path));
        }
    }

    private function extractArtifact(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Archive de mise à jour illisible.');
        }

        $this->makeDirectory($destination);

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $relative = $this->safeRelative($name);
                if ($relative === null) {
                    continue;
                }

                $target = $destination . '/' . $relative;
                $this->makeDirectory(dirname($target));

                $contents = $zip->getFromIndex($index);
                if ($contents === false) {
                    throw new RuntimeException('Entrée illisible : ' . $name);
                }

                if (file_put_contents($target, $contents) === false) {
                    throw new RuntimeException('Écriture impossible : ' . $target);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Refuse toute traversée de chemin et ne retient que les chemins gérés.
     */
    private function safeRelative(string $entryName): ?string
    {
        $relative = str_replace('\\', '/', $entryName);
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Entrée d’archive refusée : ' . $entryName);
        }

        // Les données de l'installation ne sont jamais écrasées.
        if (str_starts_with($relative, 'storage/') || $relative === 'config/local.php') {
            return null;
        }

        $first = explode('/', $relative)[0];
        $allowed = array_merge(self::MANAGED_PATHS, self::MANAGED_FILE_ROOTS);
        if (!in_array($first, $allowed, true)) {
            return null;
        }

        return $relative;
    }

    /**
     * Renommage, ou copie puis suppression si la source et la destination ne
     * sont pas sur le même système de fichiers (`storage/` déporté).
     */
    private function move(string $source, string $destination): void
    {
        $this->makeDirectory(dirname($destination));
        if (@rename($source, $destination)) {
            return;
        }

        $this->copyPath($source, $destination);
        $this->removePath($source);
    }

    private function copyPath(string $source, string $destination): void
    {
        if (is_file($source)) {
            $this->makeDirectory(dirname($destination));
            if (!copy($source, $destination)) {
                throw new RuntimeException('Copie impossible : ' . $destination);
            }

            return;
        }

        if (!is_dir($source)) {
            return;
        }

        $this->makeDirectory($destination);

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->copyPath($source . '/' . $entry, $destination . '/' . $entry);
        }
    }

    /**
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        $files = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                array_push($files, ...$this->filesIn($path));
            } elseif (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException('Répertoire inaccessible : ' . $directory);
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /**
     * Un hébergeur qui ne revalide pas les horodatages de l'OPcache servirait
     * l'ancien code depuis la mémoire, longtemps après la mise à jour.
     */
    private function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}
