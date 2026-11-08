"""Classe un enregistrement de log KumoMTA : rebond_dur | rebond_mou | report | None (pas un echec)."""


def _statut(rec):
    e = (rec.get("response") or {}).get("enhanced_status_code") or {}
    if "class" not in e:
        return ""
    return "%s.%s.%s" % (e.get("class"), e.get("subject", 0), e.get("detail", 0))


def classifier(rec, regles):
    type_ = rec.get("type")
    if type_ not in ("Bounce", "TransientFailure", "Expiration"):
        return None
    statut = _statut(rec)
    texte = str((rec.get("response") or {}).get("content", "")).lower()
    for r in regles["regles"]:
        if r.get("type", type_) != type_:
            continue
        p, t = r.get("statut_prefixe"), r.get("contient")
        if p is not None and not (statut == p or statut.startswith(p + ".")):
            continue
        if t is not None and not any(m.lower() in texte for m in t):
            continue
        return r["classe"]
    return regles["defaut_transitoire"] if type_ == "TransientFailure" else regles["defaut_bounce"]
