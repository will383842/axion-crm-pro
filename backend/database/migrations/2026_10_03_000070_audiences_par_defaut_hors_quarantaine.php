<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LES TROIS AUDIENCES PAR DÉFAUT NE COMPTENT PLUS LES ADRESSES EN QUARANTAINE
 * (lot N5 « filtre d'envoi », 03/10/2026).
 *
 * « Prospects contactables » (le compteur « joignables » de l'accueil),
 * « … — Île-de-France » et « Confiance email A (domaine = site) » retenaient
 * une fiche dès qu'elle portait une adresse (`has_email`) — y compris une
 * adresse tirée d'un site DEVINÉ non vérifié (`QuarantaineSite`). Elles
 * passent à `email_hors_quarantaine` ; « Confiance email A » exclut en plus
 * les fiches au site deviné non vérifié (`site_non_verifie` sous `not`) : son
 * « domaine = site » y est un domaine deviné, et la note A déjà écrite n'est
 * pas réécrite.
 *
 * Même patron que `2026_10_01_000021` :
 *  - une audience n'est touchée QUE si ses critères sont EXACTEMENT ceux
 *    laissés par le seeder et la migration 000021 (`CRITERES_D_ORIGINE`,
 *    figés ici), comparaison STRICTE sur une forme canonique. Une audience
 *    retouchée à l'écran n'est pas modifiée : elle est notée au journal ;
 *  - rejouable : une audience déjà passée n'est plus « d'origine » ;
 *  - `down()` rend les critères d'origine, et seulement aux audiences qui
 *    portent EXACTEMENT les critères posés ici.
 *
 * Les membres ne sont pas recalculés ici (le recalcul de 04:00,
 * `audiences:full-refresh`, s'en charge). En attendant, `refreshed_at` des
 * audiences modifiées repasse à NULL : l'accueil dit « pas encore calculé »
 * plutôt qu'un ancien chiffre qui compterait des adresses en quarantaine.
 * Aucune fiche, aucun membre, aucune adresse n'est effacé.
 *
 * `email_audiences` porte une RLS : espace par espace, contexte posé
 * LOCALEMENT à la transaction de la migration.
 */
return new class extends Migration
{
    /** L'exclusion des relations posée par 000021 — figée. */
    private const EXCLUSION = [
        'field' => 'relation_type',
        'op' => 'in',
        'value' => ['client', 'partenaire', 'presse_media', 'fournisseur', 'investisseur'],
    ];

    private const A_UN_EMAIL = ['field' => 'has_email', 'op' => 'eq', 'value' => true];

    private const HORS_QUARANTAINE = ['field' => 'email_hors_quarantaine', 'op' => 'eq', 'value' => true];

    private const SITE_NON_VERIFIE = ['field' => 'site_non_verifie', 'op' => 'eq', 'value' => true];

    private const PRET = ['field' => 'prospection_status', 'op' => 'eq', 'value' => 'ready_for_outreach'];

    private const IDF = ['field' => 'region_code', 'op' => 'eq', 'value' => '11'];

    private const A = ['field' => 'best_email_confidence', 'op' => 'eq', 'value' => 'A'];

    /**
     * Avant (seeder + 000021) => après. Figés ici : le seeder, lui, évolue.
     *
     * @return array<string, array{avant: array<string, mixed>, apres: array<string, mixed>}>
     */
    private static function definitions(): array
    {
        return [
            'Prospects contactables' => [
                'avant' => ['all' => [self::A_UN_EMAIL, self::PRET], 'not' => [self::EXCLUSION]],
                'apres' => ['all' => [self::HORS_QUARANTAINE, self::PRET], 'not' => [self::EXCLUSION]],
            ],
            'Prospects contactables — Île-de-France' => [
                'avant' => ['all' => [self::A_UN_EMAIL, self::PRET, self::IDF], 'not' => [self::EXCLUSION]],
                'apres' => ['all' => [self::HORS_QUARANTAINE, self::PRET, self::IDF], 'not' => [self::EXCLUSION]],
            ],
            'Confiance email A (domaine = site)' => [
                'avant' => ['all' => [self::A_UN_EMAIL, self::A], 'not' => [self::EXCLUSION]],
                'apres' => ['all' => [self::HORS_QUARANTAINE, self::A], 'not' => [self::EXCLUSION, self::SITE_NON_VERIFIE]],
            ],
        ];
    }

    /** Forme canonique : clés des objets triées, listes gardées dans leur ordre. */
    private static function canonique(mixed $valeur): mixed
    {
        if (! is_array($valeur)) {
            return $valeur;
        }
        $canon = array_map(static fn (mixed $v): mixed => self::canonique($v), $valeur);
        if (! array_is_list($canon)) {
            ksort($canon);
        }

        return $canon;
    }

    public function up(): void
    {
        $this->parEspace(function (stdClass $audience, array $criteres): ?array {
            $def = self::definitions()[(string) $audience->name] ?? null;
            if ($def === null || self::canonique($criteres) === self::canonique($def['apres'])) {
                return null;
            }
            if (self::canonique($criteres) !== self::canonique($def['avant'])) {
                Log::notice('Audience par défaut réécrite à la main : quarantaine des sites non vérifiés NON appliquée', [
                    'audience_id' => $audience->id,
                ]);

                return null;
            }

            return $def['apres'];
        }, true);
    }

    public function down(): void
    {
        $this->parEspace(function (stdClass $audience, array $criteres): ?array {
            $def = self::definitions()[(string) $audience->name] ?? null;
            if ($def === null || self::canonique($criteres) !== self::canonique($def['apres'])) {
                return null;
            }

            return $def['avant'];
        }, false);
    }

    /**
     * @param  Closure(stdClass, array<string, mixed>): ?array<string, mixed>  $nouveaux
     */
    private function parEspace(Closure $nouveaux, bool $aRecalculer): void
    {
        foreach (DB::table('workspaces')->pluck('id') as $espace) {
            DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', (string) $espace]);
            $audiences = DB::table('email_audiences')
                ->where('workspace_id', $espace)
                ->whereIn('name', array_keys(self::definitions()))
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'criteria']);
            foreach ($audiences as $audience) {
                $criteres = json_decode((string) $audience->criteria, true);
                if (! is_array($criteres)) {
                    continue;
                }
                $apres = $nouveaux($audience, $criteres);
                if ($apres === null) {
                    continue;
                }
                $maj = ['criteria' => json_encode($apres, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
                if ($aRecalculer) {
                    $maj['refreshed_at'] = null;
                }
                DB::table('email_audiences')->where('id', $audience->id)->update($maj);
            }
        }
        DB::select('SELECT set_config(?, ?, true)', ['app.current_workspace_id', '']);
    }
};
