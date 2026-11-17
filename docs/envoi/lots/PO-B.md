# PO-B — branchement du filtre mot / guide dans `SelectionDestinataires`

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), § 4.
Branche : `feat/pl-po-b` (PR #708). Aucune migration, aucun drapeau touché, lecture seule, aucun envoi.

## Fichiers (et seulement ceux-là)
- M `backend/app/Envoi/Campagnes/SelectionDestinataires.php`
- C `backend/tests/Feature/Envoi/SelectionFiltreContenuTest.php` (10 cas ; ceux qui lisent la base tournent sous `pgsql_app` ET propriétaire)
- C `docs/envoi/lots/PO-B.md`

Dépendances : PL-M0A (colonne `filtre_origine_contenu`, en ligne), PO-A (`FiltreOrigineContenu`, `unitesParOrigine`, fusionnée `7b59f0c`).

## Comportement
- La requête de campagne lit aussi `NULLIF(to_jsonb(k) -> 'filtre_origine_contenu', 'null'::jsonb)` (colonne absente ou NULL = pas de filtre).
- Forme invalide d'un des deux filtres, ou `FiltreOrigine::actif` faux : `filtre_origine_inactif` AVANT toute lecture des membres.
- Filtre de contenu présent (avec ou sans réseau) : UNE lecture `unitesParOrigine` (réseau, mots, guides) ; unité non retenue = `hors_reseau` ;
  contact rattaché à une personne opposée = `opposition` (O2).
- Réseau seul : chemin `reseauxDesUnites` inchangé. Aucun filtre : aucune lecture d'origine.
- Exception de lecture : `lecture_origine_impossible` (réessayable), aucun destinataire (code existant, réutilisé).

## Preuve rouge puis verte (CI de la PR, pas de PostgreSQL local dans ce bac à sable)
- Rouge : commit tests seuls `e9ff3b7de1c62909d65115a0b8821d3897a6af6d`, job « Pest part 2/4 » : PO-B 2, 3, 4, 5, 6, 7, 9, 10 en échec ;
  PO-B 1 et 8 (non-régression : sans filtre / réseau seul) passent déjà, comme attendu. (Un premier push avait rougi pour une collision
  de noms d'aides de test ; corrigée avant cette preuve.)
- Vert : voir la CI du commit de code (indiquée dans le commentaire « PR PRÊTE POUR RELECTURE »).

## Mutations
Non rejouées en local (pas de base). Chaque test est écrit pour rougir sur la mutation demandée : filtre de contenu ignoré si inactif ⇒ PO-B 2 ;
refus après lecture des membres ⇒ PO-B 2 (aucune requête sur `listes_manuelles`…) ; O2 retirée ⇒ PO-B 5 ; exception avalée ⇒ PO-B 7.
Rejeu en CI non fait : écart signalé au relecteur.

`vendor/bin/pint --dirty` : passed.
