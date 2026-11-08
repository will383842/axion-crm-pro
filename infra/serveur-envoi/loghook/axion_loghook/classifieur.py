"""Classe un enregistrement de log KumoMTA : rebond_dur | rebond_mou | report | inconnu | alerte_config | None.

None = pas un echec (Delivery, Reception). Un Bounce sans aucune regle n'est PAS un rebond dur : « inconnu » (journalise).
Format reel du journal KumoMTA : response.enhanced_code = {class, subject, detail} (l'ancien nom
`enhanced_status_code` est encore lu, par prudence, mais ce n'est pas le nom ecrit par KumoMTA).
"""


def _statut(rec):
    rep = rec.get("response") or {}
    e = rep.get("enhanced_code") or rep.get("enhanced_status_code") or {}
    if not isinstance(e, dict) or "class" not in e:
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
