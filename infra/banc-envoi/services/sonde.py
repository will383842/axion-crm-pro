"""Sonde réseau : tente des connexions SORTANTES vers des cibles réservées (RFC 5737 / .invalid).
Succès = zéro connexion aboutie. Code de sortie 1 si une connexion sort."""
import json, socket, sys

CIBLES = [("192.0.2.1", 25), ("198.51.100.1", 443), ("203.0.113.1", 80), ("banc-sortie.invalid", 443)]


def tenter(h, p, t):
    try:
        socket.create_connection((h, p), timeout=t).close()
        return True
    except OSError:
        return False


def verifier(connecter=tenter, timeout=3):
    ok = [f"{h}:{p}" for h, p in CIBLES if connecter(h, p, timeout)]
    return {"cibles": len(CIBLES), "sortantes": len(ok), "abouties": ok}


def code_sortie(r):
    return 1 if r["sortantes"] else 0


if __name__ == "__main__":
    r = verifier()
    print(json.dumps(r))
    sys.exit(code_sortie(r))
