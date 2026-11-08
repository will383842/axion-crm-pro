"""Requête signée du contrat v2, exactement comme backend/app/Envoi/Serveur/SignatureEnvoi.php (chargeRequete)
et VerificateurHmacEnvoi.php : « METHODE \\n chemin \\n horodatage \\n nonce \\n workspace_id \\n corps »,
HMAC-SHA256 hexadécimal, en-tête « k=<id>,sha256=<hex> » (formes « sha256=<hex> » et hex nu lues aussi).
Vecteurs communs : envoi-serveur/contrat/vecteurs-hmac.json.
"""
import hashlib
import hmac
import re

FENETRE_SECONDES = 300
NONCE_SECONDES = 600  # un nonce reste gardé 2 fenêtres : jamais rejouable pendant qu'il est encore valide
_UUID = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}")
_ENTETE = re.compile(r"k=[A-Za-z0-9_-]{1,32},(.*)", re.S)
_HORODATAGE = re.compile(r"[0-9]{1,12}")


def est_uuid(valeur) -> bool:
    return isinstance(valeur, str) and _UUID.fullmatch(valeur) is not None


def charge_requete(methode: str, chemin: str, horodatage: str, nonce: str, workspace_id: str, corps: str) -> bytes:
    return "\n".join([methode.upper(), chemin, horodatage, nonce, workspace_id, corps]).encode()


def _signature_recue(entete) -> str:
    entete = entete if isinstance(entete, str) else ""
    m = _ENTETE.fullmatch(entete)
    recu = m.group(1) if m else entete
    return recu[7:] if recu.startswith("sha256=") else recu


def verifier(secret: bytes, methode, chemin, horodatage, nonce, workspace_id, corps, entete, maintenant: float,
             fenetre: int = FENETRE_SECONDES) -> bool:
    """True = signature valide ET horodatage dans la fenêtre. Le nonce se consomme ailleurs, APRÈS ce contrôle."""
    if not secret or not est_uuid(nonce) or not est_uuid(workspace_id):
        return False
    if not all(isinstance(x, str) for x in (methode, chemin, horodatage, corps)):
        return False
    if _HORODATAGE.fullmatch(horodatage) is None or abs(maintenant - int(horodatage)) > fenetre:
        return False
    recu = _signature_recue(entete)
    if not recu or not recu.isascii():
        return False
    attendu = hmac.new(secret, charge_requete(methode, chemin, horodatage, nonce, workspace_id, corps),
                       hashlib.sha256).hexdigest()
    return hmac.compare_digest(attendu, recu)


def signer_reponse(secret: bytes, nonce: str, workspace_id: str, statut: int, corps: str) -> str:
    """Signature de RÉPONSE du contrat v2 : HMAC-SHA256 hex de « REPONSE\\nnonce\\nworkspace_id\\nstatut\\ncorps »
    (le nonce est celui de la requête). Secret vide => chaîne vide (rien n'est signé). Vecteurs : `reponses`."""
    if not secret:
        return ""
    charge = "\n".join(["REPONSE", nonce, workspace_id, str(int(statut)), corps]).encode()
    return hmac.new(secret, charge, hashlib.sha256).hexdigest()
