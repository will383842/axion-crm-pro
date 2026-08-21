<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * VERROUILLAGE OPTIMISTE — OPTIONNEL, ET C'EST LE POINT.
 *
 * 🔴 CONSTAT G43-005 (S0), mesure sur ce depot le 2026-08-20.
 *
 * `CompaniesController::update` faisait `$company->update($validated)` sur ce que
 * la resolution de route avait charge, sans jamais comparer avec l'etat que le
 * client croyait modifier. Deux commerciaux ouvrent la meme fiche, enregistrent
 * chacun leur formulaire : les DEUX recoivent 200, et la premiere saisie a
 * disparu. Personne n'est averti. C'est mesure par
 * `tests/Feature/EditionConcurrenteTest.php` (« TEMOIN de compatibilite »), qui
 * reste vert apres correction parce que ce comportement-la ne change pas.
 *
 * ── POURQUOI OPTIONNEL PLUTOT QU'IMPOSE ──────────────────────────────────────
 *
 * Imposer un jeton de version rendrait 409 a tout client qui n'en envoie pas :
 * ce serait un changement de contrat sur les routes PUT, pas un correctif. Le
 * mecanisme est donc ACTIVE PAR LE CLIENT :
 *
 *   - il envoie `If-Match: "<jeton>"`, ou `updated_at` dans le corps → on verifie ;
 *   - il n'envoie rien                                              → comportement
 *     strictement inchange (200, dernier arrive gagne).
 *
 * Autrement dit : le client qui veut etre protege le demande, et il est le seul
 * a pouvoir recevoir un 409. Aucun appelant existant ne casse.
 *
 * ── POURQUOI DEUX FORMES DE JETON, ET LAQUELLE VAUT MIEUX ────────────────────
 *
 * `updated_at` est le jeton que l'audit nomme, et le plus simple a produire cote
 * client. Il a DEUX DEFAUTS, tous deux MESURES sur ce depot le 2026-08-20 — pas
 * supposes, et le premier m'a fait ecrire une comparaison fausse avant de le
 * mesurer.
 *
 * 1. CE N'EST PAS LARAVEL QUI ECRIT CETTE COLONNE. Un trigger Postgres le fait :
 *
 *        CREATE TRIGGER companies_updated_at BEFORE UPDATE ON public.companies
 *        FOR EACH ROW EXECUTE FUNCTION trg_set_updated_at()   -- NEW.updated_at = now()
 *
 *    Or `now()` en Postgres est l'heure de DEBUT DE TRANSACTION, pas l'instant de
 *    l'instruction. Deux ecritures dans une MEME transaction portent donc le MEME
 *    `updated_at`, a la microseconde pres. En production chaque requete d'API est
 *    sa propre transaction, donc deux clients concurrents obtiennent bien deux
 *    valeurs differentes ; mais deux ecritures groupees dans une transaction sont
 *    indiscernables pour ce jeton. Le test « MEME TRANSACTION » le mesure.
 *
 * 2. LES DEUX SOURCES N'ONT PAS LA MEME PRECISION. A l'INSERT, Laravel ecrit la
 *    colonne lui-meme, au format `Y-m-d H:i:s` (`Grammar::getDateFormat()`, non
 *    surcharge par le pilote pgsql) : la valeur est tronquee a la seconde. A
 *    l'UPDATE, c'est le trigger, avec ses microsecondes. Comparer a la seconde —
 *    ce que faisait la premiere version de ce fichier — confondait donc
 *    « 15:08:10.000000 » (etat lu) et « 15:08:10.614374 » (etat d'apres) une fois
 *    sur deux, et laissait passer la perte. La comparaison se fait desormais a la
 *    PRECISION QUE LE CLIENT A FOURNIE : autant de decimales qu'il en envoie.
 *    Ainsi un client qui renvoie la valeur entiere obtient la sensibilite
 *    maximale, et un client qui la tronque (une date JavaScript, par exemple)
 *    n'obtient pas un 409 perpetuel qu'il ne saurait pas resoudre.
 *
 * `If-Match` porte donc un jeton FORT : une empreinte du CONTENU de la ligne
 * (toutes ses colonnes persistees), pas une date. Il ne depend ni de l'horloge,
 * ni du decoupage en transactions, ni de la precision du client. C'est la forme a
 * recommander. Garde : le test « MEME TRANSACTION » verifie que `updated_at` n'a
 * PAS bouge entre deux etats differents, et que le conflit est tout de meme vu.
 *
 * ⚠️ OU LE CLIENT PREND-IL SON JETON. Dans l'en-tete `ETag` d'une reponse 200, ou
 * dans le `updated_at` d'un GET. SURTOUT PAS dans le corps d'une reponse a un
 * PUT : ce corps est le modele EN MEMOIRE, et son `updated_at` est celui que
 * Laravel croit avoir ecrit, pas celui que le trigger a pose. Mesure : corps
 * « 15:08:11.000000 », base « 15:08:10.614374 ». C'est un defaut anterieur a ce
 * lot, laisse en l'etat (changer ce corps changerait ce que voit l'appelant) et
 * signale dans le rapport ; l'en-tete `ETag`, lui, est calcule sur une RELECTURE.
 *
 * ── FAIL-CLOSED, COMME LE RESTE DU DEPOT ─────────────────────────────────────
 *
 * Un jeton illisible, corrompu par un cache ou un proxy, ou impossible a
 * comparer (modele sans horodatage) est traite comme un CONFLIT, pas comme une
 * absence de jeton. Un controle qui, faute de savoir, repond « oui » est le
 * motif deja corrige dans `ApiController::estDeMonEspace` (F37-001) : on ne le
 * reintroduit pas ici. Le client relit et rejoue — il ne perd rien.
 */
