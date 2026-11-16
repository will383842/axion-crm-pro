<?php

namespace App\Crm\Contacts;

use App\Crm\Opposition\StatutOpposition;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import de contacts (G1-D). Réutilise `AjoutContact` (entreprise, doublons, création) : aucune logique copiée,
 * sauf le verdict d'opposition affiché en deux codes distincts (`opposition`, `retire_auparavant`).
 *
 * - `controle` n'écrit RIEN.
 * - `importer` rejoue le contrôle de CHAQUE ligne dans la transaction de son paquet (100 lignes), puis crée par
 *   `AjoutContact::ajouter` (sans choix de doublon : jamais de fusion en masse). Les doublons et les refus ne sont jamais créés.
 * - Le bilan, le rapport et le journal ne portent que des numéros de ligne, des codes et des compteurs, jamais une valeur.
 * - Une personne opposée ou retirée du registre ne revient JAMAIS (échec fermé : verdict absent = refus).
 * - Aucune suppression, aucun envoi. Le filtre `workspace_id` est explicite (le propriétaire ignore la RLS).
 */
final class ImportContacts
{
    public const MAX_LIGNES = 1000;

    public const MAX_CHAMP = 300;

    private const PAQUET = 100;

    private const TTL_RAPPORT = 3600;

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array{total: int, a_creer: int, refusees: list<array{ligne: int, raison: string}>, doublons: list<array{ligne: int, existant: string}>, sans_provenance: list<int>}
     */
    public function controle(string $workspaceId, array $lignes): array
    {
        $bilan = $this->vide(count($lignes));
        $this->evaluer($workspaceId, $lignes, $bilan, null);

        return $bilan;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return array{total: int, a_creer: int, refusees: list<array{ligne: int, raison: string}>, doublons: list<array{ligne: int, existant: string}>, sans_provenance: list<int>, crees: int}
     */
    public function importer(string $workspaceId, array $lignes, string $userId): array
    {
        $bilan = $this->vide(count($lignes));
        $crees = 0;
        $vus = [];
        foreach (array_chunk($lignes, self::PAQUET) as $paquet) {
            $crees += DB::transaction(function () use ($workspaceId, $paquet, $userId, &$bilan, &$vus): int {
                $n = 0;
                // Chaque ligne est contrôlée puis créée tout de suite : une ligne identique plus bas dans le fichier voit la première.
                $this->evaluer($workspaceId, $paquet, $bilan, function (array $l, array $entreprise) use ($workspaceId, $userId, &$bilan, &$n): void {
                    $r = (new AjoutContact)->ajouter($workspaceId, [
                        'first_name' => $l['prenom'], 'last_name' => $l['nom'], 'email' => $l['email'], 'company_id' => $entreprise['id'],
                        'fonction' => $l['fonction'], 'provenance' => $l['provenance'],
                    ], $userId);
                    if ($r['etat'] === 'cree') {
                        $n++;

                        return;
                    }
                    // Le contrôle venait de dire « à créer » : tout autre état est un refus (échec fermé), jamais une écriture.
                    $bilan['a_creer']--;
                    if ($r['etat'] === 'doublons' || $r['etat'] === 'doublon') {
                        $bilan['doublons'][] = ['ligne' => $l['ligne'], 'existant' => (string) ($r['doublons'][0]['id'] ?? '')];
                    } else {
                        $bilan['refusees'][] = ['ligne' => $l['ligne'], 'raison' => $r['etat'] === 'opposition' ? 'opposition' : 'refusee'];
                    }
                }, $vus);

                return $n;
            });
        }
        $bilan['crees'] = $crees;

        AuditLogger::log('contacts.import', [
            'workspace_id' => $workspaceId, 'resource_type' => 'contacts_import', 'par' => $userId,
            'total' => $bilan['total'], 'crees' => $crees, 'refusees' => count($bilan['refusees']),
            'doublons' => count($bilan['doublons']), 'sans_provenance' => count($bilan['sans_provenance']),
        ]);

        return $bilan;
    }

    /**
     * Garde le rapport 1 h (compteurs et numéros seulement), lisible par le seul utilisateur de l'espace qui l'a lancé.
     *
     * @param  array<string, mixed>  $rapport
     * @return array{id: string, statut: string, total: int, traitees: int, rapport: array<string, mixed>}
     */
    public function memoriser(string $workspaceId, string $userId, array $rapport): array
    {
        $travail = ['id' => (string) Str::uuid(), 'statut' => 'termine', 'total' => $rapport['total'], 'traitees' => $rapport['total'], 'rapport' => $rapport];
        Cache::put($this->cle($workspaceId, $userId, $travail['id']), $travail, self::TTL_RAPPORT);

        return $travail;
    }

    /** @return array<string, mixed>|null */
    public function suivi(string $workspaceId, string $userId, string $id): ?array
    {
        $t = Cache::get($this->cle($workspaceId, $userId, $id));

        return is_array($t) ? $t : null;
    }

    private function cle(string $workspaceId, string $userId, string $id): string
    {
        return 'contacts-import:' . $workspaceId . ':' . $userId . ':' . $id;
    }

    /** @return array{total: int, a_creer: int, refusees: list<array{ligne: int, raison: string}>, doublons: list<array{ligne: int, existant: string}>, sans_provenance: list<int>} */
    private function vide(int $total): array
    {
        return ['total' => $total, 'a_creer' => 0, 'refusees' => [], 'doublons' => [], 'sans_provenance' => []];
    }

    /**
     * Contrôle chaque ligne (mêmes règles que l'ajout rapide + registre des retraits). Pour chaque ligne à créer, appelle
     * `$creer` s'il est donné (import) ; sinon n'écrit rien (contrôle).
     *
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $bilan
     * @param  array<string, true>  $vus  clés de personnes déjà acceptées dans ce fichier (nom + entreprise, adresse)
     */
    private function evaluer(string $workspaceId, array $lignes, array &$bilan, ?callable $creer, array &$vus = []): void
    {
        $ajout = new AjoutContact;
        $entreprises = [];
        foreach ($lignes as $brut) {
            $l = $this->normaliser($brut);
            $refus = function (string $raison) use (&$bilan, $l): void {
                $bilan['refusees'][] = ['ligne' => $l['ligne'], 'raison' => $raison];
            };

            if ($l['nom'] === '') {
                $refus('nom_manquant');

                continue;
            }
            if (mb_strlen($l['prenom'] ?? '') > 120 || mb_strlen($l['nom']) > 120 || mb_strlen($l['fonction'] ?? '') > 200) {
                $refus('champ_trop_long');

                continue;
            }
            if (! in_array($l['provenance'], AjoutContact::PROVENANCES, true)) {
                $bilan['sans_provenance'][] = $l['ligne'];

                continue;
            }
            if ($l['email'] !== null && (mb_strlen($l['email']) > 254 || filter_var($l['email'], FILTER_VALIDATE_EMAIL) === false)) {
                $refus('email_invalide');

                continue;
            }
            $cleOrg = mb_strtolower((string) $l['organisation']);
            $entreprise = $entreprises[$cleOrg] ??= $ajout->entreprise($workspaceId, null, $l['organisation']);
            if (is_string($entreprise)) {
                $refus($entreprise === 'organisation_ambigue' ? 'organisation_ambigue' : 'organisation_introuvable');

                continue;
            }

            // Opposition puis registre des retraits : échec fermé (verdict absent = refus).
            $statut = (new StatutOpposition)->pourLignes($workspaceId, [0 => ['email' => $l['email'], 'siren' => $entreprise['siren']]]);
            $verdict = $statut[0] ?? ['opposee' => true, 'motifs' => ['verdict_absent']];
            if ($verdict['opposee'] !== false) {
                $refus('opposition');

                continue;
            }
            if ($entreprise['siren'] !== null) {
                $r = DB::selectOne('SELECT contacts_retires_contient(?::uuid, ?, ?, ?) AS e', [$workspaceId, $entreprise['siren'], $l['prenom'] ?? '', $l['nom']]);
                if ($r === null || $r->e !== false) {
                    $refus('retire_auparavant');

                    continue;
                }
            }

            $doublons = $ajout->doublons($workspaceId, $l['email'], $l['prenom'], $l['nom'], $entreprise['id']);
            if ($doublons !== []) {
                $bilan['doublons'][] = ['ligne' => $l['ligne'], 'existant' => (string) $doublons[0]['id']];

                continue;
            }
            // Même personne deux fois dans le fichier : la première seule est créée.
            $cles = ['n:' . $entreprise['id'] . ':' . mb_strtolower(($l['prenom'] ?? '') . '_' . $l['nom'])];
            if ($l['email'] !== null) {
                $cles[] = 'e:' . mb_strtolower($l['email']);
            }
            if (array_intersect_key($vus, array_flip($cles)) !== []) {
                $refus('doublon_dans_le_fichier');

                continue;
            }
            foreach ($cles as $k) {
                $vus[$k] = true;
            }

            $bilan['a_creer']++;
            if ($creer !== null) {
                $creer($l, $entreprise);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array{ligne: int, prenom: ?string, nom: string, email: ?string, organisation: ?string, fonction: ?string, provenance: string}
     */
    private function normaliser(array $b): array
    {
        $t = static function (mixed $v): ?string {
            if (! is_string($v)) {
                return null;
            }
            $v = trim($v);

            return $v === '' ? null : $v;
        };

        return [
            'ligne' => (int) ($b['ligne'] ?? 0),
            'prenom' => $t($b['prenom'] ?? null),
            'nom' => $t($b['nom'] ?? null) ?? '',
            'email' => $t($b['email'] ?? null),
            'organisation' => $t($b['organisation'] ?? null),
            'fonction' => $t($b['fonction'] ?? null),
            'provenance' => $t($b['provenance'] ?? null) ?? '',
        ];
    }
}
