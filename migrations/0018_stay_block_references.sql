-- SecondStay — carte et source d'un bloc du livret (SPECIFICATIONS.md §55).

-- La spécification demande une section séjour « configurable et sourcée »
-- portant, entre autres, des lieux et une carte. Le texte libre du bloc porte
-- déjà les types, les horaires et les consignes, mais deux choses lui manquent
-- et ne peuvent pas être rendues par du texte :
--
-- 1. Une **carte**. « Le local à poubelles est au bout de la rue à gauche »
--    ne se suit pas depuis un téléphone, dans le noir, avec une valise. Un
--    lien ouvrable — carte, plan d'accès, horaires officiels du service —
--    s'ouvre dans l'application de navigation du voyageur.
-- 2. Une **source**. Les règles locales changent : jours de collecte,
--    horaires de déchèterie, arrêté municipal sur le bruit. Un livret qui
--    affirme sans dire d'où vient l'information, ni quand elle a été
--    vérifiée, vieillit sans prévenir. C'est la même exigence que pour les
--    activités locales (§58) et la conformité (§12).
--
-- Les deux adresses sont saisies par le propriétaire, stockées telles quelles
-- et **jamais récupérées par le serveur** : elles ne sont qu'un `href` rendu
-- avec `rel="noopener noreferrer"`. Il n'y a donc pas de surface SSRF ici, et
-- `UrlGuard` — qui protège les récupérations sortantes — n'a pas à intervenir.
-- Seuls `http` et `https` sont acceptés : `javascript:` ou `data:` dans un
-- `href` serait une injection.
--
-- Par bloc **et par langue**, comme le reste de la table : une commune
-- néerlandophone et une commune francophone ne publient pas la même page.
ALTER TABLE `stay_info`
    ADD COLUMN `link_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `media_id`,
    ADD COLUMN `link_label` VARCHAR(120) NOT NULL DEFAULT '' AFTER `link_url`,
    ADD COLUMN `source_url` VARCHAR(500) NOT NULL DEFAULT '' AFTER `link_label`,
    -- Date de dernière vérification de la source, jamais devinée : elle vaut
    -- NULL tant qu'aucune source n'est renseignée.
    ADD COLUMN `source_checked_on` DATE NULL AFTER `source_url`;
