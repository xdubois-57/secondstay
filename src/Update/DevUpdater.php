<?php

declare(strict_types=1);

namespace SecondStay\Update;

use SecondStay\Logging\Logger;
use Throwable;

/**
 * Installe le commit qu'une notification GitHub a mis en attente
 * (`DevUpdateQueue`), dès que la CI en a publié l'archive.
 *
 * Appelé par la tâche `dev_update` à chaque passage du planificateur. Sans
 * commit en attente, il ne fait rien — pas même un appel réseau.
 *
 * L'installation passe par **le même** `UpdateService::install()` que le
 * bouton et que les releases : empreinte SHA-256 annoncée par GitHub,
 * politique d'artefact, `VERSION` conforme à l'archive annoncée, sauvegarde
 * site fermé, retour arrière. Une construction de développement n'en
 * contourne aucune ; elle n'est jamais l'archive brute du dépôt, qui n'a ni
 * `vendor/` ni filtrage de ce qui part en production.
 *
 * Il n'y a délibérément **aucun repli** sur l'archive du dépôt quand la
 * construction tarde : passé le délai, l'attente est abandonnée et consignée.
 */
final class DevUpdater
{
    public const IDLE = 'idle';
    public const WAITING = 'waiting';
    public const INSTALLED = 'installed';
    public const ROLLED_BACK = 'rolled_back';
    public const REFUSED = 'refused';
    public const EXPIRED = 'expired';
    public const ALREADY = 'already';
    public const CANCELLED = 'cancelled';

    public function __construct(
        private readonly UpdateService $updates,
        private readonly DevUpdateQueue $queue,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param string $channel canal en vigueur (`update.channel`)
     *
     * @return array{status: string, version: string}
     */
    public function process(string $channel): array
    {
        $pending = $this->queue->pending();
        if ($pending === null) {
            return ['status' => self::IDLE, 'version' => ''];
        }

        // L'administrateur a quitté le canal de développement entre la
        // notification et son traitement : ce qu'elle demandait ne vaut plus.
        if (UpdateService::channel($channel) !== UpdateService::CHANNEL_DEV) {
            $this->queue->clear();

            return ['status' => self::CANCELLED, 'version' => ''];
        }

        try {
            $build = $this->updates->check(UpdateService::CHANNEL_DEV)['latest'];
        } catch (Throwable $throwable) {
            // GitHub injoignable ou limité : ce n'est qu'un « pas encore ».
            // Seul le délai met fin à l'attente.
            $this->logger->warning('update', 'Construction de développement illisible', [
                'reason' => $throwable->getMessage(),
            ]);
            $build = null;
        }

        if ($build !== null && $build->commit === $pending['commit']) {
            return $this->install($build);
        }

        if ($this->queue->now() > $pending['deadline']) {
            $this->queue->clear();
            $this->logger->warning('update', 'Construction de développement jamais publiée', [
                'commit' => $pending['commit'],
                'tag' => GitHubReleaseProvider::DEV_TAG,
            ]);

            return ['status' => self::EXPIRED, 'version' => ''];
        }

        return ['status' => self::WAITING, 'version' => ''];
    }

    /**
     * @return array{status: string, version: string}
     */
    private function install(ReleaseInfo $build): array
    {
        if ($build->version === $this->updates->currentVersion()) {
            $this->queue->clear();

            return ['status' => self::ALREADY, 'version' => $build->version];
        }

        try {
            $result = $this->updates->install($build, 'github-webhook');
        } catch (UpdateInProgressException) {
            // Une autre installation tourne encore — souvent celle du commit
            // précédent. Celui-ci attend son tour, dans la limite du délai.
            return ['status' => self::WAITING, 'version' => ''];
        } catch (Throwable $throwable) {
            // Refusée avant d'avoir rien touché : empreinte, artefact,
            // téléchargement. Le site tourne sur la version en place, et le
            // commit suivant aura sa propre chance.
            $this->queue->clear();
            $this->logger->error('update', 'Installation de développement refusée', [
                'version' => $build->version,
                'reason' => $throwable->getMessage(),
            ]);

            return ['status' => self::REFUSED, 'version' => $build->version];
        }

        $this->queue->clear();

        return [
            'status' => $result['installed'] ? self::INSTALLED : self::ROLLED_BACK,
            'version' => $build->version,
        ];
    }
}