trait VerrouOptimiste
{
    /**
     * Le jeton fort de l'etat COURANT d'un enregistrement.
     *
     * Empreinte des attributs PERSISTES (`getAttributes()` rend les valeurs
     * brutes de la ligne, pas les relations ni les accesseurs). `ksort` parce
     * que l'ordre des colonnes rendu par le pilote n'est pas un contrat : sans
     * lui, le meme etat pourrait produire deux jetons differents.
     *
     * Format : guillemets doubles, comme un ETag fort (RFC 9110 §8.8.3).
     * `If-Match` exige une comparaison forte, donc pas de prefixe `W/`.
     */
    protected function jetonDeVersion(Model $modele): string
    {
        $attributs = $modele->getAttributes();
        ksort($attributs);

        // 32 hexadecimaux suffisent : on compare des egalites, on ne resiste pas
        // a un adversaire — un client ne gagne rien a forger le jeton de l'etat
        // qu'il est en train d'ecrire.
        return '"' . substr(hash('sha256', (string) json_encode($attributs)), 0, 32) . '"';
    }

    /**
     * Refuse en 409 si le client a annonce un etat qui n'est plus celui de la base.
     *
     * Ne fait RIEN si le client n'annonce aucun etat : c'est la promesse de
     * compatibilite.
     */
    protected function refuserSiVersionPerimee(Request $r, Model $modele): void
    {
        $ifMatch = trim((string) $r->header('If-Match', ''));

        if ($ifMatch !== '') {
            // `*` signifie « n'importe quel etat, pourvu que la ressource
            // existe » (RFC 9110). Elle existe : la resolution de route l'a
            // trouvee. C'est une renonciation explicite du client.
            if ($ifMatch === '*') {
                return;
            }

            $courant = $this->jetonDeVersion($modele);

            // L'en-tete accepte une LISTE. Un client qui a plusieurs etats
            // acceptables en envoie plusieurs ; il suffit qu'un seul corresponde.
            foreach (explode(',', $ifMatch) as $candidat) {
                if (trim(trim($candidat), '"') === trim($courant, '"')) {
                    return;
                }
            }

            $this->refuserConflitDeVersion($modele);
        }

        // Forme degradee : l'horodatage lu, renvoye dans le corps. Elle n'est
        // examinee que si le client l'envoie EXPLICITEMENT — `update()` ne
        // l'ecrit pas (hors `$fillable`, hors liste de validation), donc aucun
        // appelant ne le porte par accident aujourd'hui (mesure : zero PUT vers
        // /companies dans `frontend/src`).
        $attendu = $r->input('updated_at');

        if ($attendu === null || $attendu === '' || ! is_string($attendu)) {
            return;
        }

        $courantHorodate = $modele->updated_at ?? null;

        if ($courantHorodate === null) {
            // On ne sait pas comparer : on refuse plutot que de laisser passer.
            $this->refuserConflitDeVersion($modele);
        }

        try {
            $attenduUtc = Carbon::parse($attendu)->utc()->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            $this->refuserConflitDeVersion($modele);
        }

        $courantUtc = Carbon::parse($courantHorodate)->utc()->format('Y-m-d H:i:s.u');

        // ⚠️ LA PRECISION DE COMPARAISON EST CELLE QUE LE CLIENT A FOURNIE, et
        // c'est un choix, pas une facilite. La colonne melange deux precisions :
        // tronquee a la seconde quand Laravel l'ecrit a l'INSERT, aux
        // microsecondes quand le trigger Postgres la pose a l'UPDATE (cf. l'en-tete
        // de ce fichier). Comparer toujours a la seconde confondait deux etats
        // differents et laissait la perte passer — c'etait le defaut de ma
        // premiere version, mesure une fois sur deux par la garde. Comparer
        // toujours aux microsecondes infligerait un 409 perpetuel au client qui
        // renvoie une date arrondie a la milliseconde, comme le fait toute date
        // JavaScript : il ne pourrait jamais enregistrer.
        //
        // On coupe donc les DEUX chaines au nombre de decimales que le client a
        // ecrit. Il choisit sa sensibilite ; il ne peut pas se bloquer lui-meme.
        // `Y-m-d H:i:s` occupe exactement 19 caracteres, d'ou la coupe.
        $decimales = preg_match('/\.(\d+)/', $attendu, $m) === 1 ? min(6, strlen($m[1])) : 0;
        $coupe = $decimales > 0 ? 20 + $decimales : 19;

        if (substr($attenduUtc, 0, $coupe) !== substr($courantUtc, 0, $coupe)) {
            $this->refuserConflitDeVersion($modele);
        }
    }

