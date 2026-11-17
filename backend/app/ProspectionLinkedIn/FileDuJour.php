<?php

namespace App\ProspectionLinkedIn;

use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * PONT-LINKEDIN LI-B2 — construit la file du jour « prêt à cliquer » d'un expéditeur (voie S : le fondateur clique lui-même ;
 * le CRM prépare et contrôle, jamais d'appel vers LinkedIn). Candidats `qualifie` / `controle`, `plus_tard` échus et
 * `invitation_retiree` depuis au moins 21 jours ; score au moins égal au seuil ; tri score puis ancienneté ; au plus
 * min(restant du jour, restant de la semaine). L'arrêt et l'opposition sont RELUS dans la transaction d'écriture, juste avant
 * le passage à `en_file`. Échec fermé : toute erreur ⇒ file vide, alerte, aucun état changé. Rien n'est supprimé.
 */
final class FileDuJour
{
    private const ETATS_ELIGIBLES = ['qualifie', 'controle', 'plus_tard', 'invitation_retiree'];

    private const LIMITE_CANDIDATS = 300;

    /** @param  ?Closure  $avantEcriture  point d'appel (tests) entre les contrôles et l'écriture finale */
    public function __construct(
        private readonly ?ControlesProspect $controles = null,
        private readonly ?Plafonds $plafonds = null,
        private readonly ?Arret $arret = null,
        private readonly ?Closure $avantEcriture = null,
    ) {}

    /**
     * @return array{prospects: list<string>, raison: ?string, alerte: bool} raison : arret | plafond | aucun | controle_illisible
     */
    public function construire(string $workspaceId, string $expediteurId, ?CarbonImmutable $maintenant = null): array
    {
        $t = $maintenant ?? CarbonImmutable::now((string) config('app.timezone'));
        $controles = $this->controles ?? new ControlesProspect;
        $arret = $this->arret ?? new Arret;

        try {
            return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $expediteurId, $t, $controles, $arret): array {
                if ($arret->actif($workspaceId, $expediteurId)) {
                    return self::vide('arret');
                }
                $restants = ($this->plafonds ?? new Plafonds)->restants($workspaceId, $expediteurId, $t);
                $n = min($restants['jour'], $restants['semaine']);
                if ($n <= 0) {
                    return self::vide('plafond');
                }

                $candidats = $this->candidats($workspaceId, $expediteurId, $t);
                if ($candidats === []) {
                    return self::vide('aucun');
                }
                $c = $controles->verifier($workspaceId, $candidats, $t);
                if ($c['erreur']) {
                    return self::vide('controle_illisible', true); // l'alerte est déjà journalisée
                }
                $retenus = array_slice($c['ok'], 0, $n);
                if ($retenus === []) {
                    return self::vide('aucun');
                }
                if ($this->avantEcriture !== null) {
                    ($this->avantEcriture)();
                }

                $parId = [];
                foreach ($candidats as $p) {
                    $parId[(string) $p->id] = $p;
                }

                return $this->ecrire($workspaceId, $expediteurId, array_map(static fn (string $id): \stdClass => $parId[$id], $retenus), $controles, $arret);
            });
        } catch (Throwable) {
            try {
                (new JournalProspection)->ecrire($workspaceId, null, 'alerte', 'systeme', 'file_du_jour', 'alerte-file-' . Str::uuid(), ['motif' => 'file_illisible']);
            } catch (Throwable) {
                // Échec fermé de toute façon : rien n'est proposé.
            }

            return self::vide('controle_illisible', true);
        }
    }

    /**
     * Relit l'arrêt et l'opposition dans la transaction, puis passe les prospects retenus à `en_file`.
     *
     * @param  list<\stdClass>  $retenus
     * @return array{prospects: list<string>, raison: ?string, alerte: bool}
     */
    private function ecrire(string $workspaceId, string $expediteurId, array $retenus, ControlesProspect $controles, Arret $arret): array
    {
        return DB::transaction(function () use ($workspaceId, $expediteurId, $retenus, $controles, $arret): array {
            if ($arret->actif($workspaceId, $expediteurId)) {
                return self::vide('arret');
            }
            $opposes = array_flip($controles->opposes($workspaceId, $retenus)); // lève si illisible : tout est annulé
            $file = [];
            foreach ($retenus as $p) {
                $id = (string) $p->id;
                if (isset($opposes[$id])) {
                    $controles->ecarter($workspaceId, $id);

                    continue;
                }
                $mis = WorkspaceContext::run($workspaceId, static function () use ($workspaceId, $id): bool {
                    $ligne = DB::table('pl_prospects')->where('workspace_id', $workspaceId)->where('id', $id)->lockForUpdate()->first();
                    if ($ligne === null || ! in_array((string) $ligne->etat, self::ETATS_ELIGIBLES, true)) {
                        return false; // changé entre-temps (écarté, déjà en file…) : on n'y touche pas
                    }
                    DB::table('pl_prospects')->where('workspace_id', $workspaceId)->where('id', $id)->update(['etat' => 'en_file', 'maj_le' => now()]);

                    return true;
                });
                if ($mis) {
                    $file[] = $id;
                }
            }

            return ['prospects' => $file, 'raison' => $file === [] ? 'aucun' : null, 'alerte' => false];
        });
    }

    /** @return list<\stdClass> */
    private function candidats(string $workspaceId, string $expediteurId, CarbonImmutable $t): array
    {
        $aujourdhui = $t->toDateString();
        $lignes = WorkspaceContext::run($workspaceId, fn () => DB::table('pl_prospects')
            ->where('workspace_id', $workspaceId)->where('expediteur_user_id', $expediteurId)
            ->where('score', '>=', (int) config('prospection_linkedin.seuil_score', 60))
            ->where(static function ($q) use ($aujourdhui): void {
                $q->whereIn('etat', ['qualifie', 'controle'])
                    ->orWhere(static fn ($r) => $r->where('etat', 'plus_tard')->whereNotNull('relance_le')->where('relance_le', '<=', $aujourdhui))
                    ->orWhere('etat', 'invitation_retiree');
            })
            ->orderByDesc('score')->orderBy('cree_le')->orderBy('id')->limit(self::LIMITE_CANDIDATS)->get()->all());

        // Une invitation retirée ne repart jamais avant le délai (21 jours) ; sans trace de retrait, on n'y touche pas.
        $retires = array_map(static fn (\stdClass $p): string => (string) $p->id, array_filter($lignes, static fn (\stdClass $p): bool => $p->etat === 'invitation_retiree'));
        $retraits = [];
        if ($retires !== []) {
            $retraits = WorkspaceContext::run($workspaceId, static fn () => DB::table('pl_journal')->where('workspace_id', $workspaceId)
                ->where('evenement', 'invitation_retiree')->whereIn('prospect_id', $retires)->groupBy('prospect_id')
                ->selectRaw('prospect_id, max(recu_le) as dernier')->pluck('dernier', 'prospect_id')->all());
        }
        $limite = $t->subDays((int) config('prospection_linkedin.delai_reinvitation_jours', 21));

        return array_values(array_filter($lignes, static function (\stdClass $p) use ($retraits, $limite): bool {
            if ($p->etat !== 'invitation_retiree') {
                return true;
            }
            $dernier = $retraits[(string) $p->id] ?? null;

            return $dernier !== null && CarbonImmutable::parse((string) $dernier)->lessThanOrEqualTo($limite);
        }));
    }

    /** @return array{prospects: list<string>, raison: ?string, alerte: bool} */
    private static function vide(string $raison, bool $alerte = false): array
    {
        return ['prospects' => [], 'raison' => $raison, 'alerte' => $alerte];
    }
}
