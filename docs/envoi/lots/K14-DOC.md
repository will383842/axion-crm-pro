# K14-DOC — Fiche des inscriptions corrigée

## Fait
- `docs/envoi/fiche-inscriptions-reputation.md` : ajout d'un tableau « Ordre à suivre et point du feu vert coché » ; précision que la CFL est celle de **Yahoo** et qu'Orange n'a qu'un contact délivrabilité ; ordre : Yahoo et JMRP avant le premier envoi, Postmaster et SNDS avant le palier 2 ; chaque section nomme son point du feu vert (`manuel.yahoo_cfl`, `manuel.snds_jmrp`, `manuel.postmaster_google`, `manuel.orange_cfl`, `manuel.signal_spam`, codes de D13).
- Documentation seulement : aucun lien, domaine, IP ni compte réels.

## Reste
- `manuel.yahoo_cfl` et le nouveau libellé d'Orange n'existent pas encore dans le catalogue : PR K14-CAT (la fiche les décrit comme cible D13).
- Écran « Inscriptions » en lecture seule : PR ultérieure du plan.
- Relecture de la fiche à la première inscription réelle (dettes K14-D1 à K14-D3 inchangées).

## Risques
- Tant que K14-CAT n'est pas fusionnée, le libellé du catalogue (« Orange (boucle de plaintes CFL) ») contredit la fiche ; le code, lui, est identique.
- `manuel.snds_jmrp` couvre deux inscriptions pour un seul point : précisé dans la fiche.
- `ENVOI_REEL_ACTIF=false` inchangé ; aucun envoi.

## Tests lancés
Aucun test (documentation). Relecture : recherche de liens, IP, domaines et noms privés dans les deux fichiers.
