<?php

namespace App\Envoi\Reglages;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\Seuils;
use App\Envoi\Depot\DepotSocle;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Réglages d'envoi (R14b) : seuils (`envoi_seuils`, via DepotChauffe), clés bornées (`envoi_reglages`), identité versionnée,
 * mode absence. Ne touche ni `envoi_drapeaux` ni aucun serveur. L'absence n'agit QUE sur les pauses de motif `absence`.
 */
final class DepotReglages
{
    public function __construct(private readonly DepotChauffe $chauffe, private readonly DepotSocle $socle) {}

    /** @return array<string, mixed> les règles effectives (défauts durcis des réglages de l'espace) */
    public function effectives(string $ws): array
    {
        $v = ReglesPrudence::defauts();
        foreach (DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', 'like', 'regles.%')->pluck('valeur', 'cle') as $cle => $json) {
            $champ = substr((string) $cle, 7);
            if (array_key_exists($champ, $v)) {
                $v[$champ] = json_decode((string) $json, true);
            }
        }
        $refus = Seuils::resserre('rebonds_durs_taux_max', Seuils::REBONDS_DURS_TAUX_MAX, $this->chauffe->seuil($ws, 'rebonds_durs_taux_max'));
        $v['pause_si_refus_pct'] = round($refus * 100, 4);
        $v['frein_reponses_par_jour'] = (int) Seuils::resserre('humain_pause_a', (float) Seuils::HUMAIN_PAUSE_A, $this->chauffe->seuil($ws, 'humain_pause_a'));

        return $v;
    }

    /**
     * Applique un durcissement (verrou de l'espace, tout ou rien).
     *
     * @param  array<string, mixed>  $demande
     *
     * @throws AssouplissementRefuse
     */
    public function durcir(string $ws, array $demande, string $auteur): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $demande, $auteur): void {
            DB::transaction(function () use ($ws, $demande, $auteur): void {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_reglages|' . $ws]);
                $actuel = $this->effectives($ws);
                $nouveau = ReglesPrudence::durcir($actuel, $demande);
                foreach (array_keys(ReglesPrudence::sens()) as $champ) {
                    if (! array_key_exists($champ, $demande) || $nouveau[$champ] == $actuel[$champ]) {
                        continue;
                    }
                    match ($champ) {
                        'pause_si_refus_pct' => $this->chauffe->fixerSeuil($ws, 'rebonds_durs_taux_max', (float) $nouveau[$champ] / 100, $auteur),
                        'frein_reponses_par_jour' => $this->chauffe->fixerSeuil($ws, 'humain_pause_a', (float) $nouveau[$champ], $auteur),
                        default => $this->poser($ws, 'regles.' . $champ, json_encode($nouveau[$champ], JSON_THROW_ON_ERROR), $auteur),
                    };
                }
            });
        });
    }

    public function lire(string $ws, string $cle): ?string
    {
        $v = WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->where('workspace_id', $ws)->where('cle', $cle)->value('valeur'));

        return $v === null ? null : (string) $v;
    }

    public function poser(string $ws, string $cle, string $valeur, string $auteur): void
    {
        WorkspaceContext::run($ws, fn () => DB::statement(
            'INSERT INTO envoi_reglages (workspace_id, cle, valeur, auteur) VALUES (?, ?, ?, ?)
             ON CONFLICT (workspace_id, cle) DO UPDATE SET valeur = EXCLUDED.valeur, auteur = EXCLUDED.auteur, mis_a_jour_le = now()',
            [$ws, $cle, $valeur, mb_substr($auteur, 0, 100)],
        ));
    }

    /** @return array{actif: bool, du: ?string, au: ?string} */
    public function absence(string $ws): array
    {
        $actif = WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $ws)->where('motif', 'absence')->whereNull('levee_le')->exists());
        $dates = json_decode($this->lire($ws, 'absence.dates') ?? '{}', true);

        return ['actif' => $actif, 'du' => $dates['du'] ?? null, 'au' => $dates['au'] ?? null];
    }

    /**
     * Ouvre (une seule pause active) ou lève la pause de motif `absence` UNIQUEMENT.
     *
     * @return array{actif: bool, du: ?string, au: ?string}
     */
    public function reglerAbsence(string $ws, bool $actif, ?string $du, ?string $au, string $auteur): array
    {
        WorkspaceContext::run($ws, function () use ($ws, $actif, $du, $au, $auteur): void {
            DB::transaction(function () use ($ws, $actif, $du, $au, $auteur): void {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_pauses|' . $ws]);
                $maintenant = new DateTimeImmutable('now');
                if ($actif) {
                    $this->chauffe->ouvrirPause($ws, 'absence', null, $maintenant);
                } else {
                    foreach ($this->chauffe->pausesActives($ws, $maintenant) as $p) {
                        if ($p->motif === 'absence') {
                            $this->socle->journaliser($ws, 'pause_levee', $auteur, ['pause' => $p->id, 'motif' => 'absence']); // trace d'abord (déclencheur de L7)
                            $this->chauffe->leverPause($ws, (int) $p->id, $auteur);
                        }
                    }
                }
                $this->poser($ws, 'absence.dates', json_encode(['du' => $du, 'au' => $au], JSON_THROW_ON_ERROR), $auteur);
            });
        });

        return $this->absence($ws);
    }

    /** @return array<string, mixed> dernière version de l'identité, ou version vide */
    public function identite(string $ws): array
    {
        $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_identite_versions')->where('workspace_id', $ws)->orderByDesc('id')->first());

        return [
            'raison_sociale' => (string) ($l->raison_sociale ?? ''), 'forme' => (string) ($l->forme ?? 'SAS'),
            'adresse_postale' => (string) ($l->adresse_postale ?? ''), 'signature' => (string) ($l->signature ?? ''),
            'lien_desinscription' => true, 'lien_rendez_vous' => $l->lien_rendez_vous ?? null, 'bloc_information' => (string) ($l->bloc_information ?? ''),
        ];
    }

    /** @param array<string, mixed> $i */
    public function enregistrerIdentite(string $ws, array $i, string $auteur): void
    {
        WorkspaceContext::run($ws, fn () => DB::table('envoi_identite_versions')->insert([
            'workspace_id' => $ws, 'raison_sociale' => $i['raison_sociale'], 'forme' => $i['forme'], 'adresse_postale' => $i['adresse_postale'],
            'signature' => $i['signature'], 'lien_rendez_vous' => $i['lien_rendez_vous'], 'bloc_information' => $i['bloc_information'],
            'auteur' => mb_substr($auteur, 0, 100),
        ]));
    }
}
