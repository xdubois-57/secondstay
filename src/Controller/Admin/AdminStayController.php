<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Media\MediaItem;
use SecondStay\Media\MediaRepository;
use SecondStay\Stay\StayBlockReferences;
use SecondStay\Stay\StayInfoRepository;
use SecondStay\Stay\StaySecretRepository;
use SecondStay\Support\QrCode;

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

            $isPublic = $block !== null && $block->isPublic;
            $url = $isPublic ? $this->publicUrl($context, $code) : '';

            $fields[] = [
                'code' => $code,
                'phase' => $definition['phase'],
                'title' => $block === null ? '' : $block->title,
                'body' => $block === null ? '' : $block->body,
                'media_id' => $block?->mediaId,
                'link_url' => $block === null ? '' : $block->references->linkUrl,
                'link_label' => $block === null ? '' : $block->references->linkLabel,
                'source_url' => $block === null ? '' : $block->references->sourceUrl,
                'source_checked_on' => $block === null ? '' : ($block->references->checkedOn ?? ''),
                'published' => $block === null ? true : $block->published,
                'public' => $isPublic,
                'url' => $url,
                // Le QR est rendu en ligne : c'est une image à imprimer, pas
                // une ressource à demander au serveur à chaque affichage de
                // l'écran d'administration.
                'qr' => $url === '' ? '' : QrCode::toSvg($url, 4, 2),
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
            // Seuls les médias publiés et non privés sont proposés : le
            // livret est lu par un voyageur qui n'est pas administrateur, et
            // un média privé y produirait une image cassée.
            'media' => array_map(
                static fn (MediaItem $item): array => [
                    'id' => $item->id,
                    'label' => $item->caption($locale) !== '' ? $item->caption($locale) : $item->originalFilename,
                ],
                $this->container->get(MediaRepository::class)->published()
            ),
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

        // Les adresses sont validées avant la première écriture : un livret
        // à moitié enregistré, dont un bloc porte l'ancienne carte et le
        // suivant la nouvelle, est plus difficile à rattraper qu'un refus.
        $references = [];
        foreach (array_keys(StayInfoRepository::BLOCKS) as $code) {
            $result = StayBlockReferences::fromInput(
                (string) $context->request->input('link_url_' . $code, ''),
                (string) $context->request->input('link_label_' . $code, ''),
                (string) $context->request->input('source_url_' . $code, ''),
                (string) $context->request->input('source_checked_on_' . $code, ''),
            );

            if (!$result['ok']) {
                $this->flashError($result['error']);

                return $this->redirect(
                    $context->request->basePath
                    . $this->router()->path('admin.stay', ['locale' => $locale], $context->locale)
                );
            }

            $references[$code] = $result['value'];
        }

        foreach (array_keys(StayInfoRepository::BLOCKS) as $code) {
            $blocks->save(
                $code,
                $locale,
                (string) $context->request->input('title_' . $code, ''),
                (string) $context->request->input('body_' . $code, ''),
                $context->request->input('published_' . $code) !== null,
                $context->request->input('public_' . $code) !== null,
                $this->mediaChoice($context, $code),
                $references[$code],
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

    /**
     * Illustration choisie pour un bloc, si elle existe encore.
     *
     * Une valeur inventée ou un média supprimé entre l'affichage du
     * formulaire et son envoi laisse le bloc sans illustration plutôt que de
     * casser l'enregistrement : le texte du bloc porte l'essentiel.
     */
    private function mediaChoice(RequestContext $context, string $code): ?int
    {
        $chosen = (int) ($context->request->input('media_' . $code, '0') ?? '0');
        if ($chosen <= 0) {
            return null;
        }

        $item = $this->container->get(MediaRepository::class)->findById($chosen);

        return $item !== null && $item->isPublished && !$item->isPrivate ? $item->id : null;
    }

    /**
     * Adresse stable du bloc, telle qu'elle sera encodée dans le QR
     * (SPECIFICATIONS.md §47).
     *
     * L'adresse publique configurée prime sur celle de la requête : un QR
     * imprimé depuis un accès interne doit porter l'adresse que verront les
     * voyageurs, pas celle du poste qui a lancé l'impression.
     */
    private function publicUrl(RequestContext $context, string $code): string
    {
        $base = rtrim($this->settings()->string('site.public_url'), '/');
        if ($base === '') {
            $base = rtrim($context->request->baseUrl(), '/');
        }

        return $base . $this->router()->path('stay.info', ['code' => $code], $this->currentLocale($context));
    }

    private function currentLocale(RequestContext $context): string
    {
        $requested = $context->request->query('locale') ?? $context->request->input('locale');

        return is_string($requested) && Locales::isSupported($requested)
            ? $requested
            : $this->settings()->string('site.default_locale');
    }
}
