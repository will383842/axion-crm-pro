"""Configuration : fichier JSON dont le chemin vient de l'environnement. Aucun secret en dur."""
import base64
import hashlib
import hmac
import ipaddress
import json
import os
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


def _sans_mappee(adresse):
    """Une IPv6 mappée ::ffff:a.b.c.d vaut son IPv4 a.b.c.d (règle anti-joker et correspondance)."""
    return adresse.ipv4_mapped if adresse.version == 6 and adresse.ipv4_mapped else adresse


def _reseau_sans_mappe(reseau):
    """Réseau IPv6 situé dans ::ffff:0:0/96 -> le même réseau IPv4 (préfixe - 96)."""
    if reseau.version == 6 and reseau.prefixlen >= 96 and reseau.network_address.ipv4_mapped is not None:
        return ipaddress.ip_network((reseau.network_address.ipv4_mapped, reseau.prefixlen - 96))
    return reseau


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
    max_connexions: int = 100            # plafond de connexions simultanées
    duree_max_session: float = 600.0     # durée totale maximale d'une session (s)
    max_echecs_ip: int = 10              # échecs AUTH tolérés par IP source
    fenetre_echecs: float = 900.0
    delai_ecriture: float = 30.0         # délai max d'un drain() : un client qui ne lit pas est coupé

    def __post_init__(self):
        ip = _sans_mappee(ipaddress.ip_address(self.ip_ecoute))
        if ip.is_unspecified:
            raise ValueError("ip_ecoute ne peut pas être une adresse joker")
        self.reseaux = [_reseau_sans_mappe(ipaddress.ip_network(n)) for n in self.ips_autorisees]
        for version in (4, 6):                # réseau joker, seul ou par recouvrement (0/1 + 128/1)
            groupe = [r for r in self.reseaux if r.version == version]
            if any(r.prefixlen == 0 for r in ipaddress.collapse_addresses(groupe)):
                raise ValueError("ips_autorisees ne peut pas couvrir tout l'espace d'adresses")
        self.domaines_envoi = {normaliser_domaine(d) for d in self.domaines_envoi}
        self.domaines_interdits = {normaliser_domaine(d) for d in self.domaines_interdits}
        self.registre = {e: {a.strip().lower() for a in v} for e, v in self.registre.items()}
        # Empreinte de secours (compte inconnu) : MÊME nombre d'itérations que les vrais comptes,
        # pour que la durée ne révèle pas l'existence d'un compte.
        its = [int(c["hash"].split("$")[1]) for c in self.comptes.values()]
        self.empreinte_secours = hacher("secours", os.urandom(16), max(its) if its else 200_000)

    def ip_autorisee(self, ip: str) -> bool:
        try:
            a = _sans_mappee(ipaddress.ip_address(ip))
        except ValueError:
            return False
        return any(a in r for r in self.reseaux)

    def domaines_registre(self, espace: str) -> set:
        return {normaliser_domaine(a.rpartition("@")[2]) for a in self.registre.get(espace, set())}

    def domaine_interdit(self, d: str) -> bool:
        d = normaliser_domaine(d)
        return any(d == x or d.endswith("." + x) for x in self.domaines_interdits)

    @classmethod
    def charger(cls, chemin: str) -> "Config":
        with open(chemin, encoding="utf-8") as f:
            return cls(**json.load(f))
