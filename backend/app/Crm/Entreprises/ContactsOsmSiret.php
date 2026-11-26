<?php

namespace App\Crm\Entreprises;

use App\Crm\Emails\QualificationEmail;
use App\Crm\FichesProtegees;
use App\Crm\Pharmacies\ContactsOsm;
use App\Crm\Pharmacies\SelectionEnrichissement;
use App\Crm\Pharmacies\TelephonesFiness;
use App\Crm\Taxonomy;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SplFileObject;
use Throwable;

/**
 * CONTACTS DES ENTREPRISES DEPUIS OPENSTREETMAP, PAR SIRET — AJOUT SEUL (dépôt public : totaux uniquement).
 *
 * Source : objets OSM portant `ref:FR:SIRET` (licence ODbL), réduits par le workflow à un CSV
 * `siret,telephone,email,site`, avec UNE requête Overpass par région (jamais par fiche).
 *
 *  - rapprochement par ÉGALITÉ EXACTE du SIRET (14 chiffres) ; un SIRET porté par deux objets OSM, ou par
 *    deux fiches de l'espace, est ambigu : rien n'est écrit ;
 *  - e-mail : boîte GÉNÉRIQUE seulement, sur champ vide ; l'adresse reste non vérifiée ;
 *  - téléphone : sur champ vide seulement ;
 *  - site : simple CANDIDAT à prouver, noté dans `metadata.contacts_osm_siret.site_candidat`. `website` n'est
 *    JAMAIS écrit ici (la preuve par le SIREN est faite ailleurs) ;
 *  - origine `osm` dans `field_origins` (e-mail, téléphone) ; licence ODbL et attribution dans `metadata` ;
 *  - jamais d'écrasement, fiches protégées, personnes physiques, fermées et oppositions écartées ;
 *  - aucune suppression, aucun envoi ; seuls des totaux sont retournés.
 */
final class ContactsOsmSiret
{
    public const SOURCE = 'osm';

    public const CLE = 'contacts_osm_siret';

    private const COLONNES = ['siret', 'telephone', 'email', 'site'];

    /** Normalise un SIRET : 14 chiffres exacts (espaces retirés), sinon null. */
    public static function normaliserSiret(string $brut): ?string
    {
        $s = preg_replace('/\s+/', '', $brut) ?? '';

        return preg_match('/^\d{14}$/', $s) === 1 ? $s : null;
    }

    /**
     * Partie pure (sans base) : lit le CSV et ne garde que les points exploitables.
     *
     * @return array{0: array<string, array{tels: list<string>, email: string, site: string}>, 1: array<string, int>}
     */
    public static function preparer(string $fichier): array
    {
        $bilan = ['points' => 0, 'siret_invalides' => 0, 'siret_ambigus' => 0, 'emails_nominatifs_ignores' => 0];
        $points = [];
        $ambigus = [];
        foreach (self::lire($fichier) as $brut) {
            $bilan['points']++;
            $siret = self::normaliserSiret($brut['siret']);
            if ($siret === null) {
                $bilan['siret_invalides']++;

                continue;
            }
            $email = ContactsOsm::normaliserEmail($brut['email']) ?? '';
            if ($email !== '' && QualificationEmail::type($email) !== 'generique') {
                $email = '';
                $bilan['emails_nominatifs_ignores']++;
            }
            if (isset($points[$siret]) || isset($ambigus[$siret])) {
                $ambigus[$siret] = true;
                unset($points[$siret]);

                continue;
            }
            $points[$siret] = ['tels' => $brut['tels'], 'email' => $email, 'site' => ContactsOsm::normaliserSite($brut['site']) ?? ''];
        }
        $bilan['siret_ambigus'] = count($ambigus);

        return [$points, $bilan];
    }

    /**
     * @return array<string, int> bilan chiffré (aucun nom, aucune adresse, aucun numéro)
     */
    public function executer(string $workspaceId, string $fichier, bool $appliquer, int $paquet = 500): array
    {
        [$points, $bilan] = self::preparer($fichier);
        $bilan += [
            'rapproches' => 0, 'non_rapproches' => 0, 'ambigus' => 0, 'exclues' => 0, 'opposition' => 0,
            'emails_a_ajouter' => 0, 'emails_ajoutes' => 0, 'telephones_a_ajouter' => 0, 'telephones_ajoutes' => 0,
            'sites_a_noter' => 0, 'sites_notes' => 0, 'lots_en_echec' => 0,
        ];

        $fiches = WorkspaceContext::run($workspaceId, fn (): array => $this->chargerFiches($workspaceId, array_keys($points)));
        $plans = [];
        foreach ($points as $siret => $p) {
            $liste = $fiches[$siret] ?? [];
            if ($liste === []) {
                $bilan['non_rapproches']++;
            } elseif (count($liste) > 1) {
                $bilan['ambigus']++;
            } elseif (! $liste[0]['eligible']) {
                $bilan['exclues']++;
            } else {
                $bilan['rapproches']++;
                $plan = $this->planifier($liste[0], $p);
                if ($plan !== []) {
                    $plans[$liste[0]['id']] = ['plan' => $plan, 'fiche' => $liste[0]];
                }
            }
        }

        foreach (array_chunk($plans, max(1, $paquet), true) as $lot) {
            try {
                $this->traiter($workspaceId, $lot, $appliquer, $bilan);
            } catch (Throwable) {
                // Jamais le message : une erreur de base peut contenir du SQL avec ses valeurs.
                $bilan['lots_en_echec']++;
            }
        }

        return $bilan;
    }

