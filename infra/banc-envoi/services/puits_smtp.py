"""Puits SMTP programmable. MODE = accepte | 4xx | 5xx | lent. Aucun envoi réel : ne relaie jamais."""
import asyncio, os, urllib.request

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


async def client(r, w):
    async def dire(s):
        w.write((s + "\r\n").encode()); await w.drain()
    await dire("220 puits-banc ESMTP")
    en_data = False
    while not r.at_eof():
        ligne = (await r.readline()).decode(errors="replace").rstrip("\r\n")
        if en_data:
            if ligne == ".":
                en_data = False
                await asyncio.sleep(delai(MODE))
                rep = reponse_data(MODE)
                compter("recu" if rep[0] == "2" else "refuse")
                await dire(rep)
            continue
        cmd = ligne.upper()
        if cmd.startswith(("EHLO", "HELO")): await dire("250 puits-banc")
        elif cmd.startswith(("MAIL", "RCPT", "RSET", "NOOP")): await dire("250 OK")
        elif cmd.startswith("DATA"): en_data = True; await dire("354 go")
        elif cmd.startswith("QUIT"): await dire("221 bye"); break
        else: await dire("502 non gere")
    w.close()


async def main():
    srv = await asyncio.start_server(client, "0.0.0.0", 25)
    async with srv:
        await srv.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
