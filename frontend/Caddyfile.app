# Axion CRM Pro — Caddy interne au container `app` (mode prod)
# Sert le SPA Vite (dist/) sur :5173, SPA fallback vers /index.html.
# Pas de TLS ici : le Caddy frontal (infra/caddy/Caddyfile) reverse-proxy
# https://app.axion-crm-pro.com -> http://app:5173.

{
    admin off
    auto_https off
}

:5173 {
    root * /srv/app/dist
    encode zstd gzip

    # ========================================================================
    # 8.2 de `10_NAVIGATION-CIBLE.md` - LES VRAIES 301
    # ========================================================================
    #
    # Le routeur de l'application porte deja ces quatre redirections
    # (`src/app/routeTree.tsx`, garde
    # `tests/screens/redirections-navigation-cible.test.tsx`). MAIS une
    # redirection de routeur n'est PAS une 301 : le serveur rend `index.html`
    # en 200, le navigateur charge tout le paquet JavaScript, et c'est
    # seulement ensuite que l'adresse change. Le 8.2 ecrit « 301 » ; sans ces
    # quatre lignes, aucun 301 n'existe nulle part.
    #
    # Ce que les 301 apportent, et que la redirection de routeur ne donne pas :
    #   - un signet ou un lien externe est corrige par le navigateur lui-meme,
    #     durablement, sans charger l'application ;
    #   - aucun ecran intermediaire ne s'affiche, meme une fraction de seconde ;
    #   - un client en ligne de commande (`curl`, une sonde, un moteur) voit la
    #     redirection au lieu d'un 200 trompeur.
    #
    # LES DEUX COUCHES SONT VOULUES, CE N'EST PAS UN DOUBLON. La 301 sert la
    # navigation qui ARRIVE de l'exterieur ; celle du routeur sert la
    # navigation INTERNE, ou aucune requete HTTP n'est emise. Retirer l'une des
    # deux laisse un trou d'un cote.
    #
    # ATTENTION : la cible de `/analytics` est un ECART ASSUME. Le 8.2 prescrit
    # `/pilotage`, qui n'existe pas encore. Voir le commentaire de
    # `analyticsRedirectRoute` dans `routeTree.tsx`.
    redir /cold-email /pas-encore-livre?lot=L7 permanent
    redir /linkedin /pas-encore-livre?lot=L7 permanent
    redir /crm /contacts permanent
    redir /analytics / permanent

    # SPA fallback : tout chemin qui n'est pas un fichier réel -> index.html
    @notFile {
        not file
    }
    rewrite @notFile /index.html

    file_server

    # Cache long pour assets fingerprintés Vite (sha256 dans le nom)
    @assets path /assets/*
    header @assets Cache-Control "public, max-age=31536000, immutable"

    # Pas de cache pour l'index (SPA shell)
    @index path / /index.html
    header @index Cache-Control "no-store"

    log {
        output stdout
        format console
    }
}
