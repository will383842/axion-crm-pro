# LI-L3 — opposition par profil LinkedIn (zone de l'envoi)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), section LI-L3.
Dépend de PL-M0A (table `envoi_oppositions_linkedin`, en ligne : #694). Aucun appel vers LinkedIn, aucun envoi.

## Fichiers (seuls ceux-là sont touchés)
- C `backend/app/Envoi/Conformite/RegistreOppositionLinkedin.php`
- M `backend/app/Crm/Opposition/StatutOpposition.php` (méthode ajoutée `profilsOpposes` ; `pourLignes()` inchangée)
- C `backend/tests/Feature/Envoi/Conformite/OppositionLinkedinTest.php`
- C `docs/envoi/lots/LI-L3.md`

Non touchés : `RegistreOppositionManuel.php`, `OppositionSortante.php`, `DepotSocleDb.php`, `ENVOI_REEL_ACTIF`. Aucune PR ouverte ne porte
ces fichiers (vérifié au moment de pousser).

## Comportement
- `RegistreOppositionLinkedin::inscrire($ws, $profil, $motif, $auteur, $source): bool` : `LinkedinSlug::normalize` (null ⇒
  `InvalidArgumentException`, jamais de ligne vide) ; empreinte (même calcul que le registre manuel) + valeur chiffrée ;
  `INSERT … ON CONFLICT DO NOTHING` ; `true` si nouvelle, `false` au rejeu. Motif (3 à 200, sans `@`) et source contrôlés avant la base.
  Sous `WorkspaceContext::run`. Une erreur SQL devient une `RuntimeException` sans valeurs liées.
- `StatutOpposition::profilsOpposes($ws, $slugs): array<string,bool>` : une lecture pour la liste, filtrée par `workspace_id` ;
  profil non normalisable ⇒ compté opposé ; toute erreur de lecture ⇒ `RuntimeException` (échec fermé).
- Le rattachement à l'adresse ou au SIREN (`RegistreOppositionManuel::inscrire`) reste à LI-C, qui appelle ce registre.

## Tests (8, rôle applicatif `pgsql_app` ET propriétaire)
Trois formes d'URL ⇒ une ligne et rejeu `false` ; `/company/x`, domaine étranger, vide ⇒ refus ; motif/source invalides ;
`profilsOpposes` (opposé, non opposé, non normalisable ⇒ opposé) ; une seule requête ; table illisible (SELECT révoqué) ⇒ exception ;
espace B ne voit pas A ; aucune colonne en clair ; ajout seul (DELETE refusé).

## Preuve rouge puis verte
Le bac à sable met plus de 10 minutes par fichier Pest sur PostgreSQL : pas de run local long. La preuve est celle de la CI de la PR :
premier commit = tests + doc seuls (rouge attendu : classe et méthode absentes), second commit = code (vert attendu). Voir les commentaires de la PR.

## Mutations demandées (à rejouer en CI sur demande du relecteur)
`ON CONFLICT` retiré ⇒ test « trois formes » rouge ; non normalisable ⇒ « non opposé » ⇒ test `profilsOpposes` rouge ;
filtre `workspace_id` retiré ⇒ test d'espace rouge en propriétaire.
