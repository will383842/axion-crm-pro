# X1-L2-bis, lot L2b-D — écriture du filtre « réseau d'origine » (assistant de création)

Cahier : `docs/envoi/lots/X1-L2-bis-cahier.md` § 6 (branche `conception/x1-l2-bis`, PR #648). Préalables fusionnés : L2b-A (#653), L2b-B (#652), L2b-C (#660).

## Ce que fait le lot
- `DepotCampagnes::modifier` accepte `filtre_origine` (`{"reseaux": [...]}` ou `null`), validé par `FiltreOrigine::depuisColonne` ; seul ce chemin l'écrit (`creer` ne l'écrit pas). Un refus P0001 de la base (campagne reliée) devient `CampagneRefusee`, jamais une erreur brute.
- Assistant, étape 1 : `cible.origine` présent et filtre inactif → 422 `filtre_origine_inactif` (rien écrit) ; forme invalide → 422 `validation` ; `null` → retrait (permis même inactif) ; clé absente → colonne inchangée.
- `presenter()` rend `contenu.cible.origine` depuis la COLONNE, jamais depuis la saisie.
- `controles()` : sans filtre, aucun élément ; avec filtre, `filtre_origine` (`ok` si actif, `bloquant` sinon ou si illisible) et `filtre_origine_estimation` (`attention` : l'estimation ignore le filtre, cahier ESCALADE 3).
- Contrat `creation.yaml` : `Cible.origine` (nullable) et deux codes de contrôle.

## Écarts à signaler
- `frontend/src/features/envoi/api/creation.d.ts` est régénéré (`pnpm api:envoi`) : le garde `contrat-envoi-a-jour` exige que le `.d.ts` suive le YAML, il serait rouge sinon. Le cahier prévoit cette régénération en L2b-E ; elle devient sans objet là-bas.
- Le cahier ne donne pas le code du second contrôle : `filtre_origine_estimation` (à confirmer).
- Détail des contrôles : le libellé est fixe, le texte du cahier est dans `detail`.

## Hors lot
Aucune route, aucune migration, aucun drapeau touché ; `SelectionDestinataires`, `MaterialisationCampagne`, `RequetesOrigines` intacts.
