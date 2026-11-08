"""Machine à états SMTP pure (sans socket) : testable ligne à ligne."""
import base64
import re

from .config import Config, normaliser_domaine, verifier
from .file_kumo import ErreurFile, FileKumo

ENTETE_RETIRE = re.compile(rb"(?i)^x-tenant[ \t]*:")
ADRESSE = re.compile(r"^<([^<>\s]+@[^<>\s@]+)>(\s.*)?$")
AVANT_TLS = {"EHLO", "HELO", "NOOP", "QUIT", "STARTTLS", "RSET"}


def retirer_x_tenant(message: bytes) -> bytes:
    """Retire l'en-tête X-Tenant fourni par le client (lignes repliées comprises)."""
    tete, sep, corps = message.partition(b"\r\n\r\n")
    sortie, saute = [], False
    for l in tete.split(b"\r\n"):
        if l[:1] in (b" ", b"\t"):
            if not saute:
                sortie.append(l)
            continue
        saute = bool(ENTETE_RETIRE.match(l))
        if not saute:
            sortie.append(l)
    return b"\r\n".join(sortie) + sep + corps


def domaine_from(message: bytes) -> str | None:
    tete = message.partition(b"\r\n\r\n")[0].decode("utf-8", "replace")
    m = re.search(r"(?im)^from:.*?@([^>\s;,]+)", tete)
    return m.group(1) if m else None


class Session:
    def __init__(self, cfg: Config, file: FileKumo, ip_pair: str):
        self.cfg, self.file, self.ip = cfg, file, ip_pair
        self.tls = False
        self.authc = None
        self.echecs = 0
        self._raz()
        self.mode = "cmd"

    def _raz(self):
        self.mail_from = self.rcpt = None
        self.data: list[bytes] = []
        self.taille = 0
        self.mode = "cmd"

    def accueil(self):
        if not self.cfg.ip_autorisee(self.ip):          # couche 1
            return ["554 5.7.1 IP non autorisee"], "close"
        return ["220 injection ESMTP"], None

    def ligne(self, brut: bytes):
        """Retourne (réponses, action) ; action ∈ {None, 'starttls', 'close'}."""
        if self.mode == "data":
            return self._lire_data(brut)
        if self.mode == "auth":
            self.mode = "cmd"
            return self._auth_creds(brut.strip().decode("ascii", "replace"))
        txt = brut.rstrip(b"\r\n").decode("utf-8", "replace")
        verbe, _, arg = txt.partition(" ")
        verbe, arg = verbe.upper(), arg.strip()
        if not self.tls and verbe not in AVANT_TLS:
            return ["530 5.7.0 STARTTLS obligatoire"], None
        m = getattr(self, "_" + verbe.lower(), None)
        if m is None or not verbe.isalpha():
            return ["502 5.5.2 commande inconnue"], None
        return m(arg)

    # --- commandes ---
    def _ehlo(self, arg):
        r = ["250-injection", "250-SIZE %d" % self.cfg.max_taille]
        r.append("250 AUTH PLAIN" if self.tls else "250 STARTTLS")
        return r, None

    _helo = _ehlo

    def _noop(self, arg):
        return ["250 2.0.0 ok"], None

    def _rset(self, arg):
        self._raz()
        return ["250 2.0.0 ok"], None

    def _quit(self, arg):
        return ["221 2.0.0 au revoir"], "close"

    def _starttls(self, arg):
        if self.tls:
            return ["503 5.5.1 TLS deja actif"], None
        return ["220 2.0.0 pret pour TLS"], "starttls"

    def tls_etabli(self):
        self.tls, self.authc = True, None
        self._raz()

    def _auth(self, arg):
        parts = arg.split()
        if self.authc:
            return ["503 5.5.1 deja authentifie"], None
        if not parts or parts[0].upper() != "PLAIN":
            return ["504 5.5.4 mecanisme non supporte"], None
        if len(parts) > 1:
            return self._auth_creds(parts[1])
        self.mode = "auth"
        return ["334 "], None

    def _auth_creds(self, b64):
        try:
            authz, authc, mdp = base64.b64decode(b64, validate=True).decode().split("\0")
        except (ValueError, UnicodeError):
            return ["501 5.5.2 AUTH mal forme"], None
        cpt = self.cfg.comptes.get(authc)
        ok = verifier(mdp, cpt["hash"]) if cpt else verifier(mdp, "pbkdf2$1$AA==$AA==")
        if not cpt or not ok or (authz and authz != authc):   # authz != authc : refus
            self.echecs += 1
            if self.echecs >= 3:
                return ["421 4.7.0 trop d'echecs"], "close"
            return ["535 5.7.8 authentification refusee"], None
        self.authc = authc
        return ["235 2.7.0 ok"], None

    def _mail(self, arg):
        if not self.authc:                                # couche 2
            return ["530 5.7.0 AUTH obligatoire"], None
        m = re.match(r"(?i)^FROM:\s*(.*)$", arg)
        a = ADRESSE.match(m.group(1)) if m else None
        if not a:
            return ["501 5.1.7 MAIL FROM invalide"], None
        adr = a.group(1).lower()
        dom = normaliser_domaine(adr.rpartition("@")[2])
        if self.cfg.domaine_interdit(dom):                # refus permanent, avant tout
            return ["550 5.7.1 domaine interdit"], None
        espace = self.cfg.comptes[self.authc]["espace"]
        if adr not in self.cfg.registre.get(espace, set()):   # couche 3
            return ["550 5.7.1 MAIL FROM hors registre"], None
        if dom not in self.cfg.domaines_envoi:            # couche 4
            return ["550 5.7.1 domaine d'envoi inconnu"], None
        self.mail_from = adr
        return ["250 2.1.0 ok"], None

    def _rcpt(self, arg):
        if not self.mail_from:
            return ["503 5.5.1 MAIL d'abord"], None
        if self.rcpt:
            return ["452 4.5.3 un seul destinataire"], None
        m = re.match(r"(?i)^TO:\s*(.*)$", arg)
        a = ADRESSE.match(m.group(1)) if m else None
        if not a:
            return ["501 5.1.3 RCPT invalide"], None
        self.rcpt = a.group(1)
        return ["250 2.1.5 ok"], None

    def _data(self, arg):
        if not (self.mail_from and self.rcpt):
            return ["503 5.5.1 MAIL/RCPT d'abord"], None
        self.mode = "data"
        return ["354 fin par <CRLF>.<CRLF>"], None

    # --- corps ---
    def _lire_data(self, brut):
        if brut == b".\r\n":
            return self._fin()
        if not brut.endswith(b"\r\n") or b"\n" in brut[:-2] or b"\r" in brut[:-2]:
            self._raz()                                   # anti « SMTP smuggling »
            return ["550 5.6.0 fin de ligne invalide"], "close"
        self.taille += len(brut)
        if self.taille <= self.cfg.max_taille:
            self.data.append(brut[1:] if brut.startswith(b"..") else brut)
        return [], None

    def _fin(self):
        msg, mf, rcpt = b"".join(self.data), self.mail_from, self.rcpt
        trop = self.taille > self.cfg.max_taille
        self._raz()
        if trop:
            return ["552 5.3.4 message trop gros"], None
        d = domaine_from(msg)
        if d and self.cfg.domaine_interdit(d):
            return ["550 5.7.1 domaine interdit dans From"], None
        msg = retirer_x_tenant(msg)
        try:
            self.file.deposer(self.cfg.comptes[self.authc]["espace"], mf, rcpt, msg)
        except ErreurFile:
            return ["451 4.3.0 file indisponible"], None
        return ["250 2.0.0 en file"], None
