<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\FileResponse;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Media\MediaRepository;
use SecondStay\Media\MediaService;
use Throwable;

/**
 * Diffusion des médias.
 *
 * Les fichiers vivent hors racine web : ils ne sont accessibles que par cet
 * endpoint, qui applique la visibilité (publié / privé) côté serveur.
 */
final class MediaController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $variant = (string) ($params['variant'] ?? 'large');
        $filename = (string) ($params['filename'] ?? '');

        $item = $this->container->get(MediaRepository::class)->findByFilename($filename);
        if ($item === null) {
            throw new NotFoundException('Média inconnu.');
        }

        // Un média privé ou dépublié n'est jamais servi au public.
        if (!$item->isPublished || $item->isPrivate) {
            $this->requireRole(\SecondStay\Auth\Role::LocalManager);
        }

        try {
            $path = $this->container->get(MediaService::class)->variantPath($item->filename, $variant);
        } catch (Throwable) {
            throw new NotFoundException('Variante inconnue.');
        }

        if (!is_file($path)) {
            throw new NotFoundException('Fichier absent.');
        }

        $response = new FileResponse(
            $path,
            $item->originalFilename !== '' ? $item->originalFilename : $item->filename,
            $item->mimeType,
            true,
        );

        // Les médias publics sont immuables : leur nom contient un identifiant
        // aléatoire, un remplacement produit un nouveau nom.
        return $response->withHeader(
            'Cache-Control',
            $item->isPrivate ? 'private, no-store' : 'public, max-age=2592000, immutable'
        );
    }
}
