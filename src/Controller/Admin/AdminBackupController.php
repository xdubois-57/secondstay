<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Backup\BackupService;
use SecondStay\Core\Http\FileResponse;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Installer\RequirementChecker;
use Throwable;

/**
 * Sauvegardes : création, vérification, téléchargement contrôlé, restauration
 * et rétention. Réservé aux administrateurs (SECURITY.md §18).
 */
final class AdminBackupController extends AdminController
{
    protected function section(): string
    {
        return 'backups';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();
        $backups = $this->container->get(BackupService::class);

        return $this->renderAdmin('admin/backups.html.twig', [
            'meta_title' => $this->trans('admin.backups.title'),
            'backups' => array_map(
                static fn (array $backup): array => $backup + [
                    'human_size' => RequirementChecker::humanBytes($backup['size']),
                ],
                $backups->list()
            ),
            'disk_usage' => RequirementChecker::humanBytes($backups->diskUsage()),
            'retention' => $this->settings()->int('backup.retention_count'),
            'include_media' => $this->settings()->bool('backup.include_media'),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $backups = $this->container->get(BackupService::class);

        try {
            $backups->create($this->settings()->bool('backup.include_media'), $user->email, $user->id);
            $backups->applyRetention($this->settings()->int('backup.retention_count'));
            $this->flashSuccess('admin.backups.created');
        } catch (Throwable $throwable) {
            $this->logger()->error('backup', 'Création de sauvegarde impossible', ['reason' => $throwable->getMessage()]);
            $this->flashError('admin.backups.error.create');
        }

        return $this->redirectToRoute($context, 'admin.backups');
    }

    /**
     * @param array<string, string> $params
     */
    public function verify(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();
        $backups = $this->container->get(BackupService::class);

        try {
            $result = $backups->verify($backups->pathFor((string) ($params['id'] ?? '')));
        } catch (Throwable) {
            return Response::json(['ok' => false, 'problems' => [$this->trans('backup.error.unreadable')]], 404);
        }

        return Response::json([
            'ok' => $result['ok'],
            'problems' => array_map(
                fn (string $problem): string => $this->trans(explode(':', $problem)[0]),
                $result['problems']
            ),
            'manifest' => $result['manifest']?->toArray(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function download(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $backups = $this->container->get(BackupService::class);

        $path = $backups->pathFor((string) ($params['id'] ?? ''));

        $this->audit()->record(
            'backup.downloaded',
            'backup',
            (string) ($params['id'] ?? ''),
            null,
            null,
            $user->id,
            $user->email,
        );

        return new FileResponse($path, basename($path), 'application/zip');
    }

    /**
     * @param array<string, string> $params
     */
    public function restore(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $backups = $this->container->get(BackupService::class);

        try {
            $path = $backups->pathFor((string) ($params['id'] ?? ''));
            $backups->restore($path, $user->email, $user->id);
            $this->flashSuccess('admin.backups.restored');
        } catch (Throwable $throwable) {
            $this->logger()->error('backup', 'Restauration impossible', ['reason' => $throwable->getMessage()]);
            $this->flashError('admin.backups.error.restore');
        }

        return $this->redirectToRoute($context, 'admin.backups');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();
        $backups = $this->container->get(BackupService::class);

        try {
            $id = (string) ($params['id'] ?? '');
            $path = $backups->pathFor($id);
            unlink($path);
            $this->audit()->record('backup.deleted', 'backup', $id, null, null, $user->id, $user->email);
            $this->flashSuccess('admin.backups.deleted');
        } catch (Throwable) {
            $this->flashError('admin.backups.error.delete');
        }

        return $this->redirectToRoute($context, 'admin.backups');
    }
}
