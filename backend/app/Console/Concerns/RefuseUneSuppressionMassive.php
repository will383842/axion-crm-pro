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
     * Décide si la suppression peut avoir lieu, et l'explique à l'opérateur.
     *
     * @param  string  $table      table visée, pour le message
     * @param  int     $aSupprimer nombre de lignes que la condition sélectionne
     * @param  int     $total      nombre total de lignes de la table
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
                "REFUS : cette commande supprimerait {$pourcentage} % de « {$table} », au-delà du plafond de {$seuil} %."
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
}
