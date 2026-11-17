<?php

namespace App\ProspectionLinkedIn;

use App\Crm\Campagnes\GardePresse;
use App\Crm\FichesProtegees;
use App\Crm\Ingest\DossierClientIngest;
use App\Crm\Opposition\FormesAdresse;
use App\Crm\Opposition\StatutOpposition;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * PONT-LINKEDIN LI-B2 — contrôles d'un lot de prospects avant la file du jour, EN UN PASSAGE, ÉCHEC FERMÉ : toute erreur de
 * lecture ⇒ aucun prospect rendu, aucun état changé, une alerte journalisée. Lectures seules (registres, demandes RGPD, fiches,
 * inscriptions d'envoi) ; les seules écritures sont le journal (`controle_refuse`, `opposition`) et le passage à `ecarte`.
 * Un prospect opposé n'est jamais réinscrit ni contacté. Aucun appel vers LinkedIn.
 */
final class ControlesProspect
{
    /** Ordre de priorité des refus non définitifs. */
    public const MOTIFS = ['arret', 'rgpd_en_cours', 'presse', 'client', 'courriel_recent'];

    public function __construct(
        private readonly ?StatutOpposition $opposition = null,
        private readonly ?JournalProspection $journal = null,
        private readonly ?Arret $arret = null,
    ) {}

    /**
     * @param  list<\stdClass>  $prospects  lignes de `pl_prospects` (id, expediteur_user_id, linkedin_slug, contact_id, siren)
     * @return array{ok: list<string>, refuses: array<string, string>, ecartes: list<string>, erreur: bool} `ok` garde l'ordre reçu
     */
    public function verifier(string $workspaceId, array $prospects, ?CarbonImmutable $maintenant = null): array
    {
        $vide = ['ok' => [], 'refuses' => [], 'ecartes' => [], 'erreur' => true];
        if ($prospects === []) {
            return ['ok' => [], 'refuses' => [], 'ecartes' => [], 'erreur' => false];
        }
        $t = $maintenant ?? CarbonImmutable::now((string) config('app.timezone'));

        try {
            // Lectures dans un point de sauvegarde : une erreur SQL n'avorte pas la transaction englobante (l'alerte reste écrivable).
            // Sous WorkspaceContext::run : sans contexte d'espace, la RLS rendrait zéro ligne sans erreur (échec OUVERT).
            [$opposes, $refus] = DB::transaction(fn (): array => WorkspaceContext::run($workspaceId, function () use ($workspaceId, $prospects, $t): array {
                $ident = $this->identites($workspaceId, $prospects);

                return [$this->opposesAvec($workspaceId, $prospects, $ident), $this->refusAvec($workspaceId, $prospects, $ident, $t)];
            }));
        } catch (Throwable) {
            $this->alerte($workspaceId);

            return $vide;
        }

        $ok = [];
        $ecartes = [];
        $refuses = [];
        foreach ($prospects as $p) {
            $id = (string) $p->id;
            if (isset($opposes[$id])) {
                $ecartes[] = $id;
            } elseif (isset($refus[$id])) {
                $refuses[$id] = $refus[$id];
            } else {
                $ok[] = $id;
            }
        }

        try {
            foreach ($ecartes as $id) {
                $this->ecarter($workspaceId, $id);
            }
            foreach ($refuses as $id => $motif) {
                if ($motif === 'arret') {
                    continue; // l'arrêt est déjà journalisé à sa pose
                }
                ($this->journal ?? new JournalProspection)->ecrire(
                    $workspaceId,
                    $id,
                    'controle_refuse',
                    'systeme',
                    'controle',
                    'controle-' . $id . '-' . $motif . '-' . $t->format('Ymd'),
                    ['motif' => $motif],
                );
            }
        } catch (Throwable) {
            $this->alerte($workspaceId);

            return $vide; // une écriture a échoué : on ne propose rien (les écritures faites sont journalisées et sûres)
        }

        return ['ok' => $ok, 'refuses' => $refuses, 'ecartes' => $ecartes, 'erreur' => false];
    }

    /**
     * Relecture de l'opposition seule (profil, adresse et SIREN de la fiche rattachée), pour le moment juste avant l'écriture.
     *
     * @param  list<\stdClass>  $prospects
     * @return list<string> ids des prospects opposés
     *
     * @throws Throwable lecture impossible : l'appelant refuse (échec fermé)
     */
    public function opposes(string $workspaceId, array $prospects): array
    {
        // Point de sauvegarde : une lecture en erreur est annulée proprement ; l'exception, elle, remonte toujours.
        return DB::transaction(fn (): array => WorkspaceContext::run($workspaceId, fn (): array => array_keys($this->opposesAvec($workspaceId, $prospects, $this->identites($workspaceId, $prospects)))));
    }

    /** Passe le prospect à `ecarte` (définitif) et journalise (une seule fois). */
    public function ecarter(string $workspaceId, string $prospectId): void
    {
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $prospectId): void {
            DB::transaction(function () use ($workspaceId, $prospectId): void {
                $p = DB::table('pl_prospects')->where('workspace_id', $workspaceId)->where('id', $prospectId)->lockForUpdate()->first();
                if ($p === null) {
                    return;
                }
                $avant = (string) $p->etat;
                $r = EtatsProspect::appliquer($avant, 'opposition');
                ($this->journal ?? new JournalProspection)->ecrire(
                    $workspaceId,
                    $prospectId,
                    'opposition',
                    'systeme',
                    'controle',
                    'ecarte-' . $prospectId,
                    ['motif' => 'opposition'],
                    $avant,
                    $r['etat'],
                    $r['sans_effet'],
                );
                if ($r['etat'] !== $avant) {
                    DB::table('pl_prospects')->where('workspace_id', $workspaceId)->where('id', $prospectId)->update(['etat' => $r['etat'], 'maj_le' => now()]);
                }
            });
        });
    }

    /**
     * Identité de contrôle de chaque prospect : adresse et SIREN de la fiche rattachée, signaux de fiche.
     *
     * @param  list<\stdClass>  $prospects
     * @return array<string, array{contact_id: ?int, email: ?string, siren: ?string, presse: bool, client: bool}>
     */
    private function identites(string $workspaceId, array $prospects): array
    {
        $contactIds = [];
        $sirens = [];
        foreach ($prospects as $p) {
            if ($p->contact_id !== null) {
                $contactIds[] = (int) $p->contact_id;
            }
            if ($p->siren !== null) {
                $sirens[] = trim((string) $p->siren);
            }
        }

        $client = DossierClientIngest::DISCOVERY_SOURCE;
        $tag = FichesProtegees::TAG_CONSOLE_CLIENT;
        $parContact = [];
        if ($contactIds !== []) {
            $marques = implode(', ', array_fill(0, count($contactIds), '?'));
            foreach (DB::select(
                'SELECT c.id, c.email::text AS email, co.siren::text AS siren,
                        ' . GardePresse::estContactPresseSql('c') . ' AS contact_presse,
                        NOT ' . GardePresse::conditionSql('c.company_id') . ' AS fiche_presse,
                        (c.discovery_source = ? OR EXISTS (SELECT 1 FROM company_tag lc_ct JOIN tags lc_t ON lc_t.id = lc_ct.tag_id
                                                            WHERE lc_ct.company_id = c.company_id AND lc_t.slug = ?)) AS client
                   FROM contacts c LEFT JOIN companies co ON co.id = c.company_id AND co.workspace_id = c.workspace_id
                  WHERE c.workspace_id = ? AND c.id IN (' . $marques . ')',
                array_merge([$client, $tag, $workspaceId], $contactIds),
            ) as $l) {
                $parContact[(int) $l->id] = $l;
                if ($l->siren !== null) {
                    $sirens[] = trim((string) $l->siren);
                }
            }
        }

        $parSiren = [];
        $sirens = array_values(array_unique($sirens));
        if ($sirens !== []) {
            $marques = implode(', ', array_fill(0, count($sirens), '?'));
            foreach (DB::select(
                'SELECT co.siren::text AS siren,
                        NOT ' . GardePresse::conditionSql('co.id') . ' AS fiche_presse,
                        EXISTS (SELECT 1 FROM company_tag lc_ct JOIN tags lc_t ON lc_t.id = lc_ct.tag_id
                                 WHERE lc_ct.company_id = co.id AND lc_t.slug = ?) AS client
                   FROM companies co WHERE co.workspace_id = ? AND co.siren IN (' . $marques . ')',
                array_merge([$tag, $workspaceId], $sirens),
            ) as $l) {
                $s = trim((string) $l->siren);
                $parSiren[$s]['presse'] = ($parSiren[$s]['presse'] ?? false) || self::vrai($l->fiche_presse);
                $parSiren[$s]['client'] = ($parSiren[$s]['client'] ?? false) || self::vrai($l->client);
            }
        }

        $sortie = [];
        foreach ($prospects as $p) {
            $c = $p->contact_id !== null ? ($parContact[(int) $p->contact_id] ?? null) : null;
            $siren = $p->siren !== null ? trim((string) $p->siren) : ($c !== null && $c->siren !== null ? trim((string) $c->siren) : null);
            $email = $c !== null && is_string($c->email) && trim($c->email) !== '' ? $c->email : null;
            $sortie[(string) $p->id] = [
                'contact_id' => $c !== null ? (int) $c->id : null,
                'email' => $email,
                'siren' => $siren,
                'presse' => ($c !== null && (self::vrai($c->contact_presse) || self::vrai($c->fiche_presse))) || ($siren !== null && ($parSiren[$siren]['presse'] ?? false)),
                'client' => ($c !== null && self::vrai($c->client)) || ($siren !== null && ($parSiren[$siren]['client'] ?? false)),
            ];
        }

        return $sortie;
    }

    /**
     * @param  list<\stdClass>  $prospects
     * @param  array<string, array{contact_id: ?int, email: ?string, siren: ?string, presse: bool, client: bool}>  $ident
     * @return array<string, true> ids opposés
     */
    private function opposesAvec(string $workspaceId, array $prospects, array $ident): array
    {
        $opposition = $this->opposition ?? new StatutOpposition;
        $slugs = array_values(array_unique(array_map(static fn (\stdClass $p): string => (string) $p->linkedin_slug, $prospects)));
        $parProfil = $opposition->profilsOpposes($workspaceId, $slugs);

        $lignes = [];
        foreach ($prospects as $p) {
            $i = $ident[(string) $p->id];
            if ($i['email'] !== null || $i['siren'] !== null) {
                $lignes[(string) $p->id] = ['email' => $i['email'], 'siren' => $i['siren']];
            }
        }
        $reponse = $lignes === [] ? [] : $opposition->pourLignes($workspaceId, $lignes);

        $opposes = [];
        foreach ($prospects as $p) {
            $id = (string) $p->id;
            $profilOppose = (bool) ($parProfil[(string) $p->linkedin_slug] ?? true); // absent : opposé
            $ligneOpposee = isset($lignes[$id]) && (bool) ($reponse[$id]['opposee'] ?? true); // absent : opposé
            if ($profilOppose || $ligneOpposee) {
                $opposes[$id] = true;
            }
        }

        return $opposes;
    }

    /**
     * @param  list<\stdClass>  $prospects
     * @param  array<string, array{contact_id: ?int, email: ?string, siren: ?string, presse: bool, client: bool}>  $ident
     * @return array<string, string> id => motif de refus (le premier dans l'ordre de MOTIFS)
     */
    private function refusAvec(string $workspaceId, array $prospects, array $ident, CarbonImmutable $t): array
    {
        $arret = $this->arret ?? new Arret;
        $arretParExpediteur = [];
        $formesParId = [];
        $toutes = [];
        $empreintesParId = [];
        $contactIds = [];
        foreach ($prospects as $p) {
            $id = (string) $p->id;
            $e = (string) $p->expediteur_user_id;
            $arretParExpediteur[$e] ??= $arret->actif($workspaceId, $e);
            $email = $ident[$id]['email'];
            if ($email !== null) {
                $formesParId[$id] = FormesAdresse::variantes($email);
                array_push($toutes, ...$formesParId[$id]);
                $empreintesParId[$id] = array_map(static fn (string $f): string => ListeSuppression::empreinte($f), $formesParId[$id]);
            }
            if ($ident[$id]['contact_id'] !== null) {
                $contactIds[] = $ident[$id]['contact_id'];
            }
        }

        $rgpd = [];
        $toutes = array_values(array_unique($toutes));
        if ($toutes !== []) {
            foreach (DB::table('rgpd_requests')->where('workspace_id', $workspaceId)->whereIn('status', ['pending', 'processing'])
                ->whereIn('subject_email', $toutes)->pluck('subject_email') as $a) {
                $rgpd[mb_strtolower(trim((string) $a))] = true;
            }
        }

        // Contacté par courriel récemment : inscriptions de séquence et registre des messages envoyés (lecture seule).
        $depuis = $t->subDays((int) config('prospection_linkedin.jours_contact_courriel', 90))->toIso8601String();
        $empreintes = array_values(array_unique(array_merge(...array_values($empreintesParId) ?: [[]])));
        $contactes = [];
        if ($empreintes !== [] || $contactIds !== []) {
            $ins = DB::table('envoi_inscriptions')->where('workspace_id', $workspaceId)->where('inscrite_le', '>=', $depuis)
                ->where(static function ($q) use ($contactIds, $empreintes): void {
                    $q->whereIn('contact_id', $contactIds === [] ? [0] : $contactIds)->orWhereIn('empreinte_adresse', $empreintes === [] ? [''] : $empreintes);
                })->get(['contact_id', 'empreinte_adresse']);
            foreach ($ins as $l) {
                $contactes['c' . (int) $l->contact_id] = true;
                $contactes['h' . trim((string) $l->empreinte_adresse)] = true;
            }
        }
        if ($empreintes !== []) {
            foreach (DB::table('envoi_messages_envoyes')->where('workspace_id', $workspaceId)->where('cree_le', '>=', $depuis)
                ->whereIn('empreinte_destinataire', $empreintes)->pluck('empreinte_destinataire') as $h) {
                $contactes['h' . trim((string) $h)] = true;
            }
        }

        $refus = [];
        foreach ($prospects as $p) {
            $id = (string) $p->id;
            $i = $ident[$id];
            $drapeaux = [
                'arret' => $arretParExpediteur[(string) $p->expediteur_user_id],
                'rgpd_en_cours' => array_intersect(array_map('mb_strtolower', $formesParId[$id] ?? []), array_keys($rgpd)) !== [],
                'presse' => $i['presse'],
                'client' => $i['client'],
                'courriel_recent' => ($i['contact_id'] !== null && isset($contactes['c' . $i['contact_id']]))
                    || array_filter($empreintesParId[$id] ?? [], static fn (string $h): bool => isset($contactes['h' . $h])) !== [],
            ];
            foreach (self::MOTIFS as $motif) {
                if ($drapeaux[$motif]) {
                    $refus[$id] = $motif;
                    break;
                }
            }
        }

        return $refus;
    }

    private function alerte(string $workspaceId): void
    {
        try {
            ($this->journal ?? new JournalProspection)->ecrire(
                $workspaceId,
                null,
                'alerte',
                'systeme',
                'controle',
                'alerte-controle-' . Str::uuid(),
                ['motif' => 'controle_illisible'],
            );
        } catch (Throwable) {
            // L'alerte elle-même a échoué : rien n'est proposé de toute façon (échec fermé).
        }
    }

    private static function vrai(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }
}
