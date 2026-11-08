"""Ecouteur LOCAL (127.0.0.1 seulement) du log hook : POST <enregistrement JSON Kumo> -> relais -> puits."""
import json
import urllib.request
from http.server import BaseHTTPRequestHandler, HTTPServer

TAILLE_MAX = 1 << 20


class PuitsHttp:
    """Envoie l'evenement a la passerelle (point d'entree local). Toute erreur => IOError : l'appelant rejoue."""

    def __init__(self, url):
        if not url.startswith("http://127.0.0.1:"):
            raise ValueError("la passerelle locale n'est joignable que sur 127.0.0.1")
        self.url = url

    def envoyer(self, evenement):
        req = urllib.request.Request(self.url, json.dumps(evenement).encode(), {"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=5) as r:
                if r.status != 200:
                    raise IOError("passerelle: %s" % r.status)
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
