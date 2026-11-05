<?php

namespace App\Crm\Origines;

/**
 * Garantit UNE seule ligne d'audit par export : écrite normalement par `terminer()` (finally),
 * ou, si le script meurt avant, par la fonction d'arrêt (`__invoke`) avec le marqueur « interrompu ».
 * Ne manipule que des volumes : aucune donnée de personne.
 */
final class GardeAuditExport
{
    private bool $ecrit = false;

    /**
     * @param  \Closure(int, bool): void  $ecrire  (lignes lues, interrompu)
     */
    public function __construct(private readonly \Closure $ecrire, private readonly \stdClass $etat, private readonly int $plafond) {}

    public function terminer(): void
    {
        if ($this->ecrit) {
            return;
        }
        $this->ecrit = true;
        ($this->ecrire)(min($this->etat->lues, $this->plafond), false);
    }

    /** Fonction d'arrêt : n'écrit que si rien n'a été écrit. */
    public function __invoke(): void
    {
        if ($this->ecrit) {
            return;
        }
        $this->ecrit = true;
        ($this->ecrire)(min($this->etat->lues, $this->plafond), true);
    }
}
