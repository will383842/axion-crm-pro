# CLAUDE.md — règles pour tout agent qui code ici

Dépôt PUBLIC : aucun secret, aucune vraie IP, aucun vrai domaine, aucune donnée de personne réelle.
Ne jamais écrire le prénom du responsable : écrire « le responsable du projet ».
La checklist technique du backend est dans `backend/AGENTS.md`.

## Taille et branches
- Petites PR : 250 à 400 lignes max, tests compris.
- La description de la PR liste les fichiers autorisés (et seulement ceux-là sont touchés).
- Branche TOUJOURS créée depuis `main`, jamais d'empilement de PR sur PR.

## Méthode
- Corrige d'abord tout ce qui est corrigeable. N'écris ESCALADE que pour une vraie décision
  (achat, production, juridique), et continue le reste sans t'arrêter.
- Tests ROUGES d'abord : écris le test, vois-le échouer, puis corrige.
- Ne jamais affaiblir, sauter ni supprimer un test (voir `backend/tests/Feature/NonRegression/LISEZ-MOI.md`).
- Migrations en AJOUTS SEULS : jamais de DROP ni de DELETE de données.
- Aucun envoi réel : `ENVOI_REEL_ACTIF=false` (config : `backend/config/envoi.php`).

## Avant de pousser
- `cd backend && vendor/bin/pint --dirty`
- Tests ciblés : `cd backend && vendor/bin/pest --filter=...`
- Si `composer` ne fonctionne pas (pas de `vendor/`), le dire dans la PR.

## Ouvrir la PR
`gh pr` (GraphQL) peut répondre 403 dans le cloud. Utiliser l'API REST :

```bash
gh api -X POST repos/will383842/axion-crm-pro/pulls \
  -f title="..." -f head="ma-branche" -f base="main" -f body="..."
```

Ne pas fusionner soi-même : la PR attend un relecteur.
