<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\I18n\Locales;
use SecondStay\Stay\BlockIllustrations;
use SecondStay\Stay\StayInfoRepository;

/**
 * Pages d'information à adresse stable, pour les QR physiques
 * (SPECIFICATIONS.md §47).
 *
 * Un QR collé sur la machine à laver, sur le local à poubelles ou près du
 * compteur doit ouvrir une page qui ne change jamais d'adresse : l'autocollant,
 * lui, ne se met pas à jour. `/{langue}/info/{bloc}` est donc dérivée du code
 * du bloc et de rien d'autre — ni identifiant, ni jeton, ni date.
 *
 * Cette page est **publique au sens strict** : celui qui scanne n'a ni compte,
 * ni lien invité, ni séjour en cours, et souvent pas de réseau mobile à
 * l'intérieur du logement. C'est précisément pourquoi la publication est
 * refusée par défaut et se décide bloc par bloc : le livret d'accueil contient
 * des choses qui n'ont rien à faire sur le web ouvert, à commencer par un code
 * d'accès que le propriétaire aurait recopié dans le texte d'un bloc.
 *
 * Ce qui est servi ici ne contient jamais de secret : les codes d'accès vivent
 * dans `stay_secret`, chiffrés, et ne sont rendus que dans « Mon séjour »
 * pendant la fenêtre du séjour. Cette page ne les lit pas.
 *
 * Le repli de langue obéit à la même logique : il comble une **lacune**, il ne
 * défait pas une décision. Un bloc jamais traduit est servi dans la langue du
 * logement, parce qu'une information dans la mauvaise langue vaut mieux qu'une
 * page absente ; un bloc renseigné puis retiré du web ouvert, ou dépublié du
 * livret, répond 404 dans cette langue-là. Sans cette distinction, retirer le
 * bloc allemand parce qu'il contenait le code de la boîte à clés laisserait
 * l'adresse allemande ouverte sur le bloc français.
 */
final class StayInfoController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $code = (string) ($params['code'] ?? '');

        if (!array_key_exists($code, StayInfoRepository::BLOCKS)) {
            throw new NotFoundException('Bloc inconnu.');
        }

        $blocks = $this->container->get(StayInfoRepository::class);
        $requested = $blocks->find($code, $context->locale);
        $block = $requested !== null && $requested->isPubliclyReadable() ? $requested : null;

        // Repli sur la langue du logement, mais **seulement** quand cette
        // langue-ci n'a rien à montrer : bloc jamais traduit, ou traduit puis
        // vidé. Un bloc renseigné que le propriétaire a retiré du web ouvert
        // — ou du livret — est une décision, pas une lacune : la servir dans
        // une autre langue rouvrirait une adresse qu'il croit fermée, et le
        // texte de repli peut porter exactement ce qu'il voulait retirer.
        if ($block === null && ($requested === null || $requested->isEmpty())) {
            $fallback = $this->settings()->string('site.default_locale');
            $block = Locales::isSupported($fallback) ? $blocks->findPublic($code, $fallback) : null;
        }

        if ($block === null) {
            throw new NotFoundException('Bloc non publié.');
        }

        return $this->render('stay/info.html.twig', [
            'meta_title' => $block->title !== '' ? $block->title : $this->trans($block->labelKey()),
            // Nommée `info` et non `block` : `block` est un mot de Twig.
            'info' => $block,
            'illustration' => $this->container->get(BlockIllustrations::class)
                ->forBlock($block, $block->locale),
            'requested_locale' => $context->locale,
            // Un autocollant dans une cuisine n'a pas vocation à être indexé :
            // la page est publique parce qu'il le faut, pas parce qu'on la
            // cherche depuis un moteur de recherche.
            'meta_robots' => 'noindex, nofollow',
        ]);
    }
}
