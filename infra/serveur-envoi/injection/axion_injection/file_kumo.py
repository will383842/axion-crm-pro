"""Interface abstraite vers la file KumoMTA. Aucun envoi réel dans ce lot."""
from abc import ABC, abstractmethod


class ErreurFile(Exception):
    """La file est indisponible : l'écouteur répond 451 (jamais « accepté »)."""


class FileKumo(ABC):
    @abstractmethod
    def deposer(self, espace: str, mail_from: str, destinataire: str, message: bytes) -> None:
        """Dépose le message dans la file de l'espace. Lève ErreurFile si impossible."""


class FausseFile(FileKumo):
    """Double de test : garde les dépôts en mémoire."""

    def __init__(self, panne: bool = False):
        self.depots: list[dict] = []
        self.panne = panne

    def deposer(self, espace, mail_from, destinataire, message):
        if self.panne:
            raise ErreurFile("file indisponible")
        self.depots.append({"espace": espace, "mail_from": mail_from,
                            "rcpt": destinataire, "message": message})
