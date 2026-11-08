"""Configuration : fichier JSON dont le chemin vient de l'environnement. Aucun secret en dur."""
import base64
import hashlib
import hmac
import ipaddress
import json
from dataclasses import dataclass, field


def hacher(mot_de_passe: str, sel: bytes, iterations: int = 200_000) -> str:
    h = hashlib.pbkdf2_hmac("sha256", mot_de_passe.encode(), sel, iterations)
    return f"pbkdf2${iterations}${base64.b64encode(sel).decode()}${base64.b64encode(h).decode()}"


def verifier(mot_de_passe: str, empreinte: str) -> bool:
    try:
        _, it, sel, _ = empreinte.split("$")
        attendu = hacher(mot_de_passe, base64.b64decode(sel), int(it))
    except (ValueError, TypeError):
        return False
    return hmac.compare_digest(attendu, empreinte)


def normaliser_domaine(d: str) -> str:
    """Casse, point final, IDN -> punycode."""
    d = d.strip().rstrip(".").lower()
    try:
        return d.encode("idna").decode("ascii")
    except UnicodeError:
        return d


@dataclass
class Config:
    ip_ecoute: str                      # IP du tunnel, jamais joker
    port: int = 2525
    cert: str = ""
    cle: str = ""
    ips_autorisees: list = field(default_factory=list)   # couche 1
    comptes: dict = field(default_factory=dict)          # couche 2 : authc -> {hash, espace}
    registre: dict = field(default_factory=dict)         # couche 3 : espace -> [MAIL FROM]
    domaines_envoi: set = field(default_factory=set)     # couche 4
    domaines_interdits: set = field(default_factory=set)
    max_taille: int = 10_000_000

    def __post_init__(self):
        ip = ipaddress.ip_address(self.ip_ecoute)
        if ip.is_unspecified:
            raise ValueError("ip_ecoute ne peut pas être une adresse joker")
        self.reseaux = [ipaddress.ip_network(n) for n in self.ips_autorisees]
        self.domaines_envoi = {normaliser_domaine(d) for d in self.domaines_envoi}
        self.domaines_interdits = {normaliser_domaine(d) for d in self.domaines_interdits}
        self.registre = {e: {a.strip().lower() for a in v} for e, v in self.registre.items()}

    def ip_autorisee(self, ip: str) -> bool:
        try:
            a = ipaddress.ip_address(ip)
        except ValueError:
            return False
        return any(a in r for r in self.reseaux)

    def domaine_interdit(self, d: str) -> bool:
        d = normaliser_domaine(d)
        return any(d == x or d.endswith("." + x) for x in self.domaines_interdits)

    @classmethod
    def charger(cls, chemin: str) -> "Config":
        with open(chemin, encoding="utf-8") as f:
            return cls(**json.load(f))
