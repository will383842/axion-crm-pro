"""DNS minimal : répond A (IP fournie en env (IP_REPONSE = imap-banc, qui porte le Banc partagé)) et MX à tout nom ; rien ne sort du réseau."""
import os, socket, struct

REP = socket.inet_aton(os.environ.get("IP_REPONSE", "10.99.0.11"))


def repondre(req):
    i = 12
    while req[i]:
        i += req[i] + 1
    q = req[12:i + 5]
    qtype = struct.unpack(">H", req[i + 1:i + 3])[0]
    ent = req[:2] + b"\x81\x80" + b"\x00\x01" + (b"\x00\x01" if qtype == 1 else b"\x00\x00") + b"\x00\x00\x00\x00"
    rep = b"\xc0\x0c\x00\x01\x00\x01\x00\x00\x00\x3c\x00\x04" + REP if qtype == 1 else b""
    return ent + q + rep


if __name__ == "__main__":
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.bind(("0.0.0.0", 53))
    while True:
        d, a = s.recvfrom(512)
        try:
            s.sendto(repondre(d), a)
        except Exception:
            pass