    /**
     * Le refus. 409 « Conflict » : la demande est valide, c'est l'etat de la
     * ressource qui a change — ce n'est ni une erreur de saisie (422) ni un
     * droit manquant (403).
     *
     * La reponse porte le jeton COURANT, dans le corps et dans `ETag` : le
     * client relit, fusionne, rejoue. Sans ce jeton il devrait refaire un GET
     * pour esperer reussir.
     */
    protected function refuserConflitDeVersion(Model $modele): never
    {
        $horodatage = $modele->updated_at ?? null;

        throw new HttpResponseException(
            response()->json([
                'error' => 'version_conflict',
                'message' => "Cette fiche a été modifiée par quelqu'un d'autre depuis votre lecture. "
                    . 'Rechargez-la, puis réappliquez vos changements : enregistrer maintenant effacerait '
                    . 'la saisie de la personne qui vous a précédé.',
                'current_version' => $this->jetonDeVersion($modele),
                'updated_at' => $horodatage instanceof Carbon ? $horodatage->toJSON() : $horodatage,
            ], 409)->header('ETag', $this->jetonDeVersion($modele)),
        );
    }

    /**
     * Pose le jeton de l'etat d'APRES sur une reponse de succes.
     *
     * Il est calcule sur une RELECTURE (`fresh()`), pas sur le modele en
     * memoire : c'est ce qu'un prochain appel chargera, colonnes generees
     * comprises (`denomination_normalized`, `quality_badge` sont GENERATED cote
     * Postgres). Un jeton calcule en memoire ne correspondrait pas a celui
     * recalcule a la requete suivante, et le client bouclerait sur des 409
     * apres chaque succes.
     */
    protected function avecJetonDeVersion(JsonResponse $reponse, Model $modele): JsonResponse
    {
        $fraiche = $modele->fresh();

        return $reponse->header('ETag', $this->jetonDeVersion($fraiche ?? $modele));
    }
}
