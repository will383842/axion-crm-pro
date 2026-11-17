# Lot L7-C — plainte, arrêt en vol, opposé jamais réinscrit (et cloisonnement)

Cahier : `docs/envoi/lots/B6-B8-cahier.md` §6 (branche `conception/b6-b8`) ; consignes : `CONSIGNES-ROUTINE.md` (branche `conception/fini`, cahier FINI #680).

## Fichiers
- C `backend/tests/Feature/Envoi/BoutEnBout/CampagneArretsTest.php` (préfixe `beba`, 6 scénarios × rôle applicatif et propriétaire)
- M `backend/tests/Support/BancCampagne.php` (ajouts : `avecChauffe`, `campagneSurMemeCible`, `plainte` ; `arf()` conservé tel quel)
- C `docs/envoi/lots/L7-C.md`

Aucun code applicatif modifié ; `ENVOI_REEL_ACTIF` reste faux, aucun drapeau touché, aucun courriel réel.

## Scénarios
- **S6** (`beba 1`, `beba 2`) : ARF `not-spam` sans effet ; ARF `abuse` ⇒ inscription arrêtée, adresse supprimée (raison `complaint`) et opposée, compteur de plaintes à 1, palier tenu (aucune pause) ; étape 2 de A jamais injectée. Seconde plainte sous 7 jours ⇒ pause globale de l'espace de 48 h (`deux_plaintes_en_sept_jours`) ; avec la garde de chauffe réelle branchée sur la passerelle du banc, 0 injection pour toutes les inscriptions, restées `active` ; aucune levée automatique.
- **S7** (`beba 3`, `beba 4`) : `arreter` ⇒ actives `arretee/campagne_arretee`, `arretee/reponse` garde son motif, 0 injection. `pause` ⇒ 0 injection ; `reprendre` ⇒ étape 2 part à l'échéance suivante.
- **S8** (`beba 5`, `beba 6`) : A (STOP) écarté (`opposition`) par les campagnes 2, 3 (après arrêt de la 1) et 4 (fiche mise à la corbeille puis restaurée), 0 inscription de A. Cloisonnement : l'espace B n'a ni événement, ni pause, ni compteur de A ; la même adresse dans B est écartée (liste de suppression globale, échec fermé, voulu : ESCALADE 5 du cahier, comportement en vigueur asserté).

## Résultats
`vendor/bin/pest tests/Feature/Envoi/BoutEnBout` vert ; répété 4 fois sans échec.
Mutations jouées (puis annulées) : `CompteurRetours` neutralisé ⇒ `beba 1` et `beba 2` rouges ; clôture retirée de `GestesCampagne::arreter` ⇒ `beba 3` rouge ; `opposition` retiré de `SelectionDestinataires::PRIORITE` ⇒ `beba 5` et `beba 6` rouges.

## Notes
- Le premier envoi est étalé de quelques minutes : le banc rejoue le cycle à 10:30 puis 11:00 (idempotent).
- Les tests décrivent un comportement existant : leur « rouge » est démontré par les mutations ci-dessus.
