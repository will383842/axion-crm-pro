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
