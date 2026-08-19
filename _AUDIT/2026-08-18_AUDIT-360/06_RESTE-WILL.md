# 06 — CE QUI REVIENT AU DIRIGEANT

> Une page. Sans redite. Chaque ligne porte une **recommandation**, pas une question ouverte.
> Tout le reste a été décidé par l'autopilote et consigné dans `05_DECISIONS.md`.
>
> **Ce qui n'est PAS ici, parce que c'est déjà tranché et que je ne le rouvre pas** :
> la rotation des secrets (**refusée**, 19/08) · la notification CNIL (**non retenue**, décision
> motivée et consignée au registre art. 33-5) · `MAIL_MAILER = log` (**ta décision**) · le multi-types
> (**remis au périmètre**) · les dossiers (**séquencés, pas reportés**).

---

## A. Une échéance datée, et deux interruptions de service à autoriser

| # | Geste | Coût réel | Recommandation |
|---|---|---|---|
| **A1** | **Sortir la production du serveur de développement PHP.** Elle tourne sous `php -S`, **un seul processus** : toutes les requêtes sont **sérialisées**. Mesuré : 12 requêtes simultanées forment un escalier de 15 ms, quand les mêmes en séquentiel restent plates. | **Quelques secondes** de recréation du conteneur `api`. php-fpm est **déjà dans l'image**. | **Oui, et en premier.** Tant que ce n'est pas fait, *une seule requête lente gèle l'application pour tout le monde* — et les compteurs du hub ont été chronométrés à **17,5 s cache froid**. Le CDC exige dix utilisateurs **dès le premier jour** : aujourd'hui c'est **impossible par construction**, pas « non mesuré ». Repli en 15 min si php-fpm demande trop de travail : poser `PHP_CLI_SERVER_WORKERS=8`. |
| **A0** | 🔴 **Une date, et c'est la seule de tout ce rapport : le disque de production sature vers le 6 OCTOBRE 2026.** Il se remplit de **511 Mio par jour**, il reste **25 Go**, et **aucune garde ne regarde cette trajectoire**. | Le geste immédiat est **A2** ci-dessous : ~90 Mo/jour viennent du seul défaut Telescope. | **Oui, et c'est le moins cher de la liste.** Poser `TELESCOPE_ENABLED=false` **recule la date de plusieurs semaines à lui seul**. Le reste (journaux de conteneurs sans `daemon.json`, cache de construction) se traite à froid. *Une saturation disque sur ce serveur arrête la base de données, pas seulement l'application.* |
| **A2** | **Redémarrer `api` après avoir posé `TELESCOPE_ENABLED=false`.** | Quelques secondes, **même fenêtre que A1**. | **Oui, dans le même geste.** Le journal de production pèse **270 Mo**, grossit de **~90 Mo/jour**, et **100 % de ses erreurs sont le même défaut** — une vraie erreur y passe inaperçue. C'est mécaniquement ce qui a permis à deux pannes de durer 71 h sans témoin. |

---

## B. Quatre arbitrages de produit — ils ne sont pas techniques, je ne peux pas les prendre

