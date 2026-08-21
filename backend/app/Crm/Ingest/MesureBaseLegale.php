<?php

namespace App\Crm\Ingest;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * MESURE — C21-006 (S1) : « 909 086 personnes (68,89 %) sont enregistrées SANS
 * AUCUN moyen de contact, et les 1 319 567 sont sans base légale renseignée. »
 *
 * ── CE QUE CETTE CLASSE FAIT, ET SURTOUT CE QU'ELLE NE FAIT PAS ────────────
 *
 * Elle COMPTE. Elle n'écrit rien, jamais. Remplir `contacts.legal_basis`
 * rétroactivement sur 1 319 567 fiches serait un acte JURIDIQUE — décider a
 * posteriori sous quelle base légale une personne a été collectée il y a deux
 * ans — pas une réparation technique. Un `UPDATE … SET legal_basis =
 * 'legitimate_interest_b2b'` global fabriquerait une conformité de façade :
 * une colonne pleine, une traçabilité inventée. Ce que l'audit peut exiger d'un
 * agent, c'est un CHIFFRE OPPOSABLE et un chemin d'écriture qui ne creuse plus
 * le trou.
 *
 * ── POURQUOI UNE BOUCLE PAR ESPACE DE TRAVAIL ──────────────────────────────
 *
 * `contacts` porte `FORCE ROW LEVEL SECURITY` et une policy stricte
 * (migrations `2026_05_16_000008` + `2026_05_18_000001`). Sans
 * `app.current_workspace_id` posé, un rôle applicatif non-propriétaire voit
 * ZÉRO ligne : la mesure rendrait « 0 fiche sans base légale », c'est-à-dire
 * le rapport le plus rassurant et le plus faux qui soit. C'est exactement le
 * silence qui a coûté `CrmRemplirClePersonne` (« 0 fiche traitée » sur
 * 410 481 restantes). On pose donc le contexte, espace par espace, et on
 * ADDITIONNE.
 *
 * ── DÉFINITIONS, ÉCRITES ICI POUR QUE LE CHIFFRE SOIT REPRODUCTIBLE ────────
 *
 *  · « sans aucun moyen de contact » = `email`, `phone` ET `linkedin_url`
 *    tous les trois nuls ou vides après rognage. Une chaîne vide est traitée
 *    comme une absence : compter `''` comme une adresse e-mail donnerait un
 *    taux de couverture flatteur et faux.
 *  · « sans base légale » = `legal_basis` nul ou vide. Le CHECK SQL
 *    `contacts_legal_basis_check` (migration `2026_08_14_000002`) autorise
 *    explicitement NULL — la colonne peut donc rester vide sans qu'aucune
 *    contrainte ne proteste, ce qui est précisément la façon dont
 *    1 319 567 fiches y sont arrivées.
 *  · les fiches en suppression douce (`deleted_at IS NOT NULL`) sont comptées
 *    À PART, jamais retirées en silence : leurs données à caractère personnel
 *    sont toujours en base, donc toujours dans le champ du RGPD.
 */
final class MesureBaseLegale
{
    /**
     * Fragment SQL « aucun moyen de contact ».
     *
     * `email` est de type CITEXT : le rognage passe par `::text`, sinon
     * Postgres ne trouve pas `btrim(citext)` et la requête échoue au lieu de
     * mesurer.
     */
    public const SQL_SANS_MOYEN_DE_CONTACT = <<<'SQL'
        (email IS NULL OR btrim(email::text) = '')
        AND (phone IS NULL OR btrim(phone) = '')
        AND (linkedin_url IS NULL OR btrim(linkedin_url) = '')
        SQL;

    /** Fragment SQL « aucune base légale renseignée ». */
    public const SQL_SANS_BASE_LEGALE = "legal_basis IS NULL OR btrim(legal_basis) = ''";

    /** Étiquette des fiches dont la provenance n'a jamais été notée. */
    public const SOURCE_INCONNUE = '(source non renseignee)';

