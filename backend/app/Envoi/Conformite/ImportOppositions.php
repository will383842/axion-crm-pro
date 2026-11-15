<?php

namespace App\Envoi\Conformite;

use App\Crm\Outbound\OppositionSortante;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Import des listes de refus (P1-LN-B, D8) : CSV d'une colonne (adresse ou domaine), en deux temps.
 *
 * - Le texte arrive en JSON et vit en MÉMOIRE seulement : jamais sur disque, jamais dans un journal, jamais en cache.
 * - Simulation : aucune écriture ; pose un jeton (cache, clé = « envoi:import: » + le jeton, valeur = utilisateur, espace, SHA-256 du texte).
 * - Confirmation : le serveur recalcule le SHA-256 du texte brut (octet pour octet), consomme le jeton UNE fois (ligne `envoi_oppositions_imports` insérée en
 *   premier ; violation d'unicité ⇒ 409), écrit le lot tout ou rien, puis APRÈS le COMMIT signale chaque adresse au site.
 * - Ne fait qu'AJOUTER des refus : le stop gagne, rien n'est jamais retiré.
 */
final class ImportOppositions
{
    public const MAX_LIGNES = 5000;

    public const MAX_OCTETS = 1048576;

    public const DUREE_JETON = 900;

    public const PREFIXE_CLE = 'envoi:import:';

    private const MOTIF_TETE = 'caractère de tête refusé';

    public function __construct(private readonly RegistreOppositionManuel $registre = new RegistreOppositionManuel) {}

    /** @return array{etape: string, valides: int, deja_presentes: int, refusees: list<array{ligne: int, motif: string}>, jeton: string} */
    public function simuler(string $workspaceId, string $utilisateur, string $contenu): array
    {
        $a = $this->analyser($workspaceId, $contenu);
        $jeton = (string) Str::uuid();
        Cache::put(self::PREFIXE_CLE . $jeton, ['utilisateur' => $utilisateur, 'espace' => $workspaceId, 'empreinte' => hash('sha256', $contenu)], self::DUREE_JETON);

        return ['etape' => 'simulation', 'valides' => count($a['valides']), 'deja_presentes' => $a['deja'], 'refusees' => $a['refusees'], 'jeton' => $jeton];
    }

    /** @return array{etape: string, valides: int, deja_presentes: int, refusees: list<array{ligne: int, motif: string}>, import_id: string} */
    public function confirmer(string $workspaceId, string $utilisateur, string $contenu, ?string $jeton): array
    {
        $attendu = is_string($jeton) && Str::isUuid($jeton) ? Cache::get(self::PREFIXE_CLE . $jeton) : null;
        $empreinte = hash('sha256', $contenu);
        if (! is_array($attendu) || ($attendu['utilisateur'] ?? null) !== $utilisateur || ($attendu['espace'] ?? null) !== $workspaceId
            || ! hash_equals((string) ($attendu['empreinte'] ?? ''), $empreinte)) {
            throw new ConflictHttpException('Simulation absente, périmée ou différente du fichier envoyé : relancez la simulation.');
        }
        /** @var string $jeton */
        $a = $this->analyser($workspaceId, $contenu);
        $importId = (string) Str::uuid();
        $adresses = 0;
        $domaines = 0;
        foreach ($a['valides'] as $v) {
            $v['portee'] === 'adresse' ? $adresses++ : $domaines++;
        }
        try {
            $empreintes = DB::transaction(function () use ($workspaceId, $utilisateur, $jeton, $empreinte, $importId, $a, $adresses, $domaines): array {
                // PREMIER : la ligne d'import. L'unicité (espace, jeton) départage deux confirmations simultanées.
                DB::table('envoi_oppositions_imports')->insert([
                    'id' => $importId, 'workspace_id' => $workspaceId, 'auteur' => $utilisateur, 'empreinte_fichier' => $empreinte,
                    'jeton_simulation' => $jeton, 'nb_lignes' => $a['lignes'], 'nb_adresses' => $adresses, 'nb_domaines' => $domaines,
                    'nb_organisations' => 0, 'nb_ignorees' => $a['deja'] + count($a['refusees']),
                ]);

                return $this->registre->inscrireLot($workspaceId, $importId, $a['valides'], 'import ' . $importId, $utilisateur);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConflictHttpException('Ce jeton a déjà servi : relancez la simulation.');
        }
        Cache::forget(self::PREFIXE_CLE . $jeton);
        // APRÈS le COMMIT : l'anti-boucle et le dédoublonnage sont décidés par OppositionSortante, jamais ici.
        $sortante = new OppositionSortante;
        foreach ($empreintes as $e) {
            $sortante->signalerSansBloquer($e, 'business', 'registre_manuel', 'registre_manuel');
        }

        return ['etape' => 'confirmation', 'valides' => count($a['valides']), 'deja_presentes' => $a['deja'], 'refusees' => $a['refusees'], 'import_id' => $importId];
    }

    /**
     * @return array{lignes: int, valides: list<array{portee: string, valeur: string}>, deja: int, refusees: list<array{ligne: int, motif: string}>}
     */
    private function analyser(string $workspaceId, string $contenu): array
    {
        if (strlen($contenu) > self::MAX_OCTETS) {
            throw ValidationException::withMessages(['contenu' => ['Fichier de plus de 1 Mo.']])->status(422);
        }
        $lignes = [];
        foreach (explode("\n", $contenu) as $i => $brut) {
            if (str_ends_with($brut, "\r")) {
                $brut = substr($brut, 0, -1);
            }
            if (trim($brut) !== '') {
                $lignes[$i + 1] = $brut;
            }
        }
        if (count($lignes) > self::MAX_LIGNES) {
            throw ValidationException::withMessages(['contenu' => ['Plus de 5 000 lignes.']])->status(422);
        }
        $candidats = [];
        $refusees = [];
        foreach ($lignes as $n => $brut) {
            $valeur = str_contains($brut, '"') ? (string) (str_getcsv($brut)[0] ?? '') : $brut;
            if ($this->tete($brut) || $this->tete($valeur)) {
                $refusees[] = ['ligne' => $n, 'motif' => self::MOTIF_TETE];

                continue;
            }
            $portee = str_contains($valeur, '@') ? 'adresse' : 'domaine';
            try {
                $candidats[$n] = ['portee' => $portee, 'valeur' => RegistreOppositionManuel::normaliser($portee, $valeur)];
            } catch (InvalidArgumentException $e) {
                $refusees[] = ['ligne' => $n, 'motif' => $e->getMessage()];
            }
        }
        $vus = [];
        $valides = [];
        $deja = 0;
        $existantes = $this->existantes($workspaceId, $candidats);
        foreach ($candidats as $c) {
            $cle = $c['portee'] . '|' . RegistreOppositionManuel::empreinte($c['valeur']);
            if (isset($vus[$cle]) || isset($existantes[$cle])) {
                $deja++;

                continue;
            }
            $vus[$cle] = true;
            $valides[] = $c;
        }
        usort($refusees, fn (array $x, array $y): int => $x['ligne'] <=> $y['ligne']);

        return ['lignes' => count($lignes), 'valides' => $valides, 'deja' => $deja, 'refusees' => $refusees];
    }

    /** Une valeur qui commence par = + - @ tabulation ou retour chariot est refusée (précaution contre l'injection CSV). */
    private function tete(string $v): bool
    {
        $t = ltrim($v, ' ');

        return $t !== '' && strpbrk($t[0], "=+-@\t\r") !== false;
    }

    /**
     * @param  array<int, array{portee: string, valeur: string}>  $candidats
     * @return array<string, true>
     */
    private function existantes(string $workspaceId, array $candidats): array
    {
        $trouvees = [];
        foreach (array_chunk(array_values($candidats), 1000) as $lot) {
            $emp = array_values(array_unique(array_map(fn (array $c): string => RegistreOppositionManuel::empreinte($c['valeur']), $lot)));
            $rows = DB::table('envoi_oppositions_registre')->where('workspace_id', $workspaceId)->whereIn('portee', ['adresse', 'domaine'])
                ->whereIn('empreinte', $emp)->get(['portee', 'empreinte']);
            foreach ($rows as $r) {
                $trouvees[$r->portee . '|' . $r->empreinte] = true;
            }
        }

        return $trouvees;
    }
}