    /**
     * Fiches non supprimées de l'espace dont le SIRET est EXACTEMENT l'un de ceux demandés.
     *
     * @param  list<string|int>  $sirets
     * @return array<string, list<array<string, mixed>>> siret → fiches
     */
    private function chargerFiches(string $workspaceId, array $sirets): array
    {
        $parSiret = [];
        foreach (array_chunk(array_map('strval', $sirets), 1000) as $morceau) {
            $lignes = DB::table('companies')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->whereIn('siret', $morceau)
                ->select(['id', 'siret', 'phone', 'email_generic', 'legal_form', 'metadata', 'field_origins', 'insee_ferme_le'])
                ->selectRaw('CASE WHEN ' . FichesProtegees::conditionSql('companies.id', null, 'companies.workspace_id') . ' THEN 1 ELSE 0 END AS non_protegee')
                ->get();
            foreach ($lignes as $c) {
                $meta = json_decode((string) $c->metadata, true);
                $origines = json_decode((string) $c->field_origins, true);
                $parSiret[(string) $c->siret][] = [
                    'id' => (int) $c->id,
                    'phone' => trim((string) $c->phone),
                    'email' => trim((string) $c->email_generic),
                    'origines' => is_array($origines) ? $origines : [],
                    'eligible' => $c->insee_ferme_le === null
                        && (int) $c->non_protegee === 1
                        && ! SelectionEnrichissement::estPersonnePhysique(is_array($meta) ? $meta : [], $c->legal_form),
                ];
            }
        }

        return $parSiret;
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array{tels: list<string>, email: string, site: string}  $p
     * @return array<string, string>
     */
    private function planifier(array $f, array $p): array
    {
        $plan = [];
        $libre = static fn (string $col, string $valeur): bool => $valeur === ''
            && ! in_array($f['origines'][$col] ?? null, Taxonomy::FIELD_ORIGINS_PROTEGEES, true);
        if ($p['email'] !== '' && $libre('email_generic', $f['email'])) {
            $plan['email_generic'] = $p['email'];
        }
        if ($p['tels'] !== [] && $libre('phone', $f['phone'])) {
            $plan['phone'] = $p['tels'][0];
        }
        if ($p['site'] !== '') {
            $plan['site_candidat'] = $p['site'];
        }

        return $plan;
    }

    /**
     * @param  array<int, array{plan: array<string, string>, fiche: array<string, mixed>}>  $lot
     * @param  array<string, int>  $bilan
     */
    private function traiter(string $workspaceId, array $lot, bool $appliquer, array &$bilan): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $lot, $appliquer, &$bilan): void {
            $variantes = [];
            $emails = [];
            foreach ($lot as $x) {
                foreach ([$x['plan']['phone'] ?? null, $x['fiche']['phone']] as $tel) {
                    if (is_string($tel) && $tel !== '') {
                        array_push($variantes, ...ListeSuppression::variantesTelephone($tel));
                    }
                }
                foreach ([$x['plan']['email_generic'] ?? null, $x['fiche']['email']] as $mail) {
                    if (is_string($mail) && $mail !== '') {
                        $emails[] = $mail;
                    }
                }
            }
            $liste = new TelephonesFiness;
            $opposes = $liste->opposes(array_values(array_unique($variantes)));
            $emailsOpposes = $liste->emailsOpposes(array_values(array_unique($emails)));

            foreach ($lot as $id => $x) {
                $bloque = false;
                foreach ([$x['plan']['phone'] ?? null, $x['fiche']['phone']] as $tel) {
                    if (is_string($tel) && $tel !== '' && array_intersect(ListeSuppression::variantesTelephone($tel), $opposes) !== []) {
                        $bloque = true;
                    }
                }
                foreach ([$x['plan']['email_generic'] ?? null, $x['fiche']['email']] as $mail) {
                    if (is_string($mail) && $mail !== '' && isset($emailsOpposes[mb_strtolower(trim($mail))])) {
                        $bloque = true;
                    }
                }
                if ($bloque) {
                    $bilan['opposition']++;

                    continue;
                }
                foreach (['email_generic' => 'emails', 'phone' => 'telephones', 'site_candidat' => 'sites'] as $col => $nom) {
                    if (! isset($x['plan'][$col])) {
                        continue;
                    }
                    $cle = $col === 'site_candidat' ? 'sites_a_noter' : $nom . '_a_ajouter';
                    $fait = $col === 'site_candidat' ? 'sites_notes' : $nom . '_ajoutes';
                    $bilan[$cle]++;
                    if ($appliquer) {
                        $bilan[$fait] += $this->ecrire($workspaceId, (int) $id, $col, $x['plan'][$col]);
                    }
                }
            }
        });
    }

    /** UPDATE conditionnel (champ vide, fiche ouverte, non protégée, origine libre) : 1 si la fiche a été complétée. */
    private function ecrire(string $workspaceId, int $id, string $col, string $valeur): int
    {
        $pdo = DB::getPdo();
        $q = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $id)
            ->whereNull('deleted_at')->whereNull('insee_ferme_le')
            ->whereRaw('NOT ' . SelectionEnrichissement::PHYSIQUE_SQL)
            ->whereRaw(FichesProtegees::conditionSql('companies.id', null, 'companies.workspace_id'));

        $objet = static fn (string $colonne): string => "CASE WHEN jsonb_typeof({$colonne}) = 'object' THEN {$colonne} ELSE '{}'::jsonb END";
        $entree = $col === 'site_candidat' ? 'site_candidat' : $col;
        // contacts_osm_siret : fusion champ par champ, avec licence ODbL et attribution ; jamais d'écrasement.
        $osm = $objet("metadata->'" . self::CLE . "'") . ' || jsonb_build_object(' . $pdo->quote($entree) . '::text, '
            . $pdo->quote($col === 'site_candidat' ? $valeur : now()->toDateString()) . '::text, \'licence\', '
            . $pdo->quote(ContactsOsm::LICENCE) . '::text, \'attribution\', ' . $pdo->quote(ContactsOsm::ATTRIBUTION) . '::text)';
        $meta = $objet('metadata') . ' || jsonb_build_object(' . $pdo->quote(self::CLE) . "::text, {$osm})";

        if ($col === 'site_candidat') {
            // Aucun champ de la fiche n'est vidé ni rempli : seul le candidat est noté, `website` reste intact.
            $q->where(fn ($w) => $w->whereNull('website')->orWhereRaw("btrim(website) = ''"));

            return $q->update(['metadata' => DB::raw($meta), 'updated_at' => now()]);
        }

        $protegees = implode(', ', array_map(static fn (string $o): string => $pdo->quote($o), Taxonomy::FIELD_ORIGINS_PROTEGEES));
        $q->whereRaw("COALESCE(field_origins->>{$pdo->quote($col)}, '') NOT IN ({$protegees})")
            ->where(fn ($w) => $w->whereNull($col)->orWhereRaw("btrim({$col}) = ''"));
        if ($col === 'email_generic') {
            $meta .= " || jsonb_build_object('email_source', 'osm', 'email_statut', 'non_verifie')";
        }

        return $q->update([
            $col => $valeur,
            'field_origins' => DB::raw($objet('field_origins') . " || jsonb_build_object({$pdo->quote($col)}::text, '" . self::SOURCE . "'::text)"),
            'metadata' => DB::raw($meta),
            'updated_at' => now(),
        ]);
    }

    /** @return \Generator<array{siret: string, tels: list<string>, email: string, site: string}> */
    private static function lire(string $fichier): \Generator
    {
        $f = new SplFileObject($fichier, 'r');
        $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $f->setCsvControl(',', '"', '');
        $entete = null;
        foreach ($f as $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }
            if ($entete === null) {
                $entete = array_map(static fn ($c): string => trim(ltrim((string) $c, "\xEF\xBB\xBF")), $row);
                $manque = array_diff(self::COLONNES, $entete);
                if ($manque !== []) {
                    throw new InvalidArgumentException('Colonnes manquantes : ' . implode(', ', $manque));
                }

                continue;
            }
            $v = [];
            foreach (self::COLONNES as $c) {
                $i = array_search($c, $entete, true);
                $v[$c] = $i === false ? '' : trim((string) ($row[$i] ?? ''));
            }
            $tels = [];
            foreach (preg_split('/[;|]/', $v['telephone']) ?: [] as $brut) {
                $t = TelephonesFiness::normaliser($brut);
                if ($t !== null) {
                    $tels[$t] = $t;
                }
            }
            yield ['siret' => $v['siret'], 'tels' => array_values($tels), 'email' => $v['email'], 'site' => $v['site']];
        }
    }
}
