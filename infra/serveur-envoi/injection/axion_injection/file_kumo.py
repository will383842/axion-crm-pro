"""Interface abstraite vers la file KumoMTA. Aucun envoi réel dans ce lot."""
from abc import ABC, abstractmethod


# K7 (ajout seul) : meta ecrite sur chaque message a l'injection, relue par le log hook (loghook.lua, `meta = {...}`).
# `espace` = l'espace du COMPTE authentifie (jamais celui d'un en-tete du client) : le hook ne melange ainsi jamais deux espaces.
META_LOGHOOK = ("id_injection", "espace")


def meta_depot(espace: str, id_injection: str) -> dict:
    """Meta a poser sur le message lors du depot dans KumoMTA (le jeton K6 s'y ajoute plus tard)."""
    return {"espace": espace, "id_injection": id_injection}


class ErreurFile(Exception):
    """La file est indisponible : l'écouteur répond 451 (jamais « accepté »)."""


class FileKumo(ABC):
    @abstractmethod
    def deposer(self, espace: str, mail_from: str, destinataire: str, message: bytes) -> None:
        """Dépose le message dans la file de l'espace. Lève ErreurFile si impossible."""


class FileIndisponible(FileKumo):
    """File de production tant que KumoMTA n'est pas branché : refuse toujours (451), ne garde rien."""

    def deposer(self, espace, mail_from, destinataire, message):
        raise ErreurFile("KumoMTA non branché")


class FausseFile(FileKumo):
    """Double de test UNIQUEMENT (jamais en production) : garde les dépôts en mémoire."""

    def __init__(self, panne: bool = False):
        self.depots: list[dict] = []
        self.panne = panne

    def deposer(self, espace, mail_from, destinataire, message):
        if self.panne:
            raise ErreurFile("file indisponible")
        self.depots.append({"espace": espace, "mail_from": mail_from,
                            "rcpt": destinataire, "message": message,
                            "meta": meta_depot(espace, "inj-%d" % (len(self.depots) + 1))})
