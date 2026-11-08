# Dette nommée — journal d'audit chaîné des oppositions (I3, audit données #443)

**État** : aucun journal chaîné n'existe dans `backend/app/Envoi/`. `envoi_journal_commandes`
est un journal de commandes en ajout seul, SANS chaînage de hachages (pas de `prev_hash`) ;
y inscrire les oppositions n'apporterait pas l'infalsifiabilité demandée. Le seul journal
chaîné du dépôt est `audit_logs` (hors du module Envoi).

**Dette** : inscrire chaque opposition (empreinte seule, canal, date) dans un journal
chaîné. À traiter dans un lot dédié : soit étendre `envoi_journal_commandes` d'un
chaînage (migration en AJOUTS SEULS), soit écrire dans `audit_logs`, après décision du
responsable du projet. D'ici là, la trace de l'opposition est `envoi_oppositions`
(ajout seul par triggers, RLS FORCE) et l'événement sortant `consent_optout`.
