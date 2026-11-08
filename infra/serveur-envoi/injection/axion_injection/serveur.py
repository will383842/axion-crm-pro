"""Écouteur asyncio : STARTTLS obligatoire, TLS 1.2 minimum. Aucun envoi réel."""
import asyncio
import os
import ssl

from .config import Config
from .file_kumo import FileKumo
from .session import Session


def contexte_tls(cfg: Config) -> ssl.SSLContext:
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    ctx.load_cert_chain(cfg.cert, cfg.cle)
    return ctx


async def _client(cfg, file, ctx, r, w):
    s = Session(cfg, file, w.get_extra_info("peername")[0])
    rep, act = s.accueil()
    try:
        while True:
            for l in rep:
                w.write(l.encode() + b"\r\n")
            await w.drain()
            if act == "close":
                break
            if act == "starttls":
                await w.start_tls(ctx)
                s.tls_etabli()
            try:
                brut = await asyncio.wait_for(r.readuntil(b"\n"), 300)
            except (asyncio.IncompleteReadError, asyncio.LimitOverrunError, asyncio.TimeoutError):
                break
            rep, act = s.ligne(brut)
    except (ssl.SSLError, ConnectionError):
        pass
    finally:
        w.close()


async def demarrer(cfg: Config, file: FileKumo):
    ctx = contexte_tls(cfg)
    return await asyncio.start_server(
        lambda r, w: _client(cfg, file, ctx, r, w), cfg.ip_ecoute, cfg.port, limit=1 << 20)


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
