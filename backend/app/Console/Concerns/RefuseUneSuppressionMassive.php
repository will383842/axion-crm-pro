<?php

namespace App\Console\Concerns;

/**
 * Garde commune aux commandes qui SUPPRIMENT DÉFINITIVEMENT des lignes.
 *
 * 🔴 POURQUOI CE FICHIER EXISTE (audit 360, B15-004 et B15-008).
 *
 * `prospection:purge-non-commercial` supprimait sur la condition
 * `legal_form IS NULL OR left(legal_form, 1) <> '5'`. Sur la base de volume, où
 * `legal_form` est nul pour l'essentiel des lignes, cette commande **efface
 * presque tout** — sans confirmation, sans essai à blanc, sans plafond, et sans
 * qu'aucun test ne dise jamais ce qu'elle supprime.
 *
 * Une commande destructive doit rendre l'accident DIFFICILE, pas seulement
 * possible à éviter. Trois barrières, dans cet ordre :
 *
 *   1. `--dry-run` : on montre ce qu'on ferait, on ne fait rien ;
 *   2. un PLAFOND de proportion : au-delà, on refuse et on explique — c'est
 *      cette barrière-là qui aurait arrêté l'effacement total ;
 *   3. une confirmation explicite, ou `--force` pour l'automatisation assumée.
 *
 * `--force` reste possible : une purge légitime peut porter sur une grande part
 * de la table. Mais elle devient un GESTE VOLONTAIRE, écrit dans la commande,
 * et non le comportement par défaut d'un `artisan` lancé sans y penser.
 */
trait RefuseUneSuppressionMassive
{
    /** Part de la table au-delà de laquelle on exige `--force`. */
    protected float $proportionMaximale = 0.30;

    /**
     * Nombre de lignes en deçà duquel la PROPORTION ne veut rien dire.
     *
     * 🔑 POURQUOI UN PLANCHER, ET PAS SEULEMENT UN POURCENTAGE.
     *
     * Ce plafond protège d'un accident de MASSE — le cas `B15-004`, où une
     * condition trop large effaçait presque toute une table de plusieurs
     * millions de lignes. Sur un petit volume, la proportion s'affole sans que
     * le danger existe : purger 2 candidats sur 4, c'est 50 %, et c'est
     * exactement le ménage qu'on attend.
     *
     * Or bloquer là serait pire qu'inutile. Une purge de rétention RGPD qui ne
     * s'exécute pas est un MANQUEMENT : le CRM garde des données au-delà de
     * l'échéance qu'il s'est donnée. On refuserait donc, chaque mois, au nom
     * d'un risque qui n'existe pas à cette échelle.
     *
     * En deçà de ce plancher, seule la proportion est TUE ; tout le reste — le
     * compte, la table, le journal — est dit comme d'habitude.
     */
    protected int $plancherLignes = 1000;

    /**
     * Décide si la suppression peut avoir lieu, et l'explique à l'opérateur.
     *
     * @param  string  $table  table visée, pour le message
     * @param  int  $aSupprimer  nombre de lignes que la condition sélectionne
     * @param  int  $total  nombre total de lignes de la table
     */
    protected function suppressionAutorisee(string $table, int $aSupprimer, int $total): bool
    {
        if ($aSupprimer === 0) {
            $this->info("Rien à supprimer dans « {$table} ».");

            return false;
        }

        $proportion = $total > 0 ? $aSupprimer / $total : 1.0;
        $pourcentage = number_format($proportion * 100, 1, ',', ' ');

        $this->line("Table « {$table} » : {$aSupprimer} ligne(s) sur {$total} sélectionnée(s) — {$pourcentage} %.");

        if ($this->option('dry-run')) {
            $this->info("Essai à blanc : RIEN n'a été supprimé. Relancez sans --dry-run pour agir.");

            return false;
        }

        $force = (bool) $this->option('force');

        if ($proportion > $this->proportionMaximale && ! $force) {
            $seuil = number_format($this->proportionMaximale * 100, 0, ',', ' ');
            $this->error(
                "REFUS : cette commande supprimerait {$pourcentage} % de « {$table} », au-delà du plafond de {$seuil} %.",
            );
            $this->line('Si cette proportion est VOULUE, relancez avec --force. Vérifiez d\'abord avec --dry-run.');

            return false;
        }

        if (! $force && $this->input->isInteractive()) {
            return (bool) $this->confirm(
                "Confirmer la suppression DÉFINITIVE de {$aSupprimer} ligne(s) de « {$table} » ?",
                false,
            );
        }

        if (! $force) {
            $this->error('REFUS : suppression non interactive sans --force. Rien n\'a été supprimé.');

            return false;
        }

        return true;
    }

