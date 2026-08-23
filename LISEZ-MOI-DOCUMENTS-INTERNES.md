# Les documents internes ont déménagé

`_AUDIT/`, `_REPORTS/`, `_SESSIONS/` et `_PROMPTS/` ne sont plus dans ce dépôt.

Ils vivent désormais dans **`will383842/axion-crm-pro-audit`**, qui est **privé
et doit le rester**.

## Pourquoi

Ce dépôt est **public**. Ces quatre dossiers portaient :

- le **registre des violations de données**, le **brouillon de notification
  CNIL** et l'**AIPD** — des pièces qui engagent le responsable de traitement ;
- l'audit 360 complet, qui décrit **fichier par ligne** les faiblesses d'un
  système en production ;
- l'état du pare-feu et les rapports d'infrastructure.

## ⚠️ Ce que ce déplacement ne fait pas

**L'historique git de ce dépôt les conserve.** Les en retirer vraiment
demanderait une réécriture d'historique, qui invaliderait toutes les PR, toutes
les références et les branches en cours.

C'est pourquoi les faiblesses **exploitables** ont été traitées à la source
plutôt que cachées :

- **l'IP d'origine du serveur est neutralisée** — un filtre n'accepte plus, sur
  80/443, que les plages officielles de Cloudflare. La connaître ne sert plus à
  rien. *Vérifié : le site répond par Cloudflare, l'IP directe est injoignable.*
- le mot de passe Postgres publié doit être tourné — voir le journal d'audit.

*On remplace un secret par un contrôle : c'est plus solide qu'un dépôt fermé.*
