# L8-ESSAI — essai à blanc de bout en bout (D10)

Commande locale `php artisan envoi:essai-a-blanc {espace} --auteur=` — **aucune route**. Données fictives (`*.example.test`), faux serveur, aucun envoi réel.

## Fichiers
`app/Envoi/Orchestration/EssaiABlanc.php`, `RapportEssai.php`, `app/Console/Commands/EnvoiEssaiABlanc.php`, `app/Providers/Envoi/OrchestrationServiceProvider.php` (liaison), tests `EssaiABlancTest.php` et `EssaiABlancSansReseauTest.php`.

## Parcours (dans UNE transaction annulée à la fin)
**Isolement** : le parcours se fait dans un ESPACE FICTIF créé dans la transaction (annulé avec elle) ; les vraies inscriptions de l'espace demandé ne sont ni comptées, ni traitées par le séquenceur, ni composées, ni modifiées, ni reportées (même avec 100 inscriptions échues ou une adresse témoin). La ligne de résultat est écrite après l'annulation, rattachée à l'espace réel demandé.

Jour fictif : lundi 2 mars 2099 (horloge injectée, le verrou consultatif du vrai jour n'est jamais pris). Quatre inscriptions fictives, plafond du jour fixé à 2.

| Étape | Preuve |
|---|---|
| `inscription` | 3 inscriptions actives |
| `composition` | message composé, Message-ID écrit dans `envoi_messages_envoyes` |
| `injection` | 2 injections sur le faux serveur |
| `plafond` | la 3e est reportée, puis part le jour suivant sans dépasser le plafond |
| `rebond_dur` | DSN 5.1.1 relu par la vraie `ReleveImap` (Message-ID retrouvé) ⇒ adresse supprimée |
| `stop` | réponse STOP relue ⇒ opposition ; l'étape 2 des deux adresses n'est jamais injectée |
| `arret_general` | « tout arrêter » armé ⇒ aucune injection, même pour une inscription neuve |

Seule la ligne de résultat (`envoi_essais_blanc` : étapes vert/rouge, sans adresse) est écrite après l'annulation. Étape rouge ⇒ code de sortie non nul. Exception non rattrapée par une étape : ligne rouge écrite quand même (étapes non atteintes en rouge, sans valeur ni adresse), la commande sort en code non nul avec un message propre. `--auteur` contenant un `@` est refusé avant tout essai.

## Garde-fous
- Chaîne construite par l'essai : `FauxServeurEnvoi` créé **après** l'ouverture de la transaction ; jamais le `ServeurEnvoi` du conteneur, ni `RecepteurRetoursCrm` (cache, fichier temporaire, espace commercial) ; récepteurs propres à l'essai (opposition + rebond dur dans la transaction) puis `RecepteurRetoursSequence`.
- Porte « envoi réel » ouverte **seulement** pour la passerelle locale de l'essai, par une fermeture ; `config('envoi.reel_actif')` n'est jamais modifiée (reste `false`).
- `Http::preventStrayRequests()` pendant tout l'essai ; aucune écriture de cache ; aucun courriel : `Alerte` étant finale, l'essai passe à la relève un exemplaire sans canaux (jamais l'alerte du conteneur).
- Comptage avant/après de toutes les tables `envoi_*` (sauf le résultat), `contacts`, `personnes`, `email_suppressions`, `opt_out`, `crm_outbound_events`, `companies` : identique.
- Les maillons freins (DNS) et réponses (chiffrement) de la chaîne réelle ne sont pas rejoués : ils ne sont pas nécessaires au parcours et le premier résout un fournisseur par DNS.
- Exigence : la clé et les hôtes de désinscription (`envoi.cle_desinscription`, `envoi.desinscription_hotes`) doivent être configurés, sinon l'étape `composition` est rouge.

## Reste
- L'espionne de flux des tests ne couvre que les schémas `http` et `https` (pas les autres flux).
- Aucun résolveur MX n'est rejoué (les maillons DNS de la chaîne réelle ne sont pas exercés).