    /**
     * Même plafond, POUR UNE COMMANDE PLANIFIÉE — et sans confirmation.
     *
     * 🔴 POURQUOI CETTE SECONDE PORTE EXISTE (B15-008, 2026-08-21).
     *
     * `suppressionAutorisee()` ci-dessus finit par exiger `--force` dès que
     * l'entrée n'est pas interactive. C'est juste pour une commande qu'un
     * humain lance. C'est INAPPLICABLE à une commande que le planificateur
     * lance seul : elle refuserait chaque nuit, en silence, et personne ne
     * lirait le message.
     *
     * La parade n'est donc pas d'ajouter `--force` à la ligne du planificateur —
     * cela reviendrait à retirer la garde tout en ayant l'air de la poser. C'est
     * de garder la SEULE barrière qui protège vraiment d'un accident de masse :
     * le PLAFOND DE PROPORTION. Au-delà, on refuse et on crie ; en deçà, on
     * laisse l'automatisme faire son travail.
     *
     * `media:clean-emails` (tous les jours à 05:05) met `media.email` à NULL sur
     * les adresses parasites ou sur-partagées. Le jour où son détecteur se
     * trompe — un domaine grand public mal classé, un seuil trop bas — il
     * effacerait les adresses de tout le registre presse en une nuit, sans que
     * rien ne l'arrête. Ce plafond-ci l'arrête.
     *
     * ⚠️ Le verbe est paramétrable parce que ces commandes n'effacent pas
     * toujours des LIGNES : nuller une colonne détruit tout autant, et le
     * message doit dire ce qui se passe vraiment.
     *
     * @param  string  $table  table visée, pour le message
     * @param  int  $aEcrire  nombre de lignes que la condition sélectionne
     * @param  int  $total  nombre total de lignes de la table
     * @param  string  $verbe  ce que la commande fait ("purger", "nuller"…)
     */
    protected function ecritureAutoriseeSansOperateur(
        string $table,
        int $aEcrire,
        int $total,
        string $verbe = 'modifier',
    ): bool {
        if ($aEcrire === 0) {
            $this->info("Rien à {$verbe} dans « {$table} ».");

            return false;
        }

        $proportion = $total > 0 ? $aEcrire / $total : 1.0;
        $pourcentage = number_format($proportion * 100, 1, ',', ' ');

        $this->line("Table « {$table} » : {$aEcrire} ligne(s) sur {$total} — {$pourcentage} %.");

        if ($aEcrire < $this->plancherLignes) {
            // Sous le plancher : la proportion ne mesure pas un risque de masse.
            return true;
        }

        if ($proportion > $this->proportionMaximale && ! (bool) $this->option('force')) {
            $seuil = number_format($this->proportionMaximale * 100, 0, ',', ' ');
            $this->error(
                "REFUS : cette commande va {$verbe} {$pourcentage} % de « {$table} », "
                . "au-delà du plafond de {$seuil} %.",
            );
            $this->line(
                'Une commande PLANIFIÉE qui touche une telle part de la table est presque '
                . 'toujours un détecteur qui s\'est trompé, pas un ménage légitime. '
                . 'Vérifiez avec --dry-run ; si la proportion est VOULUE, relancez à la main '
                . 'avec --force.',
            );

            return false;
        }

        return true;
    }
}
