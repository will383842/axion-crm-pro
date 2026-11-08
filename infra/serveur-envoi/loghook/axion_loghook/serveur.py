"""Ecouteur LOCAL (127.0.0.1 seulement) du log hook : POST <enregistrement JSON Kumo> -> relais -> puits."""
import hmac
import json
import os
import pathlib
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

from .relais import EvenementRefuse

TAILLE_MAX = 1 << 20
ENTETE_SECRET = "X-Axion-Relais-Secret"  # secret Lua -> relais (distinct du secret relais -> passerelle)
DELAI_MAX = 10  # secondes : un client lent ou muet ne bloque jamais le relais


def _secret_valide(secret):
    return isinstance(secret, str) and len(secret) >= 32


def lire_secret(chemin):
    """Secret partage local : lu dans un fichier HORS depot (jamais dans le code), droits 0600 (aucun acces groupe/autres)
    et proprietaire = l'utilisateur courant. Absent, court ou mal protege => ValueError (le relais refuse de demarrer)."""
    try:
        st = os.stat(chemin)
        if st.st_mode & 0o077:
            raise ValueError("secret du log hook lisible par d'autres que son proprietaire (chmod 0600)")
        if hasattr(os, "geteuid") and st.st_uid != os.geteuid():
            raise ValueError("secret du log hook : proprietaire inattendu")
        secret = pathlib.Path(chemin).read_text().strip()
    except OSError as e:
        raise ValueError("secret du log hook illisible") from e
    if len(secret) < 32:
        raise ValueError("secret du log hook trop court (32 caracteres minimum)")
    return secret


class PuitsHttp:
    """Envoie l'evenement a la passerelle (point d'entree local, secret partage en en-tete).
    Panne ou 5xx => IOError (l'appelant rejoue). 400 => EvenementRefuse (definitif, jamais rejoue)."""

    def __init__(self, url, secret):
        if not url.startswith("http://127.0.0.1:"):
            raise ValueError("la passerelle locale n'est joignable que sur 127.0.0.1")
        if not isinstance(secret, str) or len(secret) < 32:
            raise ValueError("secret du log hook absent ou trop court")
        self.url, self._secret = url, secret

    def envoyer(self, evenement):
        req = urllib.request.Request(self.url, json.dumps(evenement).encode(),
                                     {"Content-Type": "application/json", "X-Axion-Loghook-Secret": self._secret})
        try:
            with urllib.request.urlopen(req, timeout=5) as r:
                if r.status != 200:
                    raise IOError("passerelle: %s" % r.status)
        except urllib.error.HTTPError as e:
            if e.code == 400:
                raise EvenementRefuse("la passerelle refuse cet enregistrement (400)") from e
            raise IOError("passerelle: %s" % e.code) from e
        except OSError as e:
            raise IOError("passerelle indisponible") from e


def creer(relais, secret, port=0):
    """Ecouteur local. `secret` obligatoire (>= 32 caracteres) : sans lui, le relais ne demarre pas."""
    if not _secret_valide(secret):
        raise ValueError("secret Lua -> relais absent ou trop court (32 caracteres minimum)")
    attendu = secret.encode()

    class H(BaseHTTPRequestHandler):
        timeout = DELAI_MAX

        def do_POST(self):
            # Controle AVANT toute lecture du corps : un autre processus local ne forge ni rebond ni reception.
            recu = self.headers.get(ENTETE_SECRET, "").encode("utf-8", "replace")
            if not hmac.compare_digest(recu, attendu):
                return self._rep(403)
            try:
                n = int(self.headers.get("Content-Length", "0"))
                if not 0 < n <= TAILLE_MAX:
                    return self._rep(400)  # jamais 413 : Kumo le rejouerait sans fin (451)
                relais.traiter(json.loads(self.rfile.read(n)))
            except (ValueError, TypeError, AttributeError):
                return self._rep(400)
            except IOError:
                return self._rep(503)  # Kumo reessaie : rien n'est perdu
            self._rep(200)

        def _rep(self, code):
            self.send_response(code)
            self.send_header("Content-Length", "0")
            self.end_headers()

        def log_message(self, *a):
            pass

    return HTTPServer(("127.0.0.1", port), H)