| # | La question | Recommandation |
|---|---|---|
| **B1** | **Le CRM refuse, par conception, de copier le contenu des échanges.** Son code dit : « la timeline est un **index** des touchpoints, jamais une copie de leur contenu ». Conséquence mesurée : **13 catégories d'information** (corps des messages, réponses envoyées, notes internes, CV, heure et lieu des rendez-vous…) **ne traversent jamais** le canal. Or le **principe 10** de ton cahier des charges dit l'inverse : « une seule porte pour la journée : le CRM ». **Les deux ne peuvent pas être vrais.** | **Trancher pour le principe 10, et l'assumer** : le CRM doit porter le contenu, pas un index. Sinon l'étape 1c est impossible — **retirer les écrans du site ferait perdre l'information, pas la déplacer**. Le coût est réel (schéma, volume, RGPD) mais il est **moindre aujourd'hui** qu'après avoir construit dessus. |
| **B1 bis** | **« Quand la donnée n'arrive pas, on affiche zéro. »** Ce n'est pas un bug : c'est une **convention écrite** dans le code (`TopDeptsCard.tsx:15` : « *si l'endpoint renvoie 404/500 ou rien, on tombe sur `EmptyState`* »). Conséquence mesurée : **23 écrans sur 30 rendent un texte identique au caractère près** selon que la base est vide ou que la requête a planté, **19 affirment « 0 » ou « aucun »**, et **37 sur 37 ne distinguent pas un refus de droits d'une panne**. Au bout de la chaîne, `/console/arbitrage` **énonce « Tous les événements entrants ont trouvé leur entreprise »** — *phrase toujours fausse, et rassurante*. | **Trancher contre la convention.** Un opérateur doit pouvoir distinguer « il n'y a rien » de « je n'ai pas pu regarder » : c'est la différence entre une base vide et une panne, et c'est **exactement** ce qui a permis à deux pannes de durer 71 h sans témoin. Coût : un état d'erreur par écran, mécanique. |
| **B2** | **Le produit dépasse déjà son périmètre.** `/cold-email`, `/linkedin` et le constructeur d'audiences relèvent du lot **L7**, que le §26 de ton cahier des charges **exclut** de la première version. | **Décider s'ils restent.** S'ils restent, un ADR daté ; sinon, redirection (jamais un 404). Ce n'est pas urgent, mais c'est **la seule endroit où on a construit hors périmètre**, et ça mérite d'être su. |
| **B3** | **La séparation du courrier d'authentification et du courrier métier.** Ta décision « `MAIL_MAILER` reste `log` » **est respectée** — mais personne n'avait vu qu'elle coupait aussi le **lien magique** et la **réinitialisation de mot de passe**. C'est l'une des trois raisons pour lesquelles **personne ne s'est jamais connecté au CRM en production**. | **Autoriser le courrier d'authentification à partir, en gardant le métier en `log`.** Laravel permet de désigner un mailer par envoi : la décision est tenable **sans** exception, et sans rien envoyer à un prospect. |

---

## C. Quatre choses qu'il me faut de toi pour continuer

| # | Ce qu'il me faut | Pourquoi |
|---|---|---|
| **C0** | **Installer l'autorité racine locale de Caddy dans le magasin Windows**, une seule fois :<br>`docker cp axion-crm-caddy:/data/caddy/pki/authorities/local/root.crt .`<br>puis import dans « Autorités de certification racines de confiance ». | Chrome refuse `https://app.localhost` faute de cette autorité. **Sans elle, aucun agent ne peut exécuter le §11 du mandat** — ouvrir les 37 écrans à la main, dans un vrai navigateur, à l'état nominal. Un agent a **refusé de l'installer lui-même** : c'est une modification permanente de la sécurité de ta machine, elle t'appartient. Il a contourné par un conteneur temporaire, retiré depuis — mais un contournement ne vaut pas pour l'état nominal. |
| **C1** | **Comment sais-tu aujourd'hui qui rappeler ?** (Excel, Google Sheets, Gmail, carnet, mémoire — ou rien.) | Le critère de l'étape 1a est « la vue *aujourd'hui* **remplace le tableur** », et ce tableur n'a jamais été vu. Si la réponse est « rien, je me débrouille », **ce n'est pas un problème** — mais cela change la conception : *on ne remplace pas une habitude, on en crée une.* Question déjà posée le 19/08, restée sans réponse. |
| **C2** | **Deux enregistrements DNS Cloudflare** : `staging` et `staging-api`, type **A**, vers `46.62.248.239`, **Proxied**. | La préproduction existe et répond, mais Let's Encrypt échoue si le nom ne résout pas. Repli si le certificat coince : passer en **DNS only** le temps de la délivrance, puis remettre le proxy. |
| **C3** | **Le jeton du bot Telegram, l'identifiant du groupe, et ton identifiant privé** — à poser **par script**, jamais dans le chat. | Le CRM ne contient **aucun** code Telegram (deux commentaires, rien d'autre). Les alertes qui fonctionnent aujourd'hui sont celles du **site**. À demander quand la pièce arrivera — **pas avant**. |

---

## C bis. 🟠 Une question hors CRM, et je la pose sans accusation : la certification Qualiopi

**Les faits mesurés, et rien d'autre.** Le dépôt du site porte un drapeau
`QUALIOPI_CERTIFICATION_OBTENUE`, dont le **défaut est `false`**, et dont les commentaires disent
explicitement « tant que le certificat n'est [pas délivré] » (`src/lib/vcard/index.ts:30`,
`Dockerfile:131-137`). L'agent 34 a mesuré que **la variable GitHub vaut `true` depuis le 10/08**,
que **l'image déployée porte `true`**, et que **la page publique affirme la certification**.

