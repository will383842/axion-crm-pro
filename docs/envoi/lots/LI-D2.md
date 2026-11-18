# LI-D2 — entrée de menu « File LinkedIn » et durcissements de l'écran (UI-LI)

Compte rendu manquant de LI-D2, complété par le lot UI-LI (relectures de #745 et #747). Front seul, aucune migration, aucun drapeau.

## LI-D2 (déjà fusionné)
L'entrée « File LinkedIn » du menu n'existe que si le serveur sert la file : 404, refus ou erreur ⇒ pas d'entrée (échec fermé). Sonde en lecture seule, aucun effet.

## UI-LI
- `CarteProspect` : le lien « Ouvrir le profil » n'est rendu que si `profil` commence par `https://www.linkedin.com/in/`. Sinon, texte simple « Profil non cliquable » : aucun `href`, donc jamais de `javascript:`, de `data:`, de `http:` ni d'un autre site.
- `Sidebar` : la clé de cache de la sonde porte l'espace (`['menu', 'prospection-linkedin-disponible', <espace>]`), avec un `staleTime` de 5 minutes, sans rechargement au retour sur la fenêtre. Sans espace connu, pas de sonde. Deux rendus du même espace : une seule requête ; changement d'espace : une nouvelle requête.

## Tests
- `frontend/src/features/prospection-linkedin/CarteProspect.test.tsx` (9) ;
- `frontend/tests/components/SidebarLinkedin.test.tsx` (3).
Tests existants inchangés (suite entière : 1886 verts).

Mutations prouvées rouges : lien rendu sans contrôle (8 tests rouges) ; clé de cache sans l'espace (test de changement d'espace rouge).
