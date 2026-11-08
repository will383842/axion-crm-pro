"""Témoin positif : écoute TCP 8080 sur le réseau hors-banc (internal). Ne répond rien d'utile."""
import socketserver


class H(socketserver.BaseRequestHandler):
    def handle(self):
        pass


if __name__ == "__main__":
    socketserver.ThreadingTCPServer.allow_reuse_address = True
    socketserver.ThreadingTCPServer(("0.0.0.0", 8080), H).serve_forever()
