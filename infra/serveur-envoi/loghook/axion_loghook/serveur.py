"""Ecouteur LOCAL (127.0.0.1 seulement) du log hook : POST <enregistrement JSON Kumo> -> relais -> puits."""
import json
import pathlib
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

from .relais import EvenementRefuse

TAILLE_MAX = 1 << 20


def lire_secret(chemin):
    """Secret partage local : lu dans un fichier HORS depot (jamais dans le code). Absent ou court => ValueError."""
    try:
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


def creer(relais, port=0):
    class H(BaseHTTPRequestHandler):
        def do_POST(self):
            try:
                n = int(self.headers.get("Content-Length", "0"))
                if not 0 < n <= TAILLE_MAX:
                    return self._rep(413)
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
