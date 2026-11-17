# PO-L — liste des personnes : réseaux de chaque personne et filtre « réseau » (serveur)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691).

## Fichiers touchés
- M `backend/app/Http/Controllers/Api/Crm/PersonnesController.php`
- C `backend/tests/Feature/Crm/PersonnesFiltreReseauTest.php`
- C `docs/envoi/lots/PO-L.md`

## Comportement
- `origine_reseau` (jeton `RequetesOrigines::JETON`, un seul) sur `GET /crm/personnes` et `/crm/personnes/export`.
- Porte = `FiltreOrigine::actif($ws)` uniquement (aucune lecture directe du drapeau). Porte fermée + paramètre ⇒ 422
  `origine_lecture_inactive` ; jeton invalide (ou tableau) ⇒ 422. Défense en profondeur : `requete()` renvoie zéro ligne
  si le paramètre arrive porte fermée (échec fermé).
- Filtre : préfiltre GIN `origines @> [{"reseau": …}]` puis `EXISTS` sur une entrée non masquée.
- Champ `reseaux` (distinct, trié, entrées non masquées) seulement porte ouverte ; porte fermée ⇒ clé absente.
- `OriginesController` n'envoie jamais `origine_reseau` (test PL-L 7 : liste identique, aucune clé `reseaux`).

## Tests rouges d'abord
Avant le code : 7 tests lancés, 6 rouges pour la bonne raison (ex. export : « not to contain pol.deux@example.invalid »
— filtre ignoré ; `reseaux` absent ; 422 attendu non rendu) ; PL-L 5 passait trivialement.
Après : `Tests: 7 passed (67 assertions)`, chaque cas sous le propriétaire ET sous `axion_app` (`SET LOCAL ROLE`).

## Mutations (chacune annulée)
- porte retirée du filtre ⇒ PL-L 1 rouge ;
- `masque_le` retiré ⇒ PL-L 2 rouge ;
- paramètre ignoré porte fermée ⇒ PL-L 1 rouge.

## Vérifications
- `vendor/bin/pint --dirty` : passed ; PHPStan niveau 8 sur le contrôleur : OK.
- `pest --filter="Personnes|Origines"` : 263 passés (deux passages). Un premier passage avait montré un échec isolé
  (« sous axion_app : SIREN… ») non reproduit ensuite, ni seul ni en lot ; à surveiller en CI.
- Aucune migration, aucun drapeau touché, aucune suppression de données. Aucune PR ouverte ne touche ces fichiers.
