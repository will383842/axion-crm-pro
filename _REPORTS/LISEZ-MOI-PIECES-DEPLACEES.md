# Quatre pièces ont quitté ce dossier

Ce dépôt est **public**. Quatre documents de `_REPORTS/` n'y avaient pas leur
place et vivent désormais dans **`will383842/axion-crm-pro-audit`**, privé :

| pièce | pourquoi elle est partie |
|---|---|
| `REGISTRE-DES-VIOLATIONS-DE-DONNEES.md` | registre au sens de l'**article 33 §5 du RGPD** — il porte l'incident du 19/08, ses volumes, et la **décision motivée de ne pas notifier la CNIL** |
| `2026-08-19_BROUILLON-NOTIFICATION-CNIL-ART33.md` | le brouillon de notification lui-même |
| `AIPD_2026-08-18.md` | analyse d'impact relative à la protection des données |
| `2026-08-18_ETAT-PARE-FEU.md` | état du pare-feu de production |

**Rien n'est perdu** : les quatre ont été copiées, poussées et **relues une par
une** dans le dépôt privé avant tout retrait ici.

## Ce que ce retrait ne fait pas

**L'historique git de ce dépôt les conserve.** Les en purger demanderait une
réécriture d'historique — qui invaliderait toutes les références et les branches
en cours.

C'est pourquoi ce qui était **exploitable** a été traité à la source :

- **l'IP d'origine du serveur est neutralisée** — un filtre n'accepte plus, sur
  80/443, que les plages officielles de Cloudflare. La connaître ne sert plus à
  rien. *Vérifié : le site répond par Cloudflare, l'IP directe est injoignable.*
- le mot de passe Postgres publié est à tourner.

*On remplace un secret par un contrôle. Un contrôle tient même quand
l'historique parle.*