**Deux lectures, et je ne peux pas trancher entre elles — seul toi le peux :**

1. **Tu as obtenu la certification le 10 août.** Alors tout est en ordre : le drapeau dit vrai, et ce
   sont **les commentaires du code qui sont périmés** — il faut les corriger, c'est tout, et cette
   ligne disparaît.
2. **Le certificat n'est pas détenu.** Alors une page publique affirme une certification qui n'existe
   pas, et Qualiopi n'est pas un label décoratif : c'est une certification encadrée, opposable, et
   dont l'affichage indu se sanctionne.

**Ce que je sais** : le dépôt dit `false`, le déploiement dit `true`, et **un commit du dépôt écrit,
le jour même, que le certificat n'est pas délivré**. **Ce que je ne sais pas** : lequel des deux a
raison — *c'est un fait d'entreprise, pas un fait de code*, et aucune mesure ne me le donnera.

**Recommandation** : vérifie l'état réel en une minute, puis **aligne les deux**. Si le certificat est
détenu, corrige les commentaires ; sinon, `QUALIOPI_CERTIFICATION_OBTENUE=false` dans Coolify — le
`Dockerfile:243` indique que l'effet est **immédiat en 30 s** pour ce drapeau-là. *Je le signale parce
qu'un audit qui voit un écart entre ce qu'un dépôt affirme et ce qu'un site public déclare doit le
dire, même quand la réponse ne lui appartient pas.*

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

---

## F. 🔴 Le geste que je n'ai pas fait, et qui t'appartient : publier ce dossier

**Le dossier d'audit est committé, sur la branche `audit/360-p1-p2` (`8db8229`, 565 fichiers).
Je ne l'ai PAS poussé.**

`will383842/axion-crm-pro` est **PUBLIC** (mesuré, pas supposé). Et ce dossier réunit, en un document
unique, vérifié et daté, **douze défauts S0 actuellement ouverts** sur une production vivante qui
porte les données personnelles de **1 319 567 personnes** : comment **forger une signature acceptée**
et pourquoi elle passe · que `GET /audit-logs` rend le journal **de tous les espaces à tout compte
authentifié** · que la chaîne d'audit est **tronquable sans détection** · qu'un compte `viewer`
**supprime définitivement** une entreprise · que le mot de passe Postgres est **celui du dépôt public**
et que le mécanisme en place **empêche de le corriger**. Plus l'adresse de production, les noms de
conteneurs et la topologie.

**Le pousser aujourd'hui, c'est publier un mode d'emploi d'attaque qui fonctionne.**

Ce n'est pas une lecture extensive du mandat : le §1 me défend d'abord de porter atteinte aux données
de production, et publier le moyen d'y accéder est du même ordre que d'y toucher. **Et tu as déjà
tranché ce cas exact, dans ce sens** : le 19/08, la poussée de la branche a été retenue parce que
« le dépôt est PUBLIC et ces commits réunissent un mode d'emploi vérifié d'une faille **alors
ouverte** », puis relâchée une fois le trou fermé — *« ce qui est publié décrit un trou fermé »*.
Je n'ai fait qu'appliquer ta règle à un dossier bien plus lourd.

**Rien n'est perdu** : le commit existe en local, le travail est sous historique, et le dossier est
lisible dans `_AUDIT/2026-08-18_AUDIT-360/`.

| Option | Ce que ça donne | |
|---|---|---|
| **1. Rendre le dépôt privé, puis pousser** | Historique, collaboration et confidentialité, tout à la fois. Un dépôt qui porte le code d'un CRM contenant 1,3 M de personnes n'a de toute façon pas de raison d'être public. | ✅ **Ma recommandation** |
| **2. Pousser après correction des S0** | Ce qui est publié décrit alors des trous **fermés** — le cas normal d'un correctif de sécurité, et exactement ta règle du 19/08. | Bon, mais **retarde de plusieurs jours** la mise à l'abri du dossier. |
| **3. Garder le dossier hors du dépôt** | Statu quo. | ❌ **Déconseillé** : c'est précisément le défaut `A06-010` — un document qui fait foi, qu'aucun historique ne protège, et qui peut changer sans que personne sache quelle version a été jouée. |

**Un seul geste m'est nécessaire pour continuer** : ta réponse sur cette option. Tout le reste de
l'audit avance sans elle.
