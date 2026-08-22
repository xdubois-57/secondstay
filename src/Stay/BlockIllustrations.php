<?php

declare(strict_types=1);

namespace SecondStay\Stay;

use SecondStay\Media\MediaItem;
use SecondStay\Media\MediaRepository;

/**
 * Illustrations des blocs du livret (SPECIFICATIONS.md §45 et §55).
 *
 * Un gabarit ne doit pas aller chercher un média en base : la résolution se
 * fait ici, **en une requête** pour l'ensemble des blocs affichés.
 *
 * Seuls les médias **publiés et non privés** sont retenus. Ce n'est pas une
 * précaution de principe : le livret est lu par un voyageur qui n'est pas
 * administrateur, et par un visiteur anonyme sur les pages ouvertes depuis un
 * QR. Un média privé y produirait une image cassée, c'est-à-dire une
 * illustration qui n'illustre rien.
 */
final class BlockIllustrations
{
    public function __construct(private readonly MediaRepository $media)
    {
    }

    /**
     * @param list<StayInfoBlock> $blocks
     *
     * @return array<string, array{filename: string, alt: string, width: int, height: int}>
     */
    public function forBlocks(array $blocks, string $locale): array
    {
        // Une seule requête pour tous les blocs : « Mon séjour » en affiche
        // jusqu'à huit, et les résoudre un par un ferait seize allers-retours
        // en base sur la page que le voyageur ouvre justement avec une barre
        // de réseau.
        $ids = [];
        foreach ($blocks as $block) {
            if ($block->mediaId !== null) {
                $ids[] = $block->mediaId;
            }
        }

        $items = $this->media->findManyById($ids);

        $illustrations = [];
        foreach ($blocks as $block) {
            $entry = $this->describe($block, $locale, $block->mediaId === null ? null : ($items[$block->mediaId] ?? null));
            if ($entry !== null) {
                $illustrations[$block->code] = $entry;
            }
        }

        return $illustrations;
    }

    /**
     * @return array{filename: string, alt: string, width: int, height: int}|null
     */
    public function forBlock(StayInfoBlock $block, string $locale): ?array
    {
        if ($block->mediaId === null) {
            return null;
        }

        return $this->describe($block, $locale, $this->media->findById($block->mediaId));
    }

    /**
     * @return array{filename: string, alt: string, width: int, height: int}|null
     */
    private function describe(StayInfoBlock $block, string $locale, ?MediaItem $item): ?array
    {
        if (!$item instanceof MediaItem || !$item->isPublished || $item->isPrivate) {
            return null;
        }

        // Le texte alternatif traduit prime ; à défaut, la légende ; à défaut,
        // le titre du bloc. Une image sans alternative textuelle est une image
        // qui n'existe pas pour qui ne la voit pas.
        $alt = $item->altText($locale);
        if (trim($alt) === '') {
            $alt = $item->caption($locale);
        }
        if (trim($alt) === '') {
            $alt = $block->title;
        }

        return [
            'filename' => $item->filename,
            'alt' => $alt,
            'width' => $item->width,
            'height' => $item->height,
        ];
    }
}
