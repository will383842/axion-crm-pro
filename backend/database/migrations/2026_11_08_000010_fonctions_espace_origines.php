<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fonction d'espace `origines.lecture` (PR-A, L6) — ADDITIF SEULEMENT.
 *
 * Une ligne `('<espace d'Axion>', 'origines.lecture', true)` dans
 * `fonctions_espace`, lue comme le fait `2026_11_02_000010_fonctions_espace`
 * (`workspaces.slug = 'axion-ia'`, jamais d'UUID en dur). Les autres espaces ne
 * reçoivent aucune ligne (= fermé). `ON CONFLICT DO NOTHING` : une ligne
 * déjà posée (ou fermée à la main) n'est jamais modifiée.
 *
 * Ne change rien en production par elle-même : l'API de lecture reste derrière
 * `origines.lecture_enabled` (éteint par défaut).
 */
return new class extends Migration
{
    public function up(): void
    {
        $axion = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
        if (! is_string($axion) || $axion === '') {
            return;
        }

        DB::statement(
            'INSERT INTO fonctions_espace (espace_id, fonction, ouverte, created_at, updated_at) VALUES (?, ?, true, now(), now()) ON CONFLICT (espace_id, fonction) DO NOTHING',
            [$axion, 'origines.lecture'],
        );
    }

    /** Retour arrière INERTE : on ne retire aucune ligne de réglage. */
    public function down(): void
    {
        // Rien : la ligne reste, fermer la fonction se fait par `ouverte = false`.
    }
};
