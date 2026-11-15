# S2-SOCLE — Contrat du socle (arrêt général, reprise locale, notifications)

## Fait
- D1 côté contrat : `POST /envoi/tout-arreter` devient `POST /envoi/arret-general` (réponse `403` déclarée pour l'opérateur et le lecteur) ; `POST /envoi/reprendre` sort du contrat. Types `socle.d.ts` régénérés (`pnpm api:envoi`).
- D2 côté contrat : `POST /notifications/{id}/lue` devient `POST /notifications/{id}/read` (route réelle) ; le trou `notifications.php` est vidé.
- Trous R19 `socle.php` : `arret-general`, `etat`, `compteurs` (la route d'arrêt n'arrive qu'en vague 3).
- `CouvertureContratEnvoiTest` : seuil 107 + `not->toContain` reprendre / tout-arreter, `toContain` arret-general.
- Écran : `ToutArreter.tsx` appelle `api.post('/envoi/arret-general')` en littéral ; le bandeau n'a plus de bouton de reprise et donne la commande locale `envoi:reprendre`. Démo, gestionnaires MSW, e2e (A1–A3) et `ECARTS_CONNUS` alignés.

- Écart à la liste fermée (signalé) : `frontend/tests/e2e/demo/dm2-inf.spec.ts` (scénario P9) attendait le bouton « Reprendre » que D1 supprime ; la CI « Démo » rougissait. Test modifié : pourquoi (D1) — l'assertion devient « bandeau sans bouton, avec la commande `envoi:reprendre` ». Aucune autre PR ouverte ne touche ce fichier. À corriger dans le plan.

## Reste
- Route serveur `arret-general` (vague 3, audit sécurité) ; aucune route de reprise, voulu.

## Risques
- Le bouton « Reprendre » disparaît : la reprise passe par la commande locale journalisée (le stop gagne).
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée touchée.

## Tests lancés
- Pest `CouvertureContratEnvoiTest` (rouge puis vert) ; PHPStan global OK ; pint OK.
- Vitest complet (171 fichiers, 1577 verts) ; `pnpm typecheck`, `pnpm lint` OK.
- Pest `tests/Feature/Envoi` : les tests qui exigent PostgreSQL n'ont pas pu tourner dans ce conteneur (pas de base) : délégués à la CI.
- Playwright `socle-demo.spec.ts` : écrit, non joué ici (joué par `demo.yml`).
