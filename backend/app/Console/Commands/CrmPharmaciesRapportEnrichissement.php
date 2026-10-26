<?php

namespace App\Console\Commands;

use App\Crm\EspaceProspection;
use App\Crm\Pharmacies\SelectionEnrichissement;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;

/**
 * RAPPORT D'ENRICHISSEMENT DES PHARMACIES OUVERTES — TOTAUX SEULEMENT.
 *
 * Par taille, par département : nombre de fiches et part avec téléphone,
 * e-mail générique, site, contact (personne) et groupement. Dépôt public et
 * RGPD : aucun nom, aucune adresse, aucun identifiant en sortie. Lecture seule.
 */
class CrmPharmaciesRapportEnrichissement extends Command
{
    protected $signature = 'crm:pharmacies:rapport-enrichissement
                            {--workspace= : Identifiant ou slug de l espace}';

    protected $description = 'Totaux d enrichissement des pharmacies ouvertes (par taille et par département) — aucun nom ni adresse.';

    public function handle(): int
    {
        $espace = EspaceProspection::resoudre(is_string($this->option('workspace')) ? $this->option('workspace') : null);
        if ($espace === null) {
            $this->error('Aucun espace cible (--workspace=UUID ou slug).');

            return self::FAILURE;
        }

        $lignes = WorkspaceContext::run($espace, fn (): array => [
            'taille' => $this->agreger($espace, "COALESCE(NULLIF(size_category, ''), 'inconnue')"),
            'departement' => $this->agreger($espace, "COALESCE(NULLIF(department_code, ''), 'inconnu')"),
        ]);

        $this->info('Pharmacies ouvertes — enrichissement (totaux)');
        foreach (['taille' => 'Par taille', 'departement' => 'Par département'] as $cle => $titre) {
            $this->line('');
            $this->line($titre);
            foreach ($lignes[$cle] as $l) {
                $this->line(sprintf(
                    '  %-12s fiches %6d | tél %5s | e-mail %5s | site %5s | contact %5s | groupement %5s',
                    $l->groupe,
                    $l->total,
                    $this->part($l->telephone, $l->total),
                    $this->part($l->email, $l->total),
                    $this->part($l->site, $l->total),
                    $this->part($l->contact, $l->total),
                    $this->part($l->groupement, $l->total),
                ));
            }
        }

        return self::SUCCESS;
    }

    /** @return list<\stdClass> */
    private function agreger(string $espace, string $groupe): array
    {
        $lignes = SelectionEnrichissement::ouvertes($espace)
            ->selectRaw(
                "{$groupe} AS groupe, count(*) AS total,"
                . " count(*) FILTER (WHERE phone IS NOT NULL AND phone <> '') AS telephone,"
                . " count(*) FILTER (WHERE email_generic IS NOT NULL AND email_generic <> '') AS email,"
                . " count(*) FILTER (WHERE website IS NOT NULL AND website <> '') AS site,"
                . ' count(*) FILTER (WHERE EXISTS (SELECT 1 FROM contacts ct WHERE ct.company_id = companies.id)) AS contact,'
                . " count(*) FILTER (WHERE COALESCE(metadata->>'groupement', '') <> '') AS groupement",
            )
            ->groupByRaw($groupe)->orderByRaw('count(*) DESC')->get()->all();

        return array_values($lignes);
    }

    private function part(int|string $n, int|string $total): string
    {
        return (int) $total > 0 ? number_format((int) $n * 100 / (int) $total, 1, ',', '') . ' %' : '0 %';
    }
}
