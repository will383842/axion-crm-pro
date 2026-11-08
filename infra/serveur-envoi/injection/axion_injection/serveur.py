"""Écouteur asyncio : STARTTLS obligatoire, TLS 1.2 minimum. Aucun envoi réel."""
import asyncio
import os
import ssl

from .config import Config
from .file_kumo import FileIndisponible, FileKumo
from .limiteur import LimiteurEchecs
from .session import Session, verifier_python


def contexte_tls(cfg: Config) -> ssl.SSLContext:
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.minimum_version = ssl.TLSVersion.TLSv1_2
    ctx.load_cert_chain(cfg.cert, cfg.cle)
    return ctx


async def _couper(w, delai: float, abort: bool) -> None:
    """Coupe la connexion. Refus : on laisse le temps d'écrire la réponse puis abort() (aucune connexion
    ne reste ouverte, même si le client ne lit pas) ; départ normal : fermeture propre."""
    if not abort:
        w.close()
        return
    try:
        await asyncio.wait_for(w.drain(), delai)
    except Exception:
        pass
    transport = getattr(w, "transport", None)
    if transport is not None:
        transport.abort()
    else:
        w.close()


async def _client(cfg, file, ctx, limiteur, etat, r, w):
    if etat["n"] >= cfg.max_connexions:
        w.write(b"421 4.3.2 trop de connexions\r\n")
        await _couper(w, cfg.delai_ecriture, True)
        return
    loop = asyncio.get_running_loop()
    refus_coupure = False                             # vrai si la session finit sur un refus (abort)
    try:
        etat["n"] += 1                                # décrémenté dans le finally, quoi qu'il arrive
        fin_session = loop.time() + cfg.duree_max_session
        s = Session(cfg, file, w.get_extra_info("peername")[0], limiteur)
        rep, act = s.accueil()
        while True:
            if act == "starttls" and r._buffer:      # tampon non vide : pipelining interdit
                rep, act = ["503 5.5.1 commandes apres STARTTLS refusees"], "close"
                refus_coupure = True
            for l in rep:
                w.write(l.encode() + b"\r\n")
            try:
                await asyncio.wait_for(w.drain(), cfg.delai_ecriture)   # client qui ne lit pas : coupé
            except asyncio.TimeoutError:
                refus_coupure = True
                break
            if act == "close":
                refus_coupure = refus_coupure or not (rep and rep[0].startswith("221"))   # QUIT = départ normal
                break
            if act == "starttls":
                if r._buffer:       # clair arrivé pendant l'écriture (type CVE-2024-34083)
                    refus_coupure = True
                    break
                await w.start_tls(ctx)
                s.tls_etabli()
            restant = fin_session - loop.time()
            if restant <= 0:
                w.write(b"421 4.4.2 duree maximale de session atteinte\r\n")
                refus_coupure = True
                break
            try:
                brut = await asyncio.wait_for(r.readuntil(b"\n"), min(300, restant))
            except (asyncio.IncompleteReadError, asyncio.LimitOverrunError, asyncio.TimeoutError):
                break
            rep, act = s.ligne(brut, differer_auth=True)
            if act == "auth":                         # PBKDF2 hors de la boucle asyncio
                refus = s.preparer_auth()             # blocage par IP + échec réservé, dans la boucle
                if refus:
                    s._attente = None
                    rep, act = refus
                else:
                    ok = await loop.run_in_executor(None, s.calculer_auth)
                    rep, act = s.conclure_auth(ok)
            if s.delai:
                await asyncio.sleep(s.delai)
                s.delai = 0.0
    except (ssl.SSLError, ConnectionError):
        pass
    finally:
        etat["n"] -= 1
        await _couper(w, 1.0 if refus_coupure else 0.0, refus_coupure)


async def demarrer(cfg: Config, file: FileKumo):
    verifier_python()                                  # refuse de démarrer sans getaddresses(strict)
    ctx = contexte_tls(cfg)
    limiteur = LimiteurEchecs(cfg.max_echecs_ip, cfg.fenetre_echecs)   # par IP, partagé entre sessions
    etat = {"n": 0}
    return await asyncio.start_server(
        lambda r, w: _client(cfg, file, ctx, limiteur, etat, r, w), cfg.ip_ecoute, cfg.port, limit=1 << 20)


def file_production() -> FileKumo:
    """File de production : tant que KumoMTA n'est pas branché (lot ultérieur), tout dépôt lève
    ErreurFile (réponse 451). Rien n'est gardé, rien n'est « accepté »."""
    return FileIndisponible()


def principal():  # pragma: no cover
    cfg = Config.charger(os.environ["INJECTION_CONFIG"])
    file = file_production()

    async def boucle():
        srv = await demarrer(cfg, file)
        async with srv:
            await srv.serve_forever()
    asyncio.run(boucle())
