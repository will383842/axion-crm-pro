"""Écouteur asyncio : STARTTLS obligatoire, TLS 1.2 minimum. Aucun envoi réel."""
import asyncio
import os
import ssl

from .config import Config
from .file_kumo import FileKumo
from .limiteur import LimiteurEchecs
from .session import Session


def contexte_tls(cfg: Config) -> ssl.SSLContext:
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    ctx.load_cert_chain(cfg.cert, cfg.cle)
    return ctx


def _clair_en_attente(r) -> bool:
    """Octets déjà reçus en clair derrière STARTTLS (injection de commandes, type CVE-2024-34083).

    Un ClientHello légitime commence par 0x16 ; tout autre octet en attente est du clair."""
    tampon = bytes(getattr(r, "_buffer", b""))
    return bool(tampon) and tampon[:1] != b"\x16"


async def _client(cfg, file, ctx, limiteur, etat, r, w):
    if etat["n"] >= cfg.max_connexions:
        w.write(b"421 4.3.2 trop de connexions\r\n")
        w.close()
        return
    etat["n"] += 1
    loop = asyncio.get_running_loop()
    fin_session = loop.time() + cfg.duree_max_session
    s = Session(cfg, file, w.get_extra_info("peername")[0], limiteur)
    rep, act = s.accueil()
    try:
        while True:
            if act == "starttls" and r._buffer:      # tampon non vide : pipelining interdit
                rep, act = ["503 5.5.1 commandes apres STARTTLS refusees"], "close"
            for l in rep:
                w.write(l.encode() + b"\r\n")
            await w.drain()
            if act == "close":
                break
            if act == "starttls":
                if _clair_en_attente(r):
                    break
                await w.start_tls(ctx)
                s.tls_etabli()
            restant = fin_session - loop.time()
            if restant <= 0:
                w.write(b"421 4.4.2 duree maximale de session atteinte\r\n")
                break
            try:
                brut = await asyncio.wait_for(r.readuntil(b"\n"), min(300, restant))
            except (asyncio.IncompleteReadError, asyncio.LimitOverrunError, asyncio.TimeoutError):
                break
            rep, act = s.ligne(brut, differer_auth=True)
            if act == "auth":                         # PBKDF2 hors de la boucle asyncio
                rep, act = await loop.run_in_executor(None, s.verifier_auth)
            if s.delai:
                await asyncio.sleep(s.delai)
                s.delai = 0.0
    except (ssl.SSLError, ConnectionError):
        pass
    finally:
        etat["n"] -= 1
        w.close()


async def demarrer(cfg: Config, file: FileKumo):
    ctx = contexte_tls(cfg)
    limiteur = LimiteurEchecs(cfg.max_echecs_ip, cfg.fenetre_echecs)   # par IP, partagé entre sessions
    etat = {"n": 0}
    return await asyncio.start_server(
        lambda r, w: _client(cfg, file, ctx, limiteur, etat, r, w), cfg.ip_ecoute, cfg.port, limit=1 << 20)


def principal():  # pragma: no cover
    from .file_kumo import FausseFile
    cfg = Config.charger(os.environ["INJECTION_CONFIG"])
    # La file KumoMTA réelle sera branchée dans un lot ultérieur : ici, jamais d'envoi.
    file = FausseFile()

    async def boucle():
        srv = await demarrer(cfg, file)
        async with srv:
            await srv.serve_forever()
    asyncio.run(boucle())
