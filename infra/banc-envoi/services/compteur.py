"""Compteur HTTP : GET /inc?cle=x incrémente, GET /metrics liste (JSON)."""
import json
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlparse

COMPTES = {}


class H(BaseHTTPRequestHandler):
    def do_GET(self):
        u = urlparse(self.path)
        if u.path == "/inc":
            c = parse_qs(u.query).get("cle", ["?"])[0]
            COMPTES[c] = COMPTES.get(c, 0) + 1
        corps = json.dumps(COMPTES).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(corps))); self.end_headers(); self.wfile.write(corps)

    def log_message(self, *a):
        pass


if __name__ == "__main__":
    HTTPServer(("0.0.0.0", 8080), H).serve_forever()
