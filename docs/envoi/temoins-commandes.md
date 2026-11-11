# Témoins d'envoi — commandes locales (F03)

Commandes : `envoi:temoin-ajouter` et `envoi:temoins-lister` (jamais par l'API, jamais par une route).
L'adresse d'un témoin n'est **jamais** un argument ni une option : elle est lue en invite masquée
ou sur l'entrée standard, puis seule son empreinte est conservée.

## Usage sûr de `envoi:temoin-ajouter`

Les exemples utilisent l'adresse fictive `temoin-1@exemple.test`. Aucune vraie adresse ne doit apparaître
dans ce dépôt public, ni dans un ticket, ni dans une PR.

1. **Saisie interactive (recommandé)** : l'invite est masquée, rien n'est affiché ni gardé.

   ```bash
   docker exec -it <conteneur-backend> php artisan envoi:temoin-ajouter <workspace> gmail --libelle="Gmail de test"
   ```

2. **`read -s`** : la variable n'est pas affichée, mais elle ne doit pas être passée en argument.

   ```bash
   read -rs ADRESSE_TEMOIN
   printf '%s\n' "$ADRESSE_TEMOIN" | docker exec -i <conteneur-backend> php artisan envoi:temoin-ajouter <workspace> gmail
   unset ADRESSE_TEMOIN
   ```

3. **Redirection depuis un fichier** réservé à l'opérateur (droits `600`, supprimé après usage) :

   ```bash
   docker exec -i <conteneur-backend> php artisan envoi:temoin-ajouter <workspace> gmail < fichier-adresse.txt
   ```

   Le fichier contient une seule ligne, par exemple `temoin-1@exemple.test` dans un essai. Le supprimer ensuite.

### À ne JAMAIS faire

```bash
echo 'temoin-1@exemple.test' | php artisan envoi:temoin-ajouter ...   # INTERDIT
```

Avec `echo 'adresse' | …`, l'adresse reste en clair dans l'historique du shell (et visible dans `ps`
selon le contexte). Même interdiction pour toute forme qui écrit l'adresse dans la ligne de commande.

## Libellé

Le libellé (`--libelle`) fait 60 caractères au plus et ne doit pas ressembler à une adresse : `@`, arobase
pleine largeur (U+FF20), « at », « arobase », « dot », et par prudence « chez » et « point » (même collés à
d'autres lettres). En cas de doute la commande refuse.

## Lister

```bash
php artisan envoi:temoins-lister <workspace-uuid>
```

Le workspace doit être un UUID valide, sinon message d'erreur et code retour non nul. Seule l'empreinte
abrégée est affichée.
