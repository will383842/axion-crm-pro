<?php

namespace App\Envoi\FeuVert;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * X5-A — attestation humaine « serveur réel » (table `envoi_attestations_serveur_reel`, ajout seul).
 * La DERNIÈRE ligne de l'espace fait foi. Toute erreur de lecture remonte (échec fermé : jamais « fait » par défaut).
 */
class DepotAttestationServeurReel
{
    public const ATTESTE = 'atteste';

    public const RETIRE = 'retire';

    /** Pose une ligne (le geste est « atteste » ou « retire »). Le motif ne contient aucune adresse. */
    public function inscrire(string $ws, string $geste, string $auteur, string $motif): void
    {
        if (! in_array($geste, [self::ATTESTE, self::RETIRE], true)) {
            throw new InvalidArgumentException('Geste inconnu : « atteste » ou « retire ».');
        }
        if (! mb_check_encoding($auteur . $motif, 'UTF-8') || preg_match('/[\p{Cf}\p{Cc}]/u', $auteur . $motif) === 1) {
            throw new InvalidArgumentException('Texte invalide (UTF-8 sans caractère de contrôle).');
        }
        $auteur = DepotPreuves::nettoyer($auteur);
        $motif = DepotPreuves::nettoyer($motif);
        if ($auteur === '' || mb_strlen($auteur) > 100 || DepotPreuves::ressembleAUneAdresse($auteur)) {
            throw new InvalidArgumentException('Auteur invalide (1 à 100 caractères, sans adresse e-mail).');
        }
        if (mb_strlen($motif) < 10 || mb_strlen($motif) > 500 || DepotPreuves::ressembleAUneAdresse($motif)) {
            throw new InvalidArgumentException('Motif invalide (10 à 500 caractères, sans adresse e-mail).');
        }
        WorkspaceContext::run($ws, fn () => DB::table('envoi_attestations_serveur_reel')->insert([
            'workspace_id' => $ws, 'geste' => $geste, 'auteur' => $auteur, 'motif' => $motif,
        ]));
    }

    /** Vrai si la dernière ligne de l'espace est « atteste ». */
    public function active(string $ws): bool
    {
        $derniere = $this->derniere($ws);

        return $derniere !== null && $derniere->geste === self::ATTESTE;
    }

    /** @return object{geste: string}|null */
    private function derniere(string $ws): ?object
    {
        return WorkspaceContext::run($ws, fn () => DB::selectOne(
            'SELECT geste FROM envoi_attestations_serveur_reel WHERE workspace_id = ? ORDER BY le DESC, id DESC LIMIT 1',
            [$ws],
        ));
    }

    /**
     * Codes dont la DERNIÈRE preuve est « fait » ET déposée strictement APRÈS l'attestation active (comparaison en base,
     * à la microseconde). Vide sans attestation active. Une preuve d'avant reste à revalider.
     *
     * @return list<string>
     */
    public function codesProuvesApres(string $ws): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::select(
            <<<'SQL'
            WITH att AS (
                SELECT geste, le FROM envoi_attestations_serveur_reel WHERE workspace_id = ? ORDER BY le DESC, id DESC LIMIT 1
            )
            SELECT p.code FROM (
                SELECT DISTINCT ON (code) code, fait, cree_le FROM envoi_feu_vert_preuves WHERE workspace_id = ? ORDER BY code, id DESC
            ) p, att
            WHERE att.geste = 'atteste' AND p.fait AND p.cree_le > att.le
            SQL,
            [$ws, $ws],
        ));

        /** @var list<object{code: string}> $lignes */
        return array_map(static fn (object $l): string => (string) $l->code, $lignes);
    }
}
