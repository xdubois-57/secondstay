<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Stay\StayInfoRepository;
use SecondStay\Stay\StaySecretRepository;

/**
 * Livret d'accueil et codes d'accès (SPECIFICATIONS.md §44 et §45).
 *
 * Le livret existe réellement en quatre langues : cet écran montre donc, pour
 * chaque bloc, ce qui est renseigné et ce qui manque.
 */
final class AdminStayController extends AdminController
{
    protected function section(): string
    {
        return 'stay';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $locale = $this->currentLocale($context);
        $blocks = $this->container->get(StayInfoRepository::class);
        $secrets = $this->container->get(StaySecretRepository::class);

        $existing = $blocks->forLocale($locale);

        $fields = [];
        foreach (StayInfoRepository::BLOCKS as $code => $definition) {
            $block = $existing[$code] ?? null;

            $fields[] = [
                'code' => $code,
                'phase' => $definition['phase'],
                'title' => $block === null ? '' : $block->title,
                'body' => $block === null ? '' : $block->body,
                'published' => $block === null ? true : $block->published,
            ];
        }

        $secretFields = [];
        foreach (StaySecretRepository::CODES as $code) {
            $secretFields[] = [
                'code' => $code,
                'defined' => $secrets->isDefined($code),
                'preview' => $secrets->preview($code),
            ];
        }

        return $this->renderAdmin('admin/stay.html.twig', [
            'meta_title' => $this->trans('stay.admin.title'),
            'locales' => Locales::ALL,
            'current_locale' => $locale,
            'fields' => $fields,
            'secrets' => $secretFields,
            'completeness' => $blocks->completeness(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $locale = $this->currentLocale($context);
        $blocks = $this->container->get(StayInfoRepository::class);

        foreach (array_keys(StayInfoRepository::BLOCKS) as $code) {
            $blocks->save(
                $code,
                $locale,
                (string) $context->request->input('title_' . $code, ''),
                (string) $context->request->input('body_' . $code, ''),
                $context->request->input('published_' . $code) !== null,
            );
        }

        $this->audit()->record('stay.info_updated', 'stay_info', $locale, null, [
            'locale' => $locale,
        ], $user->id, $user->email);

        $this->flashSuccess('stay.admin.saved');

        return $this->redirect(
            $context->request->basePath
            . $this->router()->path('admin.stay', ['locale' => $locale], $context->locale)
        );
    }

    /**
     * Enregistre les codes d'accès.
     *
     * Un champ laissé vide conserve la valeur existante : l'interface ne
     * réaffiche jamais un secret, elle ne peut donc pas le renvoyer.
     *
     * @param array<string, string> $params
     */
    public function saveSecrets(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $secrets = $this->container->get(StaySecretRepository::class);
        $changed = [];

        foreach (StaySecretRepository::CODES as $code) {
            $value = (string) $context->request->input('secret_' . $code, '');

            if ($context->request->input('clear_' . $code) !== null) {
                $secrets->set($code, '');
                $changed[] = $code;
                continue;
            }

            if ($value === '') {
                continue;
            }

            $secrets->set($code, $value);
            $changed[] = $code;
        }

        if ($changed !== []) {
            $this->audit()->record('stay.secrets_updated', 'stay_secret', implode(',', $changed), null, [
                // Jamais la valeur : seulement quels codes ont changé.
                'codes' => $changed,
            ], $user->id, $user->email);
        }

        $this->flashSuccess('stay.admin.secrets_saved');

        return $this->redirectToRoute($context, 'admin.stay');
    }

    private function currentLocale(RequestContext $context): string
    {
        $requested = $context->request->query('locale') ?? $context->request->input('locale');

        return is_string($requested) && Locales::isSupported($requested)
            ? $requested
            : $this->settings()->string('site.default_locale');
    }
}
