<?php

namespace App\Crm\Rgpd;

use App\Support\ListeSuppression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * EFFACEMENT (art. 17) DES COORDONNÉES D'UNE PERSONNE LÀ OÙ LES FICHES
 * D'ORGANISATION LES PORTENT — une seule définition, pour les deux portes
 * d'effacement (`GdprErasureService`, console ; `SiteGdprService`, site).
 *
 * ── Le trou qu'on ferme (relecture sécurité de la PR #255, 2026-09-29) ──────
 *
 * Les deux services effaçaient les `contacts` PAR ADRESSE, et rien d'autre du
 * côté des organisations. Or l'adresse ou le mobile d'une personne vivent aussi :
 *
 *   - dans `companies.email_generic` : l'adresse d'une petite association est
 *     souvent celle de son président ;
 *   - dans `companies.phone` : le standard d'un club, c'est un portable ;
 *   - dans `companies.signals.contact_channels` : les canaux collectés en vrac
 *     (e-mails, téléphones, et leur fiche de vérification `details`) ;
 *   - dans `contacts.phone`, sur une fiche personne SANS l'adresse demandée.
 *
 * Ce trou existait déjà pour les organisateurs d'événements en production ; il
 * se referme pour eux du même geste.
 *
 * ── Fiches PROTÉGÉES ────────────────────────────────────────────────────────
 *
 * Mettre une coordonnée à NULL est un UPDATE : le verrou de la base
 * (`refuser_suppression_fiche_protegee`) ne vise que la suppression de la
 * fiche. C'est la promesse écrite dans `FichesProtegees` : « la protection ne
 * fait JAMAIS obstacle au droit d'une personne ». La fiche survit, la
 * coordonnée disparaît.
 *
 * ── Et on le PROUVE ─────────────────────────────────────────────────────────
 *
 * `residus()` recherche l'adresse et le numéro là où l'effacement vient de
 * passer, et PLUS LARGEMENT (tout le texte de `signals`) : un effacement ne se
 * déclare complet que si cette recherche revient vide. Une adresse rangée
 * ailleurs demain, sous une clé que `effacer()` ne connaît pas, rend donc
 * l'effacement « incomplet » au lieu de le laisser se dire fait.
 *
 * Coût : `lower(email_generic)`, les chiffres de `phone` et le texte de
 * `signals` ne sont servis par aucun index — un balayage de `companies` par
 * demande. Un effacement est rare, et c'est un droit avec un délai légal : la
 * justesse prime ici sur la vitesse (même arbitrage que `activities`).
 */
