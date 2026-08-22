<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Media\MediaRepository;
use SecondStay\Stay\BlockIllustrations;
use SecondStay\Stay\StayInfoRepository;
use SecondStay\Tests\Support\DatabaseTestCase;

/**
 * Résolution des illustrations du livret (SPECIFICATIONS.md §45 et §55).
 *
 * Ce que ce test protège n'est pas le rendu — `StayInfoPageTest` s'en charge —
 * mais son **coût**. « Mon séjour » affiche jusqu'à huit blocs illustrés, et
 * c'est la page que le voyageur ouvre debout devant une porte, avec une barre
 * de réseau. Une résolution bloc par bloc y ferait seize allers-retours en
 * base sans que rien ne le signale : aucun test fonctionnel ne casse, la page
 * devient simplement plus lente à chaque illustration ajoutée.
 */
final class BlockIllustrationsTest extends DatabaseTestCase
{
    public function testAllTheIllustrationsOfAGuideAreResolvedInOneQuery(): void
    {
        $blocks = new StayInfoRepository($this->database);
        $media = new MediaRepository($this->database);

        foreach (array_keys(StayInfoRepository::BLOCKS) as $index => $code) {
            $blocks->save($code, 'fr', ucfirst($code), 'Texte du bloc.', true, false, $this->media($media, $index));
        }

        $published = $blocks->published('fr');
        self::assertCount(8, $published);

        $before = $this->selectCount();
        $illustrations = (new BlockIllustrations($media))->forBlocks($published, 'fr');
        $spent = $this->selectCount() - $before;

        self::assertCount(8, $illustrations);
        // Une requête pour les médias, une pour leurs traductions.
        self::assertLessThanOrEqual(
            2,
            $spent,
            'Huit blocs illustrés doivent coûter deux requêtes, pas seize.'
        );
    }

    /**
     * Un livret sans illustration ne doit rien demander du tout : la page
     * publique d'un bloc purement textuel est la plus fréquente des deux.
     */
    public function testAGuideWithoutAnyIllustrationCostsNoQuery(): void
    {
        $blocks = new StayInfoRepository($this->database);
        $blocks->save('welcome', 'fr', 'Bienvenue', 'Faites comme chez vous.');
        $blocks->save('rules', 'fr', 'Règles', 'Pas de fête après 22 h.');

        $published = $blocks->published('fr');
        $illustrations = new BlockIllustrations(new MediaRepository($this->database));

        $before = $this->selectCount();
        $resolved = $illustrations->forBlocks($published, 'fr');

        self::assertSame([], $resolved);
        self::assertSame(0, $this->selectCount() - $before);
    }

    private function media(MediaRepository $repository, int $index): int
    {
        $id = $repository->create([
            'filename' => 'bloc-' . $index . '.jpg',
            'original_filename' => 'bloc-' . $index . '.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'width' => 900,
            'height' => 600,
            'category' => 'general',
            'season' => 'all',
            'position' => $index,
            'is_published' => 1,
            'is_private' => 0,
            'hash' => str_pad((string) $index, 64, 'a'),
        ]);

        $repository->saveTranslation($id, 'fr', 'Légende ' . $index, 'Texte alternatif ' . $index);

        return $id;
    }

    /**
     * Nombre de `SELECT` servis par cette connexion depuis son ouverture.
     *
     * `SHOW SESSION STATUS` n'est pas lui-même un `SELECT` : la mesure ne se
     * compte pas elle-même.
     */
    private function selectCount(): int
    {
        $row = $this->database->fetchOne("SHOW SESSION STATUS LIKE 'Com_select'");

        return (int) ($row['Value'] ?? 0);
    }
}
