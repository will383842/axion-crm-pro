<?php

namespace App\Envoi\Verdicts;

use App\Socle\Produits\ProduitsEspace;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rafraîchissement planifié des verdicts d'adresse (cahier VERDICTS §1.3, chemin 2). Pour chaque espace actif ayant le
 * produit Envoi : les adresses des inscriptions `active` échues dans les 3 jours dont le dernier verdict est absent ou
 * `valide` depuis 20 jours ou plus. N'envoie rien, aucune connexion SMTP : le seul réseau est le DNS (bornes dans
 * `QuotaDns`). INSERT seulement : aucune suppression de verdict, aucune donnée de personne retirée.
 * Échec fermé : une erreur dans un espace n'écrit rien pour lui, les autres continuent. Journal = compteurs seulement.
 */
final class RafraichissementVerdicts
{
    public const HORIZON_JOURS = 3;

    public const ADRESSES_MAX_PAR_ESPACE = 500;

    public const BORNE_SECONDES = 120;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $horloge;

    /** @param  (Closure(): DateTimeImmutable)|null  $horloge */
    public function __construct(
        private readonly VerificateurAdresse $verificateur,
        private readonly EcrivainVerdicts $ecrivain,
        ?Closure $horloge = null,
    ) {
        $this->horloge = $horloge ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /** @return array<string, int> compteurs seulement : espaces, adresses, ecrits, sans_reponse, erreurs, hors_borne */
    public function passage(DateTimeImmutable $maintenant, ?string $espace = null, bool $essai = false): array
    {
        $bilan = ['espaces' => 0, 'adresses' => 0, 'ecrits' => 0, 'sans_reponse' => 0, 'erreurs' => 0, 'hors_borne' => 0];
        if (! EcrivainVerdicts::schemaPret()) {
            return $bilan;
        }
        $debut = ($this->horloge)()->getTimestamp();
        $requete = DB::table('workspaces')->whereNull('deleted_at')->orderBy('id');
        if ($espace !== null) {
            $requete->where('id', $espace);
        }
        foreach ($requete->pluck('id') as $id) {
            $ws = (string) $id;
            if (($this->horloge)()->getTimestamp() - $debut >= self::BORNE_SECONDES) {
                $bilan['hors_borne']++;

                continue;
            }
            try {
                $n = WorkspaceContext::run($ws, fn (): ?array => $this->traiterEspace($ws, $maintenant, $essai));
            } catch (Throwable $e) {
                $bilan['erreurs']++;
                // Classe seulement : un message d'exception peut contenir une adresse.
                Log::error('envoi.verdicts.rafraichir : espace en erreur.', ['workspace_id' => $ws, 'exception' => $e::class]);

                continue;
            }
            if ($n === null) {
                continue;
            }
            $bilan['espaces']++;
            $bilan['adresses'] += $n['adresses'];
            $bilan['ecrits'] += $n['ecrits'];
            $bilan['sans_reponse'] += $n['sans_reponse'];
        }
        Log::info('envoi.verdicts.rafraichir : bilan.', $bilan);

        return $bilan;
    }

    /** @return array{adresses: int, ecrits: int, sans_reponse: int}|null null = espace sauté (produit Envoi absent) */
    private function traiterEspace(string $ws, DateTimeImmutable $maintenant, bool $essai): ?array
    {
        if (! ProduitsEspace::actif($ws, 'envoi')) {
            return null;
        }
        $adresses = $this->adressesARafraichir($ws, $maintenant);
        if ($adresses === []) {
            return ['adresses' => 0, 'ecrits' => 0, 'sans_reponse' => 0];
        }
        // --essai : tout est calculé puis annulé (verdicts ET cache des domaines), comme l'essai à blanc.
        if ($essai) {
            DB::beginTransaction();
        }
        try {
            $verdicts = $this->verificateur->verifier($ws, $adresses, $maintenant);
            $ecrits = $this->ecrivain->ecrire($ws, $verdicts, $this->verificateur->source(), $maintenant);
        } finally {
            if ($essai) {
                DB::rollBack();
            }
        }

        return [
            'adresses' => count($adresses),
            'ecrits' => $ecrits,
            'sans_reponse' => count(array_filter($verdicts, static fn ($v): bool => ! $v instanceof VerdictAdresse)),
        ];
    }

    /** @return list<string> adresses en clair de l'espace courant (jamais journalisées) */
    private function adressesARafraichir(string $ws, DateTimeImmutable $maintenant): array
    {
        $lignes = DB::select(
            "SELECT MIN(c.email) AS email
             FROM envoi_inscriptions i
             JOIN contacts c ON c.workspace_id = i.workspace_id AND c.id = i.contact_id AND c.deleted_at IS NULL AND c.email IS NOT NULL
             LEFT JOIN LATERAL (
                 SELECT v.verdict, v.verifie_le FROM envoi_verdicts_adresse v
                 WHERE v.workspace_id = i.workspace_id AND v.empreinte_adresse = i.empreinte_adresse
                 ORDER BY v.verifie_le DESC, v.id DESC LIMIT 1
             ) d ON true
             WHERE i.workspace_id = ? AND i.statut = 'active' AND i.prochaine_echeance <= ?
               AND (d.verdict IS NULL OR (d.verdict = 'valide' AND d.verifie_le <= ?))
             GROUP BY i.empreinte_adresse
             ORDER BY MIN(i.prochaine_echeance), i.empreinte_adresse
             LIMIT " . self::ADRESSES_MAX_PAR_ESPACE,
            [
                $ws,
                $maintenant->modify('+' . self::HORIZON_JOURS . ' days')->format('c'),
                $maintenant->modify('-' . EcrivainVerdicts::RAFRAICHIR_APRES_JOURS . ' days')->format('c'),
            ],
        );

        return array_values(array_filter(array_map(static fn ($l): string => trim((string) $l->email), $lignes), static fn (string $e): bool => $e !== ''));
    }
}
