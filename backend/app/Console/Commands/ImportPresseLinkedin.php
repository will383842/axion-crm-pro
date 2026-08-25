<?php

namespace App\Console\Commands;

use App\Crm\Taxonomy;
use App\Models\Journalist;
use App\Models\Workspace;
use App\Support\LinkedinSlug;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import du FICHIER PRESSE issu de recherches LinkedIn (2026-08-25).
 *
 * Source de vérité : `backend/database/data/presse-linkedin/*.json`, versionné —
 * même doctrine que {@see ImportMediaPressKit}. Le PDF d'origine n'est PAS la
 * source : 12 % de ses lignes débordent sur la suivante et son parsing est
 * approximatif ; le JSON dit la même chose exactement.
 *
 * ── Ce que ce fichier contient, et surtout ce qu'il NE contient PAS ────────
 * 412 contacts, 14 champs, aucun doublon interne (vérifié : aucun nom n'y
 * apparaît deux fois, alors que 38 recherches distinctes les ont produits).
 * Mais **ni URL LinkedIn, ni email** — son pied de page le dit : « aucune
 * coordonnée collectée ». C'est un fichier de CIBLAGE, pas un carnet d'adresses.
 *
 * Trois conséquences, portées par cette commande :
 *
 *  1. **Aucune fiche importée n'est diffusable en l'état.** Sans email, rien ne
 *     part. La commande le RAPPELLE en fin de course plutôt que d'afficher un
 *     « 412 importés » qui laisserait croire à un carnet prêt à l'emploi.
 *
 *  2. **`media_id` reste NULL, `media_raw` porte la vérité.** Sur les 412
 *     lignes, 47 % seulement ont un séparateur exploitable, et le découpage
 *     naïf fabrique de faux médias (« Journaliste », « Freelance », « Pigiste »
 *     y sortent comme noms de titres). Côté base, une recherche « TF1 » rend
 *     22 candidats dont 2 seulement sont la rédaction visée. Un rattachement
 *     automatique se tromperait 9 fois sur 10 et salirait 55 830 médias pour
 *     gagner une après-midi : on ne devine pas, on garde la chaîne d'origine et
 *     un écran de rattachement tranche.
 *
 *  3. **Le dédoublonnage délègue son calcul à Postgres.** La clé est la colonne
 *     GENERATED `dedup_key` ; la recalculer en PHP créerait une seconde
 *     implémentation de `normalize_name`, et le jour où les deux divergent, le
 *     contrôle laisse passer exactement ce qu'il devait arrêter.
 *
 * ── Sécurité d'exécution ──────────────────────────────────────────────────
 * `--dry-run` est le DÉFAUT. Écrire exige `--commit`. Sur un import de données
 * personnelles rejouable, l'asymétrie est volontaire : une simulation lancée par
 * erreur ne coûte rien, un import lancé par erreur laisse 412 fiches à trier.
 *
 * Idempotent : relancé, il ne crée rien de nouveau (tout est vu comme doublon).
 */
class ImportPresseLinkedin extends Command
{
    protected $signature = 'presse:import-linkedin
        {--commit : Écrit réellement en base (sans ce drapeau, simulation seule)}
        {--workspace= : UUID du workspace cible (défaut : le seul existant)}
        {--file= : Chemin du JSON (défaut : le dernier fichier versionné)}';

    protected $description = 'Importe le fichier presse LinkedIn (contacts de ciblage, sans coordonnées).';

    private const SOURCE = 'linkedin';

    private const DATA_DIR = 'data/presse-linkedin';

