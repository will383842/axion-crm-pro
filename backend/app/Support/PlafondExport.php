<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * LE PLAFOND DES EXPORTS CSV, écrit UNE SEULE FOIS.
 *
 * 🔴 Constat G41-007 (S1, audit 360) : « l'export CSV n'a aucun plafond et gèle
 * l'application au moins deux minutes au volume de production ».
 *
 * Ce que faisait le code avant : `chunkById(1000)` sur la totalité de la table,
 * sans borne. Au volume mesuré — 4 295 349 fiches entreprises — cela fait
 * 4 296 allers-retours SQL, chacun suivi d'un `fputcsv` par ligne, dans un
 * processus PHP-FPM occupé du début à la fin. Un seul export suffit à immobiliser
 * un worker ; trois clics suffisent à saturer la pool.
 *
 * ── Pourquoi un plafond plutôt qu'une pagination ou une file d'attente ───────
 * Une file d'attente (export asynchrone, lien par courriel) est la bonne réponse
 * à terme, et elle demande une table de travaux, un stockage de fichiers et un
 * écran. Ce n'est pas ce que ferme ce lot. Le plafond, lui, est une borne dure
 * qui rend le pire cas connu — et surtout FINI.
 *
 * ── Pourquoi il se DIT ──────────────────────────────────────────────────────
 * Un export tronqué en silence est plus dangereux qu'un export lent : celui qui
 * le reçoit croit tenir la base entière et travaille sur un extrait sans le
 * savoir. Chaque fichier tronqué se termine donc par une ligne
 * « EXPORT TRONQUE » — sans accent, pour rester lisible quel que soit l'outil
 * qui l'ouvre — et la réponse porte l'en-tête `X-Export-Plafond`.
 *
 * ── Réglable sans redéploiement ─────────────────────────────────────────────
 * `config('exports.plafond_lignes')` l'emporte sur la valeur par défaut. Aucun
 * fichier `config/exports.php` n'est nécessaire : la clé absente retombe sur la
 * constante. Cela permet à l'exploitant de la relever ponctuellement, et aux
 * tests de la descendre sans fabriquer 50 000 lignes.
 */
final class PlafondExport
{
    /**
     * 50 000 lignes. Repère de dimensionnement : à ce plafond, l'export
     * entreprises fait 50 allers-retours SQL au lieu de 4 296, soit ~1,2 % du
     * travail précédent. C'est aussi l'ordre de grandeur qu'un tableur ouvre
     * sans peine, et bien au-delà de ce qu'une campagne réelle consomme.
     */
    public const LIGNES_PAR_DEFAUT = 50000;

    /**
     * Marqueur écrit en dernière ligne d'un fichier tronqué.
     * SANS ACCENT ET SANS PONCTUATION EXOTIQUE : c'est la sous-chaîne que les
     * gardes cherchent, et un accent la rendrait dépendante de l'encodage.
     */
    public const MARQUEUR_TRONQUE = 'EXPORT TRONQUE';

    /** Le plafond effectif, jamais inférieur à 1 (un export vide ne dit rien). */
    public static function lignes(): int
    {
        $configure = (int) config('exports.plafond_lignes', self::LIGNES_PAR_DEFAUT);

        return max(1, $configure);
    }

    /**
     * Parcourt la requête en lots bornés et n'écrit JAMAIS plus que le plafond.
     *
     * ⚠️ On lit délibérément UNE ligne de plus que le plafond, sans l'écrire :
     * c'est le seul moyen de distinguer « l'export fait exactement 50 000
     * lignes » de « l'export a été coupé à 50 000 ». Sans cette ligne témoin,
     * un export complet de pile 50 000 fiches serait annoncé tronqué — et on
     * ferait douter d'un fichier correct.
     *
     * `chunkById` est conservé (pagination stable en mémoire bornée) ; poser un
     * `->limit()` sur la requête n'aurait servi à rien, `chunkById` le
     * réécrivant à chaque lot par `forPageAfterId()`.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  callable(Model):void  $ecrireLigne
     * @return bool vrai si l'export a été coupé
     */
    public static function parcourirBorne(Builder $query, callable $ecrireLigne): bool
    {
        $plafond = self::lignes();
        $lues = 0;
        $tronque = false;

        $query->chunkById(min(1000, $plafond + 1), function ($lot) use (&$lues, &$tronque, $plafond, $ecrireLigne) {
            foreach ($lot as $ligne) {
                $lues++;
                if ($lues > $plafond) {
                    $tronque = true;

                    return false; // stoppe chunkById
                }

                $ecrireLigne($ligne);
            }

            return true;
        });

        return $tronque;
    }

    /**
     * Écrit la ligne d'avertissement en queue de fichier.
     *
     * @param  resource  $sortie
     * @param  int  $colonnes  largeur du CSV, pour que la ligne reste alignée
     */
    public static function ecrireAvertissement($sortie, int $colonnes): void
    {
        $message = self::MARQUEUR_TRONQUE . ' : plafond de ' . self::lignes()
            . ' lignes atteint, ce fichier est INCOMPLET. Affinez les filtres pour obtenir le reste.';

        fputcsv($sortie, array_pad([$message], max(1, $colonnes), ''));
    }

    /**
     * En-têtes HTTP annonçant le plafond, à fusionner avec ceux de l'export.
     * Posés AVANT le streaming : à ce moment on ne sait pas encore si le fichier
     * sera coupé, donc on annonce la BORNE, pas le résultat. Le résultat, lui,
     * est dans la dernière ligne du fichier.
     *
     * @return array<string,string>
     */
    public static function entetes(): array
    {
        return ['X-Export-Plafond' => (string) self::lignes()];
    }
}
