# UI-BOITE — demander un nouveau code dans l'ajout de boîte (remarque de #717)

## Ce qui change
À l'étape 2 de « Ajouter une boîte », un bouton « Recevoir un nouveau code » rappelle `POST /envoi/boites/ajout/code` avec exactement la même demande qu'à l'étape 1 (adresse, hôte, port, chiffrement, utilisateur). Il n'envoie ni mot de passe, ni code.
- Le mot de passe saisi et l'ancien code sont vidés après chaque envoi, réussi ou non.
- Un 429 affiche « Patientez quelques minutes… » ; les autres erreurs gardent les textes existants (jamais le message technique du serveur en 5xx).
- Succès : « Un nouveau code est parti. L'ancien ne sert plus. »

## Tests (`AjoutBoite.test.tsx`, ajouts seuls)
Vus rouges avant le code : même charge sans secret et champs vidés ; 429 ⇒ message et mot de passe vidé. Plus : pas de bouton à l'étape 1.

## Mutation
Le mot de passe ajouté à la demande de renvoi ⇒ test rouge. Restauré.
