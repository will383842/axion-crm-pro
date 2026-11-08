<?php

namespace App\Envoi\Sequence;

use App\Crm\Campagnes\EligibiliteAdresse;
use App\Crm\Campagnes\GardeConsoleClient;
use App\Crm\Campagnes\GardePresse;
use App\Crm\Campagnes\Segments;
use App\Crm\Emails\QualificationEmail;
use App\Crm\Emails\VerificationEmail;
use App\Crm\ProvenanceTiers\ProvenanceTiers;
use App\Crm\Sites\QuarantaineSite;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lecture DEPUIS LA BASE (contacts, companies, tags) : l'appelant ne fournit aucun fait.
 *
 * Mêmes faits que `ResolveurDestinataires::candidats` (qui fait référence) :
 * une occurrence par fiche portant LA MÊME adresse, avec non_diffusible,
 * entreprise_fermee, entreprise_individuelle, site_non_verifie,
 * information_tiers_insuffisante, email_source, perso, status, verification,
 * deja_informe. `EligibiliteAdresse::motif` applique « une occurrence suffit ».
 *
 * Article 14 RGPD : la date de collecte est celle du CONTACT (`contacts.created_at`)
 * et la première information celle du CONTACT (`contacts.first_info_at`). L'information
 * donnée à l'entreprise ne vaut pas information donnée à une personne ajoutée ensuite.
 *
 * `GardePresse` (par contact et par fiche) et `GardeConsoleClient` s'appliquent ici :
 * elles ne sont pas des motifs d'`EligibiliteAdresse` mais des exclusions de chemin.
 */
final class LecteurFicheDb implements LecteurFiche
{
    public const EXCLUSION_PRESSE = 'contact_presse';

    public function lire(string $workspaceId, int $contactId): ?Fiche
    {
        $l = DB::table('contacts as c')
            ->join('companies as o', 'o.id', '=', 'c.company_id')
            ->where('c.workspace_id', $workspaceId)
            ->where('o.workspace_id', $workspaceId)
            ->where('c.id', $contactId)
            ->whereNull('c.deleted_at')
            ->selectRaw('c.id, c.company_id, c.email, c.created_at, c.first_info_at, o.country_code, o.siren')
            ->selectRaw(GardePresse::estContactPresseSql('c') . ' AS contact_presse')
            ->selectRaw('NOT ' . GardePresse::conditionSql('o.id') . ' AS fiche_presse')
            ->first();
        if ($l === null || $l->email === null || trim((string) $l->email) === '') {
            return null;
        }
        $adresse = QualificationEmail::normaliser((string) $l->email);

        $noms = DB::table('company_tag as ct')
            ->join('tags as t', 't.id', '=', 'ct.tag_id')
            ->where('ct.company_id', $l->company_id)
            ->where('t.workspace_id', $workspaceId)
            ->pluck('t.slug')->map(fn ($n): string => mb_strtolower(trim((string) $n)))->all();
        $segment = null;
        foreach (Segments::CONNUS as $s) {
            if (in_array(mb_strtolower(Segments::tag($s)), $noms, true)) {
                $segment = $s;
                break;
            }
        }

        $exclusion = null;
        if ((bool) $l->contact_presse || (bool) $l->fiche_presse) {
            $exclusion = self::EXCLUSION_PRESSE;
        } elseif (GardeConsoleClient::exclues($workspaceId, [$adresse]) !== []) {
            $exclusion = EligibiliteAdresse::FICHE_CONSOLE;
        }

        return new Fiche(
            (int) $l->id,
            (int) $l->company_id,
            (string) $l->email,
            $l->country_code === null ? null : (string) $l->country_code,
            $l->siren === null ? null : (string) $l->siren,
            $segment,
            $l->created_at === null ? null : new DateTimeImmutable((string) $l->created_at),
            $l->first_info_at === null ? null : new DateTimeImmutable((string) $l->first_info_at),
            $this->occurrences($workspaceId, $adresse),
            $exclusion,
        );
    }

    /**
     * Toutes les fiches (contacts non supprimés de l'espace) qui portent cette adresse.
     *
     * @return list<array<string, mixed>>
     */
    private function occurrences(string $workspaceId, string $adresse): array
    {
        $lignes = DB::table('contacts as c')
            ->join('companies as o', 'o.id', '=', 'c.company_id')
            ->where('c.workspace_id', $workspaceId)
            ->where('o.workspace_id', $workspaceId)
            ->whereNull('c.deleted_at')
            ->where('c.email', $adresse) // CITEXT : insensible à la casse, servi par idx_contacts_email
            ->selectRaw('c.id, c.email, c.email_status, c.metadata, c.first_info_at, o.legal_form, o.insee_non_diffusible_le, o.insee_ferme_le')
            ->selectRaw("o.metadata->>'email_source' AS email_source")
            ->selectRaw(QuarantaineSite::personneSql('c', 'o') . ' AS site_non_verifie')
            ->selectRaw(ProvenanceTiers::informationInsuffisanteSql('c') . ' AS information_tiers_insuffisante')
            ->get();

        $occurrences = [];
        foreach ($lignes as $l) {
            $meta = json_decode(is_string($l->metadata ?? null) ? $l->metadata : '{}', true);
            $meta = is_array($meta) ? $meta : [];
            $verification = VerificationEmail::statutDe($meta['email_verification'] ?? null, $adresse)
                ?? match ((string) $l->email_status) {
                    'valid' => VerificationEmail::VALIDE,
                    'invalid' => VerificationEmail::INVALIDE,
                    'disposable' => VerificationEmail::JETABLE,
                    default => null,
                };
            $occurrences[] = [
                'email' => $adresse,
                'status' => $l->email_status === null ? null : (string) $l->email_status,
                'verification' => $verification,
                'perso' => ($meta['email_nature'] ?? null) === 'perso',
                'deja_informe' => $l->first_info_at !== null,
                'entreprise_individuelle' => EligibiliteAdresse::estEntrepriseIndividuelle($l->legal_form),
                'non_diffusible' => $l->insee_non_diffusible_le !== null,
                'entreprise_fermee' => $l->insee_ferme_le !== null,
                'site_non_verifie' => (bool) $l->site_non_verifie,
                'information_tiers_insuffisante' => (bool) $l->information_tiers_insuffisante,
                'email_source' => is_string($l->email_source ?? null) ? $l->email_source : null,
            ];
        }

        return $occurrences;
    }
}
