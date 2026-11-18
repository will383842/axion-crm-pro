<?php

namespace App\Envoi\Desinscription;

use App\Envoi\Conformite\RegistreOppositionManuel;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Met une adresse (par son empreinte) à l'écart : un lien par adresse (réutilisé au rejeu), puis UNE opposition
 * (`EnregistrerOpposition`, idempotente). N'efface rien, ne lit ni n'écrit d'adresse en clair. Le canal dit d'où vient le geste.
 *
 * Adresse de REPLI (adresse générale, un seul message) : l'opposition vaut AUSSI pour le SIREN de l'entreprise, pour qu'aucun
 * contact nommé ajouté plus tard ne soit prospecté. Sans SIREN exploitable, la trace par entreprise (`ArretParEntrepriseL1`) fait foi.
 * Échec fermé : l'adresse est opposée D'ABORD ; les SIREN s'écrivent ensuite en UNE transaction (tout ou rien). Une erreur
 * n'est jamais avalée : elle remonte, l'adresse reste opposée, et le rejeu (idempotent) reprend l'écriture des SIREN.
 */
final class OpposerAdresse
{
    public function __construct(private readonly EnregistrerOpposition $opposition) {}

    public function executer(string $ws, string $empreinte, string $canal): void
    {
        $reference = DB::transaction(function () use ($ws, $empreinte): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_stop|' . $ws . '|' . $empreinte]);
            $lien = DB::table('envoi_liens_desinscription')->where('workspace_id', $ws)->where('empreinte_adresse', $empreinte)->orderBy('id')->first();
            if ($lien !== null) {
                return (string) $lien->reference;
            }
            $reference = (string) Str::uuid();
            DB::table('envoi_liens_desinscription')->insert([
                'workspace_id' => $ws, 'reference' => $reference, 'empreinte_adresse' => $empreinte, 'portee' => 'business',
            ]);

            return $reference;
        });
        $this->opposition->executer($ws, $reference, $canal);
        $this->opposerLesEntreprisesDeRepli($ws, $empreinte);
    }

    /** SIREN des entreprises dont CETTE adresse est une inscription de repli (tous statuts), inscrits au registre en une transaction. */
    private function opposerLesEntreprisesDeRepli(string $ws, string $empreinte): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $empreinte): void {
            $sirens = DB::table('envoi_inscriptions as i')
                ->join('companies as c', fn ($j) => $j->on('c.id', '=', 'i.entreprise_id')->on('c.workspace_id', '=', 'i.workspace_id'))
                ->where('i.workspace_id', $ws)->where('i.empreinte_adresse', $empreinte)->where('i.repli_generique', true)
                ->whereNotNull('c.siren')->distinct()->orderBy('c.siren')->pluck('c.siren')->map(fn ($s): string => trim((string) $s))->all();
            if ($sirens === []) {
                return;
            }
            DB::transaction(function () use ($ws, $sirens): void {
                $registre = new RegistreOppositionManuel;
                foreach ($sirens as $siren) {
                    try {
                        $registre->inscrire($ws, 'organisation', $siren, 'Opposition reçue sur l’adresse générale de repli', 'systeme');
                    } catch (InvalidArgumentException) {
                        // SIREN inexploitable (clé de contrôle fausse) : le registre ne peut le porter ; la trace par entreprise fait foi.
                    }
                }
            });
        });
    }
}