    /**
     * Libellés du fichier → vocabulaire fermé `Taxonomy::ACCES_PRESSE`.
     *
     * La correspondance est explicite et exhaustive : un libellé inconnu n'est
     * PAS rangé dans `a_qualifier` par défaut. Un défaut silencieux ferait
     * glisser une porte d'accès nouvelle vers le seul bucket qu'on ne regarde
     * plus, et le contact deviendrait invisible sans que personne ne l'ait
     * décidé. Inconnu ⇒ la ligne est signalée, pas rangée.
     */
    private const ACCES = [
        'Email rédaction' => 'email_redaction',
        'Rédaction / prod' => 'redaction_prod',
        'LinkedIn direct' => 'linkedin_direct',
        'Ouvrir le profil' => 'a_qualifier',
    ];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $path = $this->resolvePath();
        if ($path === null) {
            $this->error('Aucun fichier JSON trouvé dans ' . self::DATA_DIR . ' (ni --file).');

            return self::FAILURE;
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw) || ! isset($raw['contacts']) || ! is_array($raw['contacts'])) {
            $this->error("Le JSON ne porte pas de tableau `contacts` : {$path}");

            return self::FAILURE;
        }

        $workspaceId = $this->resolveWorkspace();
        if ($workspaceId === null) {
            return self::FAILURE;
        }

        $collecteLe = $this->dateCollecte($raw);

        $this->line('Fichier   : ' . $path);
        $this->line('Contacts  : ' . count($raw['contacts']));
        $this->line('Collecté  : ' . ($collecteLe ?? 'date inconnue'));
        $this->line('Mode      : ' . ($commit ? 'ÉCRITURE' : 'simulation (--commit pour écrire)'));
        $this->newLine();

        $creees = 0;
        $doublons = 0;
        $signalees = [];

        foreach ($raw['contacts'] as $ligne) {
            if (! is_array($ligne)) {
                continue;
            }

            $nom = trim((string) ($ligne['nom'] ?? ''));
            if ($nom === '') {
                $signalees[] = ['rang' => $ligne['rang'] ?? '?', 'motif' => 'nom vide'];
                continue;
            }

            [$prenom, $patronyme, $ambigu] = $this->decouperNom($nom);
            if ($ambigu) {
                // On importe quand même — un nom composé reste un nom — mais on
                // le signale : « Daphné Leprince-Ringuet » se découpe bien,
                // « Jean Pierre de La Tour » beaucoup moins, et seul un humain
                // sait lequel des deux il a sous les yeux.
                $signalees[] = ['rang' => $ligne['rang'] ?? '?', 'motif' => "nom à relire : {$nom}"];
            }

            $acces = self::ACCES[trim((string) ($ligne['acces'] ?? ''))] ?? null;
            if ($acces === null) {
                $signalees[] = [
                    'rang' => $ligne['rang'] ?? '?',
                    'motif' => "accès inconnu : « {$ligne['acces']} » — ligne importée SANS porte d'accès",
                ];
            }

            $mediaRaw = trim((string) ($ligne['media_specialite'] ?? '')) ?: null;

            $existe = $this->dejaPresent($workspaceId, $prenom, $patronyme, $mediaRaw);
            if ($existe) {
                $doublons++;
                continue;
            }

            if ($commit) {
                Journalist::create([
                    'workspace_id' => $workspaceId,
                    'first_name' => $prenom,
                    'last_name' => $patronyme,
                    'media_id' => null,          // rattachement humain — cf. en-tête
                    'media_raw' => $mediaRaw,
                    'role' => $this->tronquer($ligne['role'] ?? null, 160),
                    'beat' => $this->tronquer($ligne['secteur'] ?? null, 160),
                    'acces' => $acces,
                    'priorite' => $this->entier($ligne['priorite'] ?? null),
                    'score' => $this->entier($ligne['score'] ?? null),
                    'abonnes' => $this->entier($ligne['abonnes'] ?? null),
                    'media_portee_raw' => $this->tronquer($ligne['portee'] ?? null, 80),
                    'media_support_raw' => $this->tronquer($ligne['support'] ?? null, 80),
                    'collecte_le' => $collecteLe,
                    'lien_linkedin' => 'inconnu',
                    // Toujours null pour CE fichier (il ne porte pas d'URL) —
                    // la ligne est là pour qu'un fichier enrichi, lui, la voie
                    // normalisée sans qu'on ait à y repenser.
                    'linkedin_slug' => LinkedinSlug::normalize($this->tronquer($ligne['linkedin_url'] ?? null, 500)),
                    'source' => self::SOURCE,
                    // Traçabilité RGPD : le fichier ne porte pas d'URL de profil,
                    // mais il dit QUELLE recherche a produit la ligne. C'est la
                    // provenance la plus précise disponible, et devoir répondre
                    // « d'où tenez-vous cela ? » sans elle serait impossible.
                    'socials' => ['linkedin_recherche' => $ligne['rubrique_source'] ?? null],
                ]);
            }

            $creees++;
        }

        $this->afficherBilan($creees, $doublons, $signalees, $commit, $raw['contacts']);

        return self::SUCCESS;
    }

    /**
     * Une fiche de même clé nom+média existe-t-elle déjà ? Le hachage est
     * calculé PAR POSTGRES, avec les mêmes fonctions que la colonne GENERATED.
     */
    private function dejaPresent(string $workspaceId, ?string $prenom, ?string $patronyme, ?string $mediaRaw): bool
    {
        $cle = DB::selectOne(
            "SELECT encode(digest(
                 normalize_name(coalesce(?, '') || '_' || coalesce(?, ''))
                 || '@' || coalesce(normalize_name(coalesce(?, '')), ''),
                 'sha256'
             ), 'hex') AS k",
            [$prenom, $patronyme, $mediaRaw],
        );

        if ($cle === null || ! isset($cle->k)) {
            return false;
        }

        return Journalist::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->where('dedup_key', $cle->k)
            ->exists();
    }

    /**
     * Découpe « Prénom Nom » en deux. Rend aussi un drapeau d'ambiguïté.
     *
     * Heuristique assumée : le PREMIER mot est le prénom, le reste le nom. Elle
     * est juste sur l'écrasante majorité des lignes et fausse sur les particules
     * (« de », « van ») comme sur les prénoms composés non tiretés. D'où le
     * drapeau : on ne prétend pas avoir résolu le problème des noms de personnes,
     * on signale les lignes où l'on a pu se tromper.
     *
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    private function decouperNom(string $nom): array
    {
        $morceaux = preg_split('/\s+/u', trim($nom)) ?: [];

        if (count($morceaux) === 1) {
            // Un seul mot : c'est un patronyme ou un pseudonyme, jamais un
            // prénom seul dans un fichier presse. Le ranger en `first_name`
            // rendrait la fiche introuvable dans une recherche par nom.
            return [null, $morceaux[0], false];
        }

        $prenom = array_shift($morceaux);

        return [$prenom, implode(' ', $morceaux), count($morceaux) > 1];
    }

    private function resolvePath(): ?string
    {
        $option = $this->option('file');
        if (is_string($option) && $option !== '') {
            return is_file($option) ? $option : null;
        }

        $fichiers = glob(database_path(self::DATA_DIR . '/*.json')) ?: [];
        if ($fichiers === []) {
            return null;
        }

        // Le plus récent par nom : les fichiers sont datés (contacts-AAAA-MM-JJ).
        sort($fichiers);

        return (string) end($fichiers);
    }

    private function resolveWorkspace(): ?string
    {
        $option = $this->option('workspace');
        if (is_string($option) && $option !== '') {
            return $option;
        }

        $workspaces = Workspace::query()->limit(2)->pluck('id');
        if ($workspaces->count() === 1) {
            return (string) $workspaces->first();
        }

        // Ambigu ⇒ on s'arrête. Choisir « le premier » importerait 412 fiches
        // de données personnelles dans un espace qui n'est peut-être pas le bon,
        // et rien dans l'interface ne le rendrait visible ensuite.
        $this->error($workspaces->isEmpty()
            ? 'Aucun workspace en base.'
            : 'Plusieurs workspaces : précisez --workspace=<uuid>.');

        return null;
    }

    /** @param array<string, mixed> $raw */
    private function dateCollecte(array $raw): ?string
    {
        $date = $raw['meta']['date_collecte'] ?? null;

        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    private function tronquer(mixed $valeur, int $max): ?string
    {
        $texte = trim((string) ($valeur ?? ''));
        if ($texte === '') {
            return null;
        }

        return mb_substr($texte, 0, $max);
    }

    private function entier(mixed $valeur): ?int
    {
        return is_numeric($valeur) ? (int) $valeur : null;
    }

    /**
     * @param  list<array{rang: mixed, motif: string}>  $signalees
     * @param  array<int, mixed>  $contacts
     */
    private function afficherBilan(int $creees, int $doublons, array $signalees, bool $commit, array $contacts): void
    {
        $this->info(($commit ? 'Créées   : ' : 'À créer  : ') . $creees);
        $this->line('Doublons : ' . $doublons . ' (déjà en base, ignorés)');

        if ($signalees !== []) {
            $this->newLine();
            $this->warn('À relire — ' . count($signalees) . ' ligne(s) :');
            foreach (array_slice($signalees, 0, 30) as $s) {
                $this->line("  #{$s['rang']} · {$s['motif']}");
            }
            if (count($signalees) > 30) {
                $this->line('  … et ' . (count($signalees) - 30) . ' autres.');
            }
        }

        // ── Le rappel qui empêche de croire le travail fini ──────────────────
        $parAcces = [];
        foreach ($contacts as $c) {
            $cle = is_array($c) ? (self::ACCES[trim((string) ($c['acces'] ?? ''))] ?? 'inconnu') : 'inconnu';
            $parAcces[$cle] = ($parAcces[$cle] ?? 0) + 1;
        }

        $this->newLine();
        $this->line('Répartition par porte d\'accès :');
        foreach (Taxonomy::ACCES_PRESSE as $acces) {
            $this->line(sprintf('  %-16s %d', $acces, $parAcces[$acces] ?? 0));
        }

        $this->newLine();
        $this->warn('AUCUNE de ces fiches n\'est diffusable en l\'état : le fichier ne porte pas d\'email.');
        $this->line('Prochaines étapes, dans cet ordre :');
        $this->line('  1. rapprocher l\'export LinkedIn « Connections » → URL de profil + relations ;');
        $this->line('  2. rattacher les médias à la main (media_raw → media_id) ;');
        $this->line('  3. chercher les emails des ' . ($parAcces['email_redaction'] ?? 0)
            . ' contacts « email_redaction » — les seuls que le mailing pourra atteindre.');

        if (! $commit) {
            $this->newLine();
            $this->info('Simulation seule. Relancez avec --commit pour écrire.');
        }
    }
}
