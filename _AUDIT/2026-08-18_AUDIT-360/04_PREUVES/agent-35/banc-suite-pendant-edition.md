# Une suite complète jouée pendant que des agents éditent l'arbre ne prouve rien

**Constaté le 2026-08-21**, sur moi-même.

## Ce que j'ai fait, et pourquoi c'était faux

J'ai lancé la suite `tests/Feature` complète (37 minutes sur un seul lot) **pendant
que six agents réparateurs éditaient le même worktree**. Le dossier `backend/` est
un **montage BIND** sur le conteneur `a35r` : le conteneur ne voit pas une copie
figée de l'arbre, il voit l'arbre **vivant**.

PHPUnit charge ses fichiers de test **au fur et à mesure** de la campagne, et le
code applicatif est chargé à la demande par l'autoloader. Un fichier édité à la
minute 20 est donc lu dans son état de la minute 20, pendant que les dix-neuf
premières minutes ont mesuré l'état de la minute 0.

**Le résultat n'est l'état d'aucun instant.** Il ne correspond à aucun commit,
donc il ne prouve rien — ni le vert, ni le rouge. Un vert obtenu ainsi est
exactement le genre de vert que ce dossier passe son temps à dénoncer chez les
autres.

## Ce que ça vaut quand même

Un **rouge** reste un signal utile : il désigne un endroit à regarder. Mais il ne
peut pas être imputé sans être rejoué au calme, parce qu'il peut venir d'un
fichier à moitié écrit.

Un **vert**, lui, ne vaut rien du tout : il peut avoir mesuré l'ancien code d'un
fichier réparé, ou le nouveau code d'un fichier dont la garde n'a pas encore été
relue.

## La règle qui en sort

> **La suite de vérification se joue dans une fenêtre CALME.**
> Aucun agent n'édite le worktree pendant qu'elle tourne. On attend que la vague
> soit rendue, on gèle, on joue, on commite. Puis on relance la vague suivante.

C'est un coût réel — la vague suivante ne peut plus recouvrir la vérification de
la précédente — et c'est le prix de la seule chose qu'on vend ici : une mesure
dont on peut dire de quel état elle parle.

## Le cas particulier qui l'a rendu visible

Au milieu de ce même passage, j'ai écarté `/var/www/.github` du banc pour prouver
qu'un témoin de montage savait rougir — **alors que la suite complète tournait et
lit cet arbre**. Deux minutes d'absence. Si `NeDoitPasRegresserTest` est passé
pendant cette fenêtre, il a rougi de mon fait et non du produit.

Le témoin que je venais d'ajouter dit exactement cela dans son message d'échec
(« l'arbre n'est pas visible depuis le banc … la rafraîchir par `tar -c …` »),
donc le faux rouge s'explique tout seul au lieu d'envoyer chercher un défaut
inexistant. C'était le but du témoin ; il aura servi à son auteur avant tout le
monde.
