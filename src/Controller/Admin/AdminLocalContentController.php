<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Http\UrlGuard;
use SecondStay\I18n\Locales;
use SecondStay\Llm\LlmProvider;
use SecondStay\LocalContent\LocalContentRepository;
use SecondStay\LocalContent\LocalContentService;
use SecondStay\LocalContent\PromptBuilder;

/**
 * Contenu local : sources, consigne, essai (SPECIFICATIONS.md §56).
 *
 * L'écran tient en trois gestes : coller des URL, écrire une consigne — ou en
 * faire proposer une à partir de la localisation — et lancer un essai. Tout le
 * reste est fait par le pipeline.
 */
final class AdminLocalContentController extends AdminController
{
    protected function section(): string
    {
        return 'local';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $service = $this->container->get(LocalContentService::class);
        $repository = $this->container->get(LocalContentRepository::class);
        $prompts = $this->container->get(PromptBuilder::class);

        return $this->renderAdmin('admin/local-content.html.twig', [
            'meta_title' => $this->trans('local.admin.title'),
            'enabled' => $service->isEnabled(),
            'provider' => $this->container->get(LlmProvider::class)->name(),
            'configured' => $this->container->get(LlmProvider::class)->isConfigured(),
            'sources' => $repository->sources(),
            'generations' => $repository->recentGenerations(10),
            'window_weeks' => $service->windowWeeks(),
            'refresh_days' => $service->refreshDays(),
            'has_location' => $prompts->hasLocation(),
            'location' => $prompts->location(),
            'instructions' => $this->settings()->string('llm.prompt'),
            'suggested' => $this->takeSuggestion(),
            'locales' => Locales::ALL,
            'due' => count($service->dueStays()),
        ]);
    }

    /**
     * Ajoute une URL à consulter.
     *
     * @param array<string, string> $params
     */
    public function addSource(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $url = trim((string) $context->request->input('url', ''));

        // L'URL est contrôlée dès la saisie : signaler tout de suite qu'une
        // adresse interne est refusée vaut mieux qu'un échec silencieux à la
        // première génération (SECURITY.md §16).
        //
        // Un nom qui ne se résout pas est en revanche **accepté** : un site
        // peut être injoignable une minute, et refuser de l'enregistrer pour
        // autant serait pénible sans rien protéger. La vraie barrière est à la
        // sortie — `HttpFetcher` contrôle chaque requête et chaque
        // redirection, à chaque génération.
        $inspection = (new UrlGuard())->inspect($url);
        if ($inspection['ok'] === false && $inspection['reason'] !== 'ssrf.dns_failed') {
            // Le garde parle déjà : sa raison est une clé de traduction.
            $this->flashError($inspection['reason']);

            return $this->redirectToRoute($context, 'admin.local');
        }

        $repository = $this->container->get(LocalContentRepository::class);
        foreach ($repository->sources() as $existing) {
            if ($existing->url === $url) {
                $this->flashWarning('local.error.duplicate');

                return $this->redirectToRoute($context, 'admin.local');
            }
        }

        $repository->addSource($url, (string) $context->request->input('label', ''));

        $this->audit()->record('local.source_added', 'local_source', $url, null, null, $user->id, $user->email);

        $inspection['ok'] === true
            ? $this->flashSuccess('local.source_added')
            : $this->flashWarning('local.source_added_unresolved');

        return $this->redirectToRoute($context, 'admin.local');
    }

    /**
     * @param array<string, string> $params
     */
    public function toggleSource(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $repository = $this->container->get(LocalContentRepository::class);
        $source = $repository->findSource((int) ($params['id'] ?? 0));
        if ($source === null) {
            throw new NotFoundException('Source introuvable.');
        }

        $repository->setSourceActive($source->id, !$source->active);
        $this->flashSuccess('local.source_updated');

        return $this->redirectToRoute($context, 'admin.local');
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteSource(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $repository = $this->container->get(LocalContentRepository::class);
        $source = $repository->findSource((int) ($params['id'] ?? 0));
        if ($source === null) {
            throw new NotFoundException('Source introuvable.');
        }

        $repository->deleteSource($source->id);

        $this->audit()->record(
            'local.source_deleted',
            'local_source',
            $source->url,
            null,
            null,
            $user->id,
            $user->email
        );
        $this->flashSuccess('local.source_deleted');

        return $this->redirectToRoute($context, 'admin.local');
    }

    /**
     * Propose une consigne à partir de la localisation configurée.
     *
     * @param array<string, string> $params
     */
    public function suggestPrompt(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $prompts = $this->container->get(PromptBuilder::class);
        if (!$prompts->hasLocation()) {
            $this->flashError('local.error.no_location');

            return $this->redirectToRoute($context, 'admin.local');
        }

        // La proposition est mise dans le formulaire, pas enregistrée : c'est
        // un point de départ que le propriétaire relit avant de l'accepter.
        $this->session()->set('local_suggestion', $prompts->suggestedInstructions($context->locale));

        return $this->redirectToRoute($context, 'admin.local');
    }

    /**
     * Enregistre la consigne libre.
     *
     * @param array<string, string> $params
     */
    public function savePrompt(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $this->settings()->set(
            'llm.prompt',
            mb_substr(trim((string) $context->request->input('prompt', '')), 0, 4000),
            $user->email,
            $user->id,
        );

        $this->flashSuccess('local.prompt_saved');

        return $this->redirectToRoute($context, 'admin.local');
    }

    /**
     * Essai à blanc.
     *
     * @param array<string, string> $params
     */
    public function test(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $locale = (string) $context->request->input('locale', $context->locale);
        $result = $this->container->get(LocalContentService::class)
            ->test(Locales::isSupported($locale) ? $locale : $context->locale);

        $result['ok'] ? $this->flashSuccess('local.tested') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.local');
    }

    /**
     * Rafraîchit les séjours entrés dans la fenêtre.
     *
     * @param array<string, string> $params
     */
    public function refresh(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $result = $this->container->get(LocalContentService::class)->refreshDue();

        $result['stays'] > 0 ? $this->flashSuccess('local.refreshed') : $this->flashWarning('local.nothing_due');

        return $this->redirectToRoute($context, 'admin.local');
    }

    private function takeSuggestion(): string
    {
        $suggestion = $this->session()->string('local_suggestion');
        if ($suggestion !== '') {
            $this->session()->remove('local_suggestion');
        }

        return $suggestion;
    }
}
