# 06 — CE QUI REVIENT AU DIRIGEANT

> Une page. Sans redite. Chaque ligne porte une **recommandation**, pas une question ouverte.
> Tout le reste a été décidé par l'autopilote et consigné dans `05_DECISIONS.md`.
>
> **Ce qui n'est PAS ici, parce que c'est déjà tranché et que je ne le rouvre pas** :
> la rotation des secrets (**refusée**, 19/08) · la notification CNIL (**non retenue**, décision
> motivée et consignée au registre art. 33-5) · `MAIL_MAILER = log` (**ta décision**) · le multi-types
> (**remis au périmètre**) · les dossiers (**séquencés, pas reportés**).

---

## A. Deux interruptions de service à autoriser — c'est le seul obstacle

| # | Geste | Coût réel | Recommandation |
|---|---|---|---|
| **A1** | **Sortir la production du serveur de développement PHP.** Elle tourne sous `php -S`, **un seul processus** : toutes les requêtes sont **sérialisées**. Mesuré : 12 requêtes simultanées forment un escalier de 15 ms, quand les mêmes en séquentiel restent plates. | **Quelques secondes** de recréation du conteneur `api`. php-fpm est **déjà dans l'image**. | **Oui, et en premier.** Tant que ce n'est pas fait, *une seule requête lente gèle l'application pour tout le monde* — et les compteurs du hub ont été chronométrés à **17,5 s cache froid**. Le CDC exige dix utilisateurs **dès le premier jour** : aujourd'hui c'est **impossible par construction**, pas « non mesuré ». Repli en 15 min si php-fpm demande trop de travail : poser `PHP_CLI_SERVER_WORKERS=8`. |
| **A2** | **Redémarrer `api` après avoir posé `TELESCOPE_ENABLED=false`.** | Quelques secondes, **même fenêtre que A1**. | **Oui, dans le même geste.** Le journal de production pèse **270 Mo**, grossit de **~90 Mo/jour**, et **100 % de ses erreurs sont le même défaut** — une vraie erreur y passe inaperçue. C'est mécaniquement ce qui a permis à deux pannes de durer 71 h sans témoin. |

---

## B. Trois arbitrages de produit — ils ne sont pas techniques, je ne peux pas les prendre

| # | La question | Recommandation |
|---|---|---|
| **B1** | **Le CRM refuse, par conception, de copier le contenu des échanges.** Son code dit : « la timeline est un **index** des touchpoints, jamais une copie de leur contenu ». Conséquence mesurée : **13 catégories d'information** (corps des messages, réponses envoyées, notes internes, CV, heure et lieu des rendez-vous…) **ne traversent jamais** le canal. Or le **principe 10** de ton cahier des charges dit l'inverse : « une seule porte pour la journée : le CRM ». **Les deux ne peuvent pas être vrais.** | **Trancher pour le principe 10, et l'assumer** : le CRM doit porter le contenu, pas un index. Sinon l'étape 1c est impossible — **retirer les écrans du site ferait perdre l'information, pas la déplacer**. Le coût est réel (schéma, volume, RGPD) mais il est **moindre aujourd'hui** qu'après avoir construit dessus. |
| **B2** | **Le produit dépasse déjà son périmètre.** `/cold-email`, `/linkedin` et le constructeur d'audiences relèvent du lot **L7**, que le §26 de ton cahier des charges **exclut** de la première version. | **Décider s'ils restent.** S'ils restent, un ADR daté ; sinon, redirection (jamais un 404). Ce n'est pas urgent, mais c'est **la seule endroit où on a construit hors périmètre**, et ça mérite d'être su. |
| **B3** | **La séparation du courrier d'authentification et du courrier métier.** Ta décision « `MAIL_MAILER` reste `log` » **est respectée** — mais personne n'avait vu qu'elle coupait aussi le **lien magique** et la **réinitialisation de mot de passe**. C'est l'une des trois raisons pour lesquelles **personne ne s'est jamais connecté au CRM en production**. | **Autoriser le courrier d'authentification à partir, en gardant le métier en `log`.** Laravel permet de désigner un mailer par envoi : la décision est tenable **sans** exception, et sans rien envoyer à un prospect. |

---

## C. Trois choses qu'il me faut de toi pour continuer

| # | Ce qu'il me faut | Pourquoi |
|---|---|---|
| **C1** | **Comment sais-tu aujourd'hui qui rappeler ?** (Excel, Google Sheets, Gmail, carnet, mémoire — ou rien.) | Le critère de l'étape 1a est « la vue *aujourd'hui* **remplace le tableur** », et ce tableur n'a jamais été vu. Si la réponse est « rien, je me débrouille », **ce n'est pas un problème** — mais cela change la conception : *on ne remplace pas une habitude, on en crée une.* Question déjà posée le 19/08, restée sans réponse. |
| **C2** | **Deux enregistrements DNS Cloudflare** : `staging` et `staging-api`, type **A**, vers `46.62.248.239`, **Proxied**. | La préproduction existe et répond, mais Let's Encrypt échoue si le nom ne résout pas. Repli si le certificat coince : passer en **DNS only** le temps de la délivrance, puis remettre le proxy. |
| **C3** | **Le jeton du bot Telegram, l'identifiant du groupe, et ton identifiant privé** — à poser **par script**, jamais dans le chat. | Le CRM ne contient **aucun** code Telegram (deux commentaires, rien d'autre). Les alertes qui fonctionnent aujourd'hui sont celles du **site**. À demander quand la pièce arrivera — **pas avant**. |

---

## D. Un document juridique que je rédige mais ne valide pas

**La mise en balance de l'intérêt légitime, et l'information de l'article 14.**
La collecte repose sur l'article 6.1.f pour **1 319 567 personnes physiques**. La mise en balance
n'est **écrite nulle part**, et l'information prévue à l'article 14 (collecte indirecte) n'est
**jamais délivrée** — l'analyse d'impact en vigueur le dit elle-même : *« il n'est écrit nulle part »*,
*« rien dans le code ne la déclenche »*. La table du registre de l'article 30 n'est créée par **aucune
migration**.

**Recommandation** : je rédige la mise en balance et le texte d'information ; **tu les valides**
(réserve n° 5 du mandat : je rédige, je ne valide pas). C'est le seul S0 de conformité dont **un tiers
subit le préjudice sans le savoir** — et le seul qui ne se corrige pas par du code.

---

## E. Pour information — ce que j'ai fait sans te demander, et qui touche la production

Trois gestes en **lecture seule** : mesures SSH sur le serveur (ports, processus, journaux, longueur
des secrets **sans jamais afficher leur valeur**), 12 requêtes `/up` concurrentes pour établir la
sérialisation, et la **restauration d'une sauvegarde de 725 Mo dans une base jetable locale** — qui a
rendu **20 726 338 lignes au nombre exact**. Aucune écriture, aucune mutation, aucun secret modifié.

Une tentative a été **refusée par la machine et je le signale plutôt que de le taire** : j'ai voulu
déposer un fichier de test sur la production pour rendre une démonstration plus nette. `Permission
denied` — **le système de fichiers du conteneur est en lecture seule**. C'est une bonne nouvelle de
sécurité, et ce témoin-là n'a donc pas été joué : la démonstration repose sur d'autres mesures.
