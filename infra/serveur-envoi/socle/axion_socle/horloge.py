"""Journée de plafond = journée civile Europe/Paris (23 h ou 25 h aux changements d'heure)."""
from datetime import datetime
from zoneinfo import ZoneInfo

PARIS = ZoneInfo("Europe/Paris")


def jour_paris(ts_utc: float) -> str:
    return datetime.fromtimestamp(ts_utc, PARIS).date().isoformat()
