"""IMAP minimal du banc, avec les 6 boîtes témoins (A10).

Un seul processus (conteneur imap-banc) héberge DEUX serveurs sur le MÊME objet Banc :
- le puits SMTP (port 25, mode accepte) qui refuse en 550 tout RCPT hors des 6 témoins et
  dépose les messages acceptés selon le comportement de chaque boîte (inbox | junk | absent) ;
- ce serveur IMAP (port 143) qui les relit : LOGIN authentifié, SELECT INBOX|Junk (EXISTS réel), SEARCH.
Réglage par variables d'environnement : BANC_SECRET (mots de passe dérivés, voir temoins.mot_de_passe)
et BANC_TEMOIN_<FOURNISSEUR>=inbox|junk|absent. Rien n'est écrit sur disque (read_only conservé)."""
import asyncio
import shlex

from temoins import Banc
from puits_smtp import faire_client

BANC = Banc()  # les 6 boîtes témoins (A10), partagé avec le puits SMTP du même processus


def faire_client_imap(banc):
    async def client(r, w):
        w.write(b"* OK IMAP banc pret\r\n")
        boite = boite_ouverte = None
        while not r.at_eof():
            l = (await r.readline()).decode(errors="replace").strip()
            if not l: break
            tag, _, cmd = l.partition(" ")
            try: arg = shlex.split(cmd)  # gère les identifiants entre guillemets
            except ValueError: arg = []
            c = arg[0].upper() if arg else ""
            t = tag.encode()
            if c == "CAPABILITY": w.write(b"* CAPABILITY IMAP4rev1\r\n")
            elif c == "LOGIN":
                b = banc.boites.get(arg[1]) if len(arg) > 2 else None
                if not b or b.mot_de_passe != arg[2]:
                    w.write(t + b" NO refuse\r\n"); await w.drain(); continue
                boite = b
            elif c in ("SELECT", "EXAMINE", "LIST", "SEARCH") and not boite:
                w.write(t + b" NO non authentifie\r\n"); await w.drain(); continue
            elif c in ("SELECT", "EXAMINE"):
                d = "junk" if len(arg) > 1 and arg[1].lower() == "junk" else "inbox"
                boite_ouverte = d
                w.write(f"* {len(boite[d])} EXISTS\r\n* 0 RECENT\r\n".encode())
            elif c == "LIST": w.write(b'* LIST () "/" INBOX\r\n* LIST () "/" Junk\r\n')
            elif c == "SEARCH":
                ids = " ".join(str(i + 1) for i in range(len(boite[boite_ouverte]))) if boite_ouverte else ""
                w.write(f"* SEARCH {ids}\r\n".encode())
            if c == "LOGOUT":
                w.write(b"* BYE\r\n" + t + b" OK fin\r\n"); break
            w.write(t + b" OK " + c.encode() + b" fait\r\n")
            await w.drain()
        w.close()
    return client


async def main():
    smtp = await asyncio.start_server(faire_client("accepte", BANC), "0.0.0.0", 25)
    imap = await asyncio.start_server(faire_client_imap(BANC), "0.0.0.0", 143)
    async with smtp, imap:
        await asyncio.gather(smtp.serve_forever(), imap.serve_forever())


if __name__ == "__main__":
    asyncio.run(main())
