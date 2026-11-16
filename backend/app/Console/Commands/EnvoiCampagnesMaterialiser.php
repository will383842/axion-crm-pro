<?php

namespace App\Console\Commands;

use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\MaterialisationRefusee;
use App\Envoi\Campagnes\ReetalementStock;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Matérialise les campagnes programmées et re-étale le stock, espace par espace (lot X1-L4-C). INERTE : aucun envoi, aucun passage à « en_cours »,
 * et la tâche tourne porte fermée (sinon le stock arriverait en bloc à l'ouverture). Elle se garde elle-même : schéma P2 absent => rien à faire.
 * Sortie et journal : compteurs et identifiants seulement, jamais d'adresse ni d'empreinte.
 */
class EnvoiCampagnesMaterialiser extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:campagnes:materialiser';

    public const BORNE_SECONDES = 50;

    public const CAMPAGNES_PAR_TOUR = 20;

    /** Réservé aux tests : horloge injectée. */
    public static ?Closure $horloge = null;

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Matérialise les campagnes programmées (inscriptions étalées, inertes) et re-étale le stock — aucun envoi';

    /** Colonne, table et index « une inscription active par adresse » (P2) présents et VALIDES : jamais d'exception SQL pendant un déploiement. */
    public static function schemaPret(): bool
    {
        try {
            if (! Schema::hasTable('envoi_campagnes_materialisations') || ! Schema::hasColumn('envoi_inscriptions', 'population')) {
                return false;
            }
            $i = DB::selectOne("SELECT x.indisvalid FROM pg_index x JOIN pg_class c ON c.oid = x.indexrelid
                                 WHERE c.relname = 'envoi_inscriptions_adresse_active_unique' AND x.indrelid = to_regclass('envoi_inscriptions')");

            return $i !== null && in_array($i->indisvalid, [true, 't', 1, '1'], true);
        } catch (Throwable) {
            return false;
        }
    }

    public function handle(MaterialisationCampagne $materialisation, ReetalementStock $reetalement): int
    {
        if (! self::schemaPret()) {
            $this->info('schéma P2 absent : rien à faire.');

            return self::SUCCESS;
        }
        $horloge = self::$horloge ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
        $maintenant = $horloge();
        $debut = $maintenant->getTimestamp();
        $enRetard = static fn (): bool => $horloge()->getTimestamp() - $debut >= self::BORNE_SECONDES;
        $b = ['espaces' => 0, 'campagnes' => 0, 'inscrits' => 0, 'bloquees' => 0, 'reglage_non_applique' => 0, 'a_reessayer' => 0, 'reetalees' => 0, 'erreurs' => 0, 'hors_borne' => 0];

        foreach (DB::table('workspaces')->whereNull('deleted_at')->orderBy('id')->pluck('id') as $espace) {
            $ws = (string) $espace;
            if ($enRetard()) {
                $b['hors_borne']++;

                continue;
            }
            $b['espaces']++;
            try {
                $b['reetalees'] += $reetalement->reetaler($ws, $maintenant);
            } catch (Throwable $e) {
                $b['erreurs']++;
                Log::error('envoi.campagnes : re-étalement en erreur.', ['workspace_id' => $ws, 'exception' => $e::class]);
            }
            $travaillees = 0;
            foreach ($this->campagnes($ws) as $id) {
                if ($travaillees >= self::CAMPAGNES_PAR_TOUR || $enRetard()) {
                    $b['hors_borne']++;

                    continue;
                }
                $b['campagnes']++;
                try {
                    $r = $materialisation->materialiser($ws, $id, $maintenant);
                    $travaillees++;
                    $b['inscrits'] += $r->inscrits;
                    $b['bloquees'] += $r->etat === 'bloquee' ? 1 : 0;
                } catch (MaterialisationRefusee $e) {
                    if ($e->motif === 'reglage_non_applique') {
                        $b['reglage_non_applique']++; // ne consomme pas le quota : elle repartira quand le transport appliquera ses réglages

                        continue;
                    }
                    $travaillees++;
                    $this->compter($b, $ws, $id, $e, $e->reessayable);
                } catch (Throwable $e) {
                    $travaillees++;
                    $this->compter($b, $ws, $id, $e, false);
                }
            }
        }
        $this->info(implode(' ', array_map(static fn (string $k, int $v): string => "{$k}={$v}", array_keys($b), $b)));
        Log::info('envoi.campagnes : bilan.', $b);

        return $b['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Campagnes programmées ou en cours dont la matérialisation n'est pas terminale, par identifiant.
     *
     * @return list<string>
     */
    private function campagnes(string $ws): array
    {
        return WorkspaceContext::run($ws, fn (): array => array_map('strval', DB::table('envoi_campagnes as c')->where('c.workspace_id', $ws)->whereIn('c.statut', ['programmee', 'en_cours'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('envoi_campagnes_materialisations as m')->whereColumn('m.campagne_id', 'c.id')->whereIn('m.etat', ['terminee', 'bloquee']))
            ->orderBy('c.id')->pluck('c.id')->all()));
    }

    /** Refus réessayable : compté, pas une erreur. Sinon : erreur, journal SANS adresse (identifiants, classe et motif seulement). */
    private function compter(array &$b, string $ws, string $campagne, Throwable $e, bool $reessayable): void
    {
        if ($reessayable) {
            $b['a_reessayer']++;

            return;
        }
        $b['erreurs']++;
        Log::error('envoi.campagnes : campagne en erreur.', ['workspace_id' => $ws, 'campagne_id' => $campagne, 'exception' => $e::class, 'motif' => $e instanceof MaterialisationRefusee ? $e->motif : null]);
    }
}