final class EffacementCoordonneesFiches
{
    /**
     * Les téléphones portés par les fiches personnes de cette adresse — à
     * relever AVANT de les supprimer : la personne a demandé l'effacement de
     * SES coordonnées, et son mobile en fait partie même si la demande ne le
     * cite pas.
     *
     * @return list<string>
     */
    public static function telephonesDesContacts(string $email, ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $requete = DB::table('contacts')->where('email', $email)->whereNotNull('phone');
        if ($workspaceId !== null) {
            $requete->where('workspace_id', $workspaceId);
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $requete->pluck('phone')->all()),
            static fn (string $t): bool => trim($t) !== '',
        )));
    }

    /**
     * @param  list<string>  $telephones
     * @return array<string, int> lignes touchées, par emplacement
     */
    public static function effacer(string $email, array $telephones, ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $variantes = self::variantes($telephones);
        $bilan = [
            'companies_email_generic' => 0,
            'companies_phone' => 0,
            'companies_canaux' => 0,
            'contacts_par_telephone' => 0,
        ];

        if ($email !== '') {
            // La fiche de vérification de l'adresse part avec l'adresse.
            $bilan['companies_email_generic'] = self::fiches($workspaceId)
                ->whereRaw('lower(email_generic) = ?', [$email])
                ->update([
                    'email_generic' => null,
                    'signals' => DB::raw("signals - 'email_generic_verification'"),
                    'updated_at' => now(),
                ]);
        }

        if ($variantes !== []) {
            $bilan['companies_phone'] = self::fiches($workspaceId)
                ->whereNotNull('phone')
                ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes)
                ->update(['phone' => null, 'updated_at' => now()]);

            $contacts = DB::table('contacts')
                ->whereNotNull('phone')
                ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes);
            if ($workspaceId !== null) {
                $contacts->where('workspace_id', $workspaceId);
            }
            $bilan['contacts_par_telephone'] = $contacts->delete();
        }

        foreach (self::fichesAvecCanaux($email, $variantes, $workspaceId) as $fiche) {
            $signals = json_decode((string) $fiche->signals, true);
            if (! is_array($signals) || ! is_array($signals['contact_channels'] ?? null)) {
                continue;
            }
            $canaux = self::nettoyerCanaux($signals['contact_channels'], $email, $variantes);
            if ($canaux === $signals['contact_channels']) {
                continue;
            }
            $signals['contact_channels'] = $canaux;
            DB::table('companies')->where('id', $fiche->id)->update([
                'signals' => json_encode($signals, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $bilan['companies_canaux']++;
        }

        return $bilan;
    }

    /**
     * Ce qui reste de l'adresse et des numéros, par emplacement. Vide : rien.
     *
     * @param  list<string>  $telephones
     * @return array<string, int>
     */
    public static function residus(string $email, array $telephones, ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $variantes = self::variantes($telephones);
        $residus = [];

        if ($email !== '') {
            $contacts = DB::table('contacts')->where('email', $email);
            $generiques = self::fiches($workspaceId)->whereRaw('lower(email_generic) = ?', [$email]);
            if ($workspaceId !== null) {
                $contacts->where('workspace_id', $workspaceId);
            }
            $residus['contacts.email'] = $contacts->count();
            $residus['companies.email_generic'] = $generiques->count();

            // Tout le texte de `signals`, pas seulement les canaux connus.
            $candidates = self::fiches($workspaceId)
                ->whereRaw('signals::text ILIKE ?', ['%' . self::echapperLike($email) . '%'])
                ->get(['id', DB::raw('signals::text AS texte')]);
            $residus['companies.signals'] = $candidates
                ->filter(static fn (object $f): bool => str_contains(mb_strtolower((string) $f->texte), $email))
                ->count();
        }

        if ($variantes !== []) {
            $contacts = DB::table('contacts')
                ->whereNotNull('phone')
                ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes);
            if ($workspaceId !== null) {
                $contacts->where('workspace_id', $workspaceId);
            }
            $residus['contacts.phone'] = $contacts->count();
            $residus['companies.phone'] = self::fiches($workspaceId)
                ->whereNotNull('phone')
                ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes)
                ->count();

            $telephonesRestants = 0;
            foreach (self::fichesAvecCanaux('', $variantes, $workspaceId) as $fiche) {
                $signals = json_decode((string) $fiche->signals, true);
                $telephonesCanaux = is_array($signals) ? ($signals['contact_channels']['phones'] ?? []) : [];
                foreach (is_array($telephonesCanaux) ? $telephonesCanaux : [] as $t) {
                    if (is_string($t) && self::telephoneVise($t, $variantes)) {
                        $telephonesRestants++;
                    }
                }
            }
            $residus['companies.signals.phones'] = $telephonesRestants;
        }

        return array_filter($residus, static fn (int $n): bool => $n > 0);
    }

    /**
     * Les fiches d'organisation qui portent l'adresse — pour l'export des
     * articles 15 et 20 (« ce qu'on sait effacer, on sait l'exporter »).
     *
     * @return list<array{id: int, denomination: ?string, email_generic: ?string, phone: ?string}>
     */
    public static function fichesPortant(string $email): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $ids = self::fiches(null)->whereRaw('lower(email_generic) = ?', [$email])->pluck('id')->all();
        foreach (self::fichesAvecCanaux($email, [], null) as $fiche) {
            $signals = json_decode((string) $fiche->signals, true);
            $canaux = is_array($signals) && is_array($signals['contact_channels'] ?? null) ? $signals['contact_channels'] : [];
            if (self::nettoyerCanaux($canaux, $email, []) !== $canaux) {
                $ids[] = $fiche->id;
            }
        }
        if ($ids === []) {
            return [];
        }

        return array_values(self::fiches(null)
            ->whereIn('id', array_values(array_unique(array_map('intval', $ids))))
            ->orderBy('id')
            ->get(['id', 'denomination', 'email_generic', 'phone'])
            ->map(static fn (object $f): array => [
                'id' => (int) $f->id,
                'denomination' => $f->denomination,
                'email_generic' => $f->email_generic,
                'phone' => $f->phone,
            ])
            ->all());
    }

    /**
     * Retire l'adresse et les numéros des canaux d'une fiche. Les autres
     * valeurs sont gardées dans leur ordre.
     *
     * @param  array<mixed>  $canaux
     * @param  list<string>  $variantes
     * @return array<mixed>
     */
    public static function nettoyerCanaux(array $canaux, string $email, array $variantes): array
    {
        if ($email !== '' && is_array($canaux['emails'] ?? null)) {
            $canaux['emails'] = array_values(array_filter(
                $canaux['emails'],
                static fn (mixed $e): bool => ! is_string($e) || mb_strtolower(trim($e)) !== $email,
            ));
        }
        if ($email !== '' && is_array($canaux['details'] ?? null)) {
            foreach (array_keys($canaux['details']) as $cle) {
                if (mb_strtolower(trim((string) $cle)) === $email) {
                    unset($canaux['details'][$cle]);
                }
            }
        }
        if ($variantes !== [] && is_array($canaux['phones'] ?? null)) {
            $canaux['phones'] = array_values(array_filter(
                $canaux['phones'],
                static fn (mixed $t): bool => ! is_string($t) || ! self::telephoneVise($t, $variantes),
            ));
        }

        return $canaux;
    }

    // ── Internes ────────────────────────────────────────────────────────────

    private static function fiches(?string $workspaceId): Builder
    {
        $requete = DB::table('companies');
        if ($workspaceId !== null) {
            $requete->where('workspace_id', $workspaceId);
        }

        return $requete;
    }

    /**
     * Les fiches dont les canaux PEUVENT porter l'adresse ou un numéro : un
     * préfiltre SQL large (le `_` d'une adresse est un joker de LIKE), puis la
     * comparaison exacte en PHP.
     *
     * @param  list<string>  $variantes
     * @return iterable<\stdClass>
     */
    private static function fichesAvecCanaux(string $email, array $variantes, ?string $workspaceId): iterable
    {
        if ($email === '' && $variantes === []) {
            return [];
        }

        return self::fiches($workspaceId)
            // `jsonb_exists` et non l'opérateur `?`, que PDO prendrait pour un paramètre.
            ->whereRaw("jsonb_exists(signals, 'contact_channels')")
            ->where(function (Builder $q) use ($email, $variantes): void {
                if ($email !== '') {
                    $q->orWhereRaw("(signals->'contact_channels')::text ILIKE ?", ['%' . self::echapperLike($email) . '%']);
                }
                foreach ($variantes as $v) {
                    $q->orWhereRaw(
                        "regexp_replace(coalesce(signals->'contact_channels'->>'phones', ''), '[^0-9]', '', 'g') LIKE ?",
                        ['%' . $v . '%'],
                    );
                }
            })
            ->get(['id', 'signals']);
    }

    /**
     * @param  list<string>  $telephones
     * @return list<string>
     */
    private static function variantes(array $telephones): array
    {
        $variantes = [];
        foreach ($telephones as $t) {
            foreach (ListeSuppression::variantesTelephone($t) as $v) {
                // Un numéro trop court (« 15 », « 3949 ») viserait n'importe qui.
                if (strlen($v) >= 9) {
                    $variantes[$v] = true;
                }
            }
        }

        // `strval` : PHP convertit une clé « 33612345678 » en entier.
        return array_map('strval', array_keys($variantes));
    }

    /** @param  list<string>  $variantes */
    private static function telephoneVise(string $telephone, array $variantes): bool
    {
        foreach (ListeSuppression::variantesTelephone($telephone) as $v) {
            if (in_array($v, $variantes, true)) {
                return true;
            }
        }

        return false;
    }

    private static function chiffres(string $colonne): string
    {
        return "regexp_replace({$colonne}, '[^0-9]', '', 'g')";
    }

    /** @param  list<string>  $valeurs */
    private static function marques(array $valeurs): string
    {
        return implode(', ', array_fill(0, count($valeurs), '?'));
    }

    private static function echapperLike(string $valeur): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valeur);
    }
}
