<?php

namespace App\Envoi\Sante;

use DateTimeImmutable;

/** Ajout seulement : un relevé = une ligne, une ouverture ou un rétablissement = une ligne. Rien ne s'efface. */
interface DepotSante
{
    /** @param array<string, mixed> $mesures */
    public function noterReleve(string $ws, string $source, DateTimeImmutable $quand, bool $ok, array $mesures): void;

    /** @return ?array{mesure_le: string, ok: bool, mesures: array<string, mixed>} */
    public function dernierReleve(string $ws, string $source): ?array;

    public function dernierBattementOk(string $ws, string $source): ?DateTimeImmutable;

    public function premierReleve(string $ws, string $source): ?DateTimeImmutable;

    /** @return array<string, array{gravite: string, detail: string, depuis: string, palier: int, alerte_emise: bool}> constats ouverts, par code */
    public function constatsOuverts(string $ws, string $source): array;

    public function ouvrir(string $ws, string $source, string $code, string $gravite, string $detail, DateTimeImmutable $quand, int $palier = 1): void;

    /** Note (ajout) que l'alerte de l'ouverture courante de ce constat est partie. Tant qu'elle manque, la supervision la renvoie. */
    public function marquerAlerteEmise(string $ws, string $source, string $code, string $gravite, DateTimeImmutable $quand): void;

    public function retablir(string $ws, string $source, string $code, DateTimeImmutable $quand): void;

    /**
     * Dernier contrôle DNS (K12) de chaque domaine.
     *
     * @return list<array{domaine: string, pret: bool, pause: bool, controle_le: string}>
     */
    public function controlesDns(string $ws): array;

    /**
     * Dernier statut de liste noire (K13) de chaque ressource.
     *
     * @return list<array{type: string, ressource: string, statut: string, pause: bool}>
     */
    public function controlesListes(string $ws): array;

    /**
     * Dernière relève IMAP de l'espace (curseur `envoi_curseurs_imap.maj_le`, posé à chaque relève menée à son terme ; le plus récent
     * de ses boîtes), ou null si elle n'a jamais tourné. Mesure la VIE de la relève, pas l'arrivée de retours : une petite campagne saine
     * peut n'avoir aucun rebond. Dette : par boîte (une boîte morte derrière une boîte vivante passe inaperçue ici).
     */
    public function dernierReleveImap(string $ws, ?string $boite = null): ?DateTimeImmutable;

    /**
     * Début de la série ININTERROMPUE de relevés de ce serveur : le dernier relevé précédé d'un trou de plus de $trouSecondes
     * (ou le premier). Fenêtre de 3 jours avant $jusqua. Sert à ne pas compter comme silence une période où la supervision était arrêtée.
     */
    public function debutSerieReleves(string $ws, string $source, DateTimeImmutable $jusqua, int $trouSecondes): ?DateTimeImmutable;

    /** Messages envoyés dans $apres < cree_le <= $jusqua (bornes nulles = ouvertes) : la matière du « canal des retours muet ». */
    public function envoisEntre(string $ws, ?DateTimeImmutable $apres, ?DateTimeImmutable $jusqua, ?string $boite = null): int;

    /**
     * Boîtes (identifiants) d'où un message est parti après $apres : la matière du « canal des retours muet », compté PAR BOÎTE.
     *
     * @return list<string>
     */
    public function boitesAvecEnvoisDepuis(string $ws, DateTimeImmutable $apres): array;

    /**
     * Serveurs d'envoi actifs (tous espaces) : [workspace_id, source].
     *
     * @return list<array{0: string, 1: string}>
     */
    public function serveursActifs(): array;
}