    /**
     * Mesure agrégée sur TOUS les espaces de travail.
     *
     * @return array{
     *     espaces: int,
     *     total: int,
     *     sans_contact: int,
     *     sans_base: int,
     *     supprimees: int,
     *     par_source: list<array{source: string, total: int, sans_contact: int, sans_base: int}>
     * }
     */
    public function surTousLesEspaces(): array
    {
        $this->exigerLeSchema();

        $espaces = DB::table('workspaces')->orderBy('id')->pluck('id');

        $total = 0;
        $sansContact = 0;
        $sansBase = 0;
        $supprimees = 0;
        /** @var array<string, array{source: string, total: int, sans_contact: int, sans_base: int}> $parSource */
        $parSource = [];

        foreach ($espaces as $espace) {
            $mesure = WorkspaceContext::run(
                (string) $espace,
                fn (): array => $this->surUnEspace((string) $espace),
            );

            $total += $mesure['total'];
            $sansContact += $mesure['sans_contact'];
            $sansBase += $mesure['sans_base'];
            $supprimees += $mesure['supprimees'];

            foreach ($mesure['par_source'] as $ligne) {
                $cle = $ligne['source'];
                $parSource[$cle] ??= ['source' => $cle, 'total' => 0, 'sans_contact' => 0, 'sans_base' => 0];
                $parSource[$cle]['total'] += $ligne['total'];
                $parSource[$cle]['sans_contact'] += $ligne['sans_contact'];
                $parSource[$cle]['sans_base'] += $ligne['sans_base'];
            }
        }

        // Décroissant par volume : le rapport doit ouvrir sur la source qui
        // porte le plus de fiches, c'est-à-dire celle qu'il faudra corriger.
        usort($parSource, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return [
            'espaces' => $espaces->count(),
            'total' => $total,
            'sans_contact' => $sansContact,
            'sans_base' => $sansBase,
            'supprimees' => $supprimees,
            // `usort()` reindexe deja : `array_values()` n'avait aucun effet.
            'par_source' => $parSource,
        ];
    }

    /**
     * Mesure d'UN espace. Le contexte RLS doit déjà être posé par l'appelant.
     *
     * @return array{
     *     total: int,
     *     sans_contact: int,
     *     sans_base: int,
     *     supprimees: int,
     *     par_source: list<array{source: string, total: int, sans_contact: int, sans_base: int}>
     * }
     */
    public function surUnEspace(string $workspaceId): array
    {
        $this->exigerLeSchema();

        $sansContact = self::SQL_SANS_MOYEN_DE_CONTACT;
        $sansBase = self::SQL_SANS_BASE_LEGALE;
        $inconnue = self::SOURCE_INCONNUE;

        // Une SEULE requête : deux passes sur 1,3 M de lignes coûteraient deux
        // parcours séquentiels là où un seul suffit, et surtout pourraient
        // rendre deux photographies différentes si une collecte tourne en même
        // temps.
        $lignes = DB::select(
            <<<SQL
            SELECT coalesce(nullif(btrim(discovery_source), ''), '{$inconnue}') AS source,
                   count(*)                                       AS total,
                   count(*) FILTER (WHERE {$sansContact})         AS sans_contact,
                   count(*) FILTER (WHERE {$sansBase})            AS sans_base
            FROM   contacts
            WHERE  workspace_id = ?
              AND  deleted_at IS NULL
            GROUP  BY 1
            SQL,
            [$workspaceId],
        );

        $supprimees = (int) DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('deleted_at')
            ->count();

        $total = 0;
        $totalSansContact = 0;
        $totalSansBase = 0;
        $parSource = [];

        foreach ($lignes as $ligne) {
            $ligneTotal = (int) $ligne->total;
            $ligneSansContact = (int) $ligne->sans_contact;
            $ligneSansBase = (int) $ligne->sans_base;

            $total += $ligneTotal;
            $totalSansContact += $ligneSansContact;
            $totalSansBase += $ligneSansBase;

            $parSource[] = [
                'source' => (string) $ligne->source,
                'total' => $ligneTotal,
                'sans_contact' => $ligneSansContact,
                'sans_base' => $ligneSansBase,
            ];
        }

        return [
            'total' => $total,
            'sans_contact' => $totalSansContact,
            'sans_base' => $totalSansBase,
            'supprimees' => $supprimees,
            'par_source' => $parSource,
        ];
    }

    /**
     * Part en pourcentage, arrondie au centième — la précision du constat
     * d'origine (« 68,89 % »). Sur un dénominateur nul on rend `null` et NON
     * `0.0` : « 0 % de fiches sans base légale sur 0 fiche » est un vert
     * déguisé, et c'est la forme exacte que prendrait une mesure étouffée par
     * la RLS.
     */
    public static function part(int $numerateur, int $denominateur): ?float
    {
        if ($denominateur <= 0) {
            return null;
        }

        return round($numerateur * 100 / $denominateur, 2);
    }

    /**
     * Une mesure qui tombe sur une table ou une colonne absente doit ÉCHOUER
     * en le disant. Sans ce refus, un renommage de colonne rendrait « 0 fiche
     * sans base légale » — la table n'ayant plus de colonne à trouver vide —
     * et le rapport annoncerait une conformité parfaite le jour où le schéma
     * casse.
     */
    private function exigerLeSchema(): void
    {
        if (! Schema::hasTable('contacts')) {
            throw new RuntimeException(
                'MESURE IMPOSSIBLE : la table « contacts » est absente de cette base. '
                . 'Une mesure qui rendrait 0 ici ferait croire le probleme resolu.',
            );
        }

        foreach (['email', 'phone', 'linkedin_url', 'legal_basis', 'discovery_source', 'deleted_at'] as $colonne) {
            if (! Schema::hasColumn('contacts', $colonne)) {
                throw new RuntimeException(
                    'MESURE IMPOSSIBLE : la colonne « contacts.' . $colonne . ' » est absente. '
                    . 'Le constat C21-006 porte sur email/phone/linkedin_url/legal_basis : '
                    . 'sans elles, le chiffre rendu ne mesurerait rien.',
                );
            }
        }
    }
}
