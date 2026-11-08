"""Empreinte d'une adresse témoin : MÊME règle que backend/app/Envoi/EmpreinteTemoin.php.

Normalisation : minuscules, espaces retirés, domaine (après le dernier « @ ») sans point final et en punycode.
Empreinte : HMAC-SHA256(adresse normalisée, clé d'empreinte) en hexadécimal minuscule. Le socle ne reçoit et ne
garde que des empreintes, jamais d'adresse témoin en clair. Vecteurs partagés avec le CRM :
envoi-serveur/contrat/vecteurs-empreintes-temoins.json (testés des deux côtés).
"""
import hashlib
import hmac
import re

EMPREINTE_RE = re.compile(r"[0-9a-f]{64}")
MAX_TEMOINS = 10


def normaliser(adresse: str) -> str:
    adresse = re.sub(r"\s+", "", str(adresse).lower())
    i = adresse.rfind("@")
    if i < 0:
        return adresse
    domaine = adresse[i + 1:].rstrip(".")
    try:
        domaine = domaine.encode("idna").decode("ascii") if domaine else domaine
    except UnicodeError:
        pass  # IDNA impossible : inchangé (comme idn_to_ascii() === false côté PHP)
    return adresse[:i + 1] + domaine


def empreinte_temoin(adresse: str, cle: bytes) -> str | None:
    """None si la clé est vide : aucune empreinte, donc aucune adresse ne peut figurer sur la liste (fermé)."""
    if not cle:
        return None
    return hmac.new(cle, normaliser(adresse).encode(), hashlib.sha256).hexdigest()


def liste_valide(liste) -> bool:
    return (isinstance(liste, list) and len(liste) <= MAX_TEMOINS
            and all(isinstance(e, str) and EMPREINTE_RE.fullmatch(e) for e in liste))
