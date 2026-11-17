# X9-A — variables d'environnement de l'envoi documentées, procédure du jour J (v1)

Cahier : #680 (`docs/envoi/lots/FINI-cahier.md`, branche `conception/fini`).

Fichiers : `.env.example` (M), `docs/envoi/JOUR-J.md` (C), `backend/tests/Feature/Envoi/VariablesEnvoiDocumenteesTest.php` (C), ce compte rendu.

Écart au cahier : 20 variables manquaient (et non 18) : `ENVOI_K1_ACTIF` et `ENVOI_SMTP_DELAI_4XX_MINUTES` s'ajoutent. Toutes sont documentées. `ENVOI_REEL_ACTIF=false` inchangé. Défauts identiques à `config/envoi.php` ; `ENVOI_K1_ACTIF=false`.

Le test ne touche ni base ni réseau : le double passage pgsql_app / propriétaire et le test IDOR ne s'appliquent pas (aucune route, aucune donnée).

## Rouge d'abord (sur main)
```
  ✓ it ne met dans le bloc envoi aucune IP publique, aucun domaine réel, aucune longue clé
  ✓ it lit bien des variables ENVOI_ dans la config (garde contre une regex vide)
  ✓ it garde ENVOI_REEL_ACTIF=false dans .env.example
  ⨯ it documente chaque variable ENVOI_ de config/envoi.php dans .env.example
  ⨯ it documente chaque variable ENVOI_ de config/envoi.php dans docs/envoi/JOUR-J.md
liste manquante : 20 variables (TEMOINS_RELEVE_ACTIF … SANTE_ACTIF)
```
## Mutation (retrait de ENVOI_SANTE_ACTIF de .env.example), annulée
```
  ⨯ it documente chaque variable ENVOI_ de config/envoi.php dans .env.example
  +    0 => 'ENVOI_SANTE_ACTIF',
  Tests:    1 failed, 4 passed (37 assertions)
```
## Vert final
5 passed (38 assertions). Pint appliqué.
Les commandes non livrées dans JOUR-J.md sont marquées « (lot X…) » ; X9-B les retirera.
