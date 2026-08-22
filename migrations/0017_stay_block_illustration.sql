-- SecondStay — illustration d'un bloc du livret (SPECIFICATIONS.md §45 et §55).

-- Le livret était entièrement textuel. La spécification demande pourtant des
-- photos : le tri des déchets, l'emplacement du local, la manœuvre d'un
-- appareil ou le chemin d'accès s'expliquent bien mieux par une image que par
-- un paragraphe — et le voyageur qui la consulte est debout devant la chose,
-- souvent dans une langue qui n'est pas la sienne.
--
-- L'illustration est choisie dans la médiathèque existante plutôt que
-- téléversée à part : le traitement d'image, la suppression des métadonnées
-- GPS, les variantes et les légendes traduites y sont déjà faits une fois pour
-- toutes.
--
-- `ON DELETE SET NULL` : supprimer un média retire l'illustration, il ne fait
-- pas disparaître le texte du bloc, qui porte l'essentiel de l'information.
ALTER TABLE `stay_info`
    ADD COLUMN `media_id` INT UNSIGNED NULL AFTER `body`,
    ADD KEY `idx_stay_info_media` (`media_id`),
    ADD CONSTRAINT `fk_stay_info_media` FOREIGN KEY (`media_id`)
        REFERENCES `media` (`id`) ON DELETE SET NULL;
