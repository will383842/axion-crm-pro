"""Puits SMTP programmable. MODE = accepte | 4xx | 5xx | lent. Aucun envoi réel : ne relaie jamais."""
import asyncio, os, re, urllib.request

from temoins import AdresseRefusee, adresses

MODE = os.environ.get("MODE", "accepte")


def reponse_data(mode):
    return {"accepte": "250 2.0.0 OK banc", "lent": "250 2.0.0 OK banc (lent)",
            "4xx": "451 4.3.0 banc : reessayez plus tard", "5xx": "550 5.1.1 banc : refuse"}[mode]


def delai(mode):
    return 5 if mode == "lent" else 0


def compter(evenement):
    url = os.environ.get("COMPTEUR_URL")
    if url:
        try:
            urllib.request.urlopen(f"{url}/inc?cle={MODE}.{evenement}", timeout=2).read()
        except OSError:
            pass


def destinataire(ligne):
    m = re.match(r"RCPT\s+TO:\s*<([^<>]*)>", ligne, re.I)
    return m.group(1).strip().lower() if m else None


def faire_client(mode=MODE, banc=None):
    """Puits SMTP. Tout RCPT hors des 6 témoins est refusé (550). Si `banc` est fourni (Banc partagé
    avec le serveur IMAP, même processus), le message accepté est déposé dans les boîtes."""
    async def client(r, w):
        async def dire(s):
            w.write((s + "\r\n").encode()); await w.drain()
        await dire("220 puits-banc ESMTP")
        en_data, rcpts, corps = False, [], []
        while not r.at_eof():
            ligne = (await r.readline()).decode(errors="replace").rstrip("\r\n")
            if en_data:
                if ligne != ".":
                    corps.append(ligne); continue
                en_data = False
                await asyncio.sleep(delai(mode))
                rep = reponse_data(mode)
                compter("recu" if rep[0] == "2" else "refuse")
                if banc and rep[0] == "2":
                    for a in rcpts:
                        try: banc.livrer(a, "\n".join(corps))
                        except AdresseRefusee: pass
                rcpts, corps = [], []
                await dire(rep)
                continue
            cmd = ligne.upper()
            if cmd.startswith(("EHLO", "HELO")): await dire("250 puits-banc")
            elif cmd.startswith("MAIL"): rcpts, corps = [], []; await dire("250 OK")
            elif cmd.startswith("RCPT"):
                a = destinataire(ligne)
                if a in adresses(): rcpts.append(a); await dire("250 OK")
                else: await dire("550 5.1.1 banc : destinataire hors des 6 temoins")
            elif cmd.startswith(("RSET", "NOOP")): rcpts, corps = [], []; await dire("250 OK")
            elif cmd.startswith("DATA"):
                if rcpts: en_data = True; await dire("354 go")
                else: await dire("503 5.5.1 aucun destinataire valide")
            elif cmd.startswith("QUIT"): await dire("221 bye"); break
            else: await dire("502 non gere")
        w.close()
    return client


client = faire_client()


async def main():
    srv = await asyncio.start_server(client, "0.0.0.0", 25)
    async with srv:
        await srv.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
