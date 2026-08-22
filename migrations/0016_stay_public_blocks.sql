-- SecondStay — publication d'un bloc du livret à une adresse stable
-- (SPECIFICATIONS.md §47).

-- Un QR collé sur la machine à laver ou sur le local à poubelles doit ouvrir
-- une page qui ne change jamais d'adresse : le sticker, lui, ne se met pas à
-- jour. Cette page est publique — un voyageur qui la scanne n'a ni compte, ni
-- lien invité, et souvent pas de réseau mobile à l'intérieur du logement.
--
-- La publication est donc **refusée par défaut**, bloc par bloc et langue par
-- langue. Le livret contient des choses qui n'ont rien à faire sur le web
-- ouvert : le code de la boîte à clés que le propriétaire aurait recopié dans
-- le texte du bloc « accès », l'adresse exacte, les habitudes des voisins. Un
-- réglage qui publierait tout d'un coup, ou par défaut, transformerait une
-- commodité en fuite.
ALTER TABLE `stay_info`
    ADD COLUMN `public` TINYINT(1) NOT NULL DEFAULT 0 AFTER `published`,
    ADD KEY `idx_stay_info_public` (`public`, `code`);
