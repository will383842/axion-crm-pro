<?php

use App\Console\Commands\CrmRemplirClePersonne;
use App\Crm\Identite\CleDePersonne;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🔴 A05-001 (S1) — remplissage de `contacts.person_key` sur le stock existant.
 *
 * Mesure du 2026-08-18 en production : **1 319 567 contacts, 410 481 avec
 * e-mail, 0 avec `person_key`**.
 *
 * ── CE QUE FAIT CETTE MIGRATION, ET CE QU'ELLE NE FAIT PAS ─────────────────
 *
 * Elle DÉCLENCHE le premier passage. Elle ne le RÉIMPLÉMENTE pas : le
 * remplissage vit dans `crm:remplir-cle-personne`, parce qu'une migration ne
 * s'exécute qu'UNE FOIS et qu'un travail de 410 481 lignes doit pouvoir être
 * repris, borné, rejoué et simulé. Deux écritures du même `UPDATE` auraient
 * fini par diverger — c'est le défaut caractéristique de ce dépôt.
 *
 * ── POURQUOI ELLE NE FAIT PAS ROUGIR LE DÉPLOIEMENT ────────────────────────
 *
 * La clé est un HMAC-SHA256 dont le secret (`PII_ENCRYPTION_KEY`) vit côté
 * site. Tant que `CRM_PERSON_KEY_SECRET` n'est pas posé côté CRM, la migration
 * ne peut RIEN remplir — et surtout, elle ne doit rien inventer : une clé
 * calculée avec un autre secret rendrait la fiche 360° cliquable tout en
 * garantissant qu'aucun rapprochement n'aboutisse, et rendrait muets l'export
 * art. 15 et l'effacement art. 17 servis par `POST /internal/site-sync/gdpr`.
 *
 * Dans ce cas elle JOURNALISE l'état exact (combien de fiches attendent) et
 * passe. Un déploiement ne rougit pas ; mais l'attente est écrite, avec son
 * chiffre, au lieu de rester invisible comme elle l'a été jusqu'ici.
 *
 * ── APRÈS AVOIR POSÉ LE SECRET ─────────────────────────────────────────────
 *
 *     php artisan crm:remplir-cle-personne --simulation   # ce qui serait fait
 *     php artisan crm:remplir-cle-personne                # le faire
 *
 * ⚠️ La migration ne borne pas le nombre de lots : sur une base déjà pourvue du
 * secret au moment du déploiement, elle traite tout le stock. La commande
 * accepte `--max-lots` pour étaler le travail si l'exploitant le préfère.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enAttente = $this->fichesSansCle();

        if (! CleDePersonne::estConfiguree()) {
            Log::warning(
                '[A05-001] Remplissage de contacts.person_key AJOURNE : CRM_PERSON_KEY_SECRET '
                . "n'est pas configure. {$enAttente} fiche(s) portent une adresse et aucune cle "
                . 'de rapprochement ; la fiche 360 leur reste inatteignable. Poser '
                . 'CRM_PERSON_KEY_SECRET = PII_ENCRYPTION_KEY du site, puis jouer '
                . '`php artisan crm:remplir-cle-personne`.',
            );

            return;
        }

        Artisan::call(CrmRemplirClePersonne::class);

        Log::info(
            "[A05-001] Remplissage de contacts.person_key joue au deploiement : {$enAttente} "
            . 'fiche(s) etaient en attente. Restant : ' . $this->fichesSansCle() . '.',
        );
    }

    public function down(): void
    {
        // Pas de retour en arrière. Effacer les clés reposées remettrait la
        // fiche 360° hors d'atteinte pour tout le stock ; et rien ne
        // distinguerait, dans la colonne, une clé reposée ici d'une clé venue
        // du site — les deux sont le même HMAC de la même adresse, ce qui est
        // précisément l'objectif.
    }

    /**
     * Compte SANS contexte workspace : les migrations tournent avec le rôle
     * propriétaire, qui n'est pas soumis aux policies. Si un jour ce n'était
     * plus vrai, ce compte tomberait à 0 — d'où le message de la commande, qui
     * lui compte espace par espace.
     */
    private function fichesSansCle(): int
    {
        return (int) DB::table('contacts')
            ->whereNull('person_key')
            ->whereNotNull('email')
            ->whereRaw("btrim(email::text) <> ''")
            ->count();
    }
};
