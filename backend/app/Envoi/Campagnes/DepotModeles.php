<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Exceptions\ModeleRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Modèles de message versionnés. Aucune suppression : restaurer = nouvelle version ; archiver = `archive_le`. */
final class DepotModeles
{
    public function creer(string $ws, string $nom, string $objet, string $corps, string $auteur): string
    {
        return WorkspaceContext::run($ws, function () use ($ws, $nom, $objet, $corps, $auteur): string {
            $id = (string) Str::uuid();
            DB::transaction(function () use ($ws, $id, $nom, $objet, $corps, $auteur): void {
                DB::table('envoi_modeles')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => $nom, 'cree_par' => $auteur]);
                $this->ajouter($ws, $id, 1, $objet, $corps, $auteur, null);
            });

            return $id;
        });
    }

    /** Nouvelle version (numéro suivant). Un modèle archivé n'en reçoit plus. */
    public function nouvelleVersion(string $ws, string $id, string $objet, string $corps, string $auteur): int
    {
        return WorkspaceContext::run($ws, fn () => DB::transaction(fn () => $this->suivante($ws, $id, $objet, $corps, $auteur, null)));
    }

    /** Restaurer la version $numero = recopier son contenu dans une NOUVELLE version ; l'historique reste intact. */
    public function restaurer(string $ws, string $id, int $numero, string $auteur): int
    {
        return WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($ws, $id, $numero, $auteur): int {
            $v = DB::table('envoi_modele_versions')->where('workspace_id', $ws)->where('modele_id', $id)->where('numero', $numero)->first()
                ?? throw ModeleRefuse::introuvable();

            return $this->suivante($ws, $id, $v->objet, $v->corps, $auteur, $numero);
        }));
    }

    public function archiver(string $ws, string $id): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $id): void {
            DB::table('envoi_modeles')->where('workspace_id', $ws)->where('id', $id)->whereNull('archive_le')->update(['archive_le' => now()]);
        });
    }

    /** @return list<\stdClass> versions, de la plus récente à la plus ancienne */
    public function versions(string $ws, string $id): array
    {
        return array_values(WorkspaceContext::run($ws, fn () => DB::table('envoi_modele_versions')->where('workspace_id', $ws)->where('modele_id', $id)->orderByDesc('numero')->get()->all()));
    }

    private function suivante(string $ws, string $id, string $objet, string $corps, string $auteur, ?int $restaureDe): int
    {
        $m = DB::table('envoi_modeles')->where('workspace_id', $ws)->where('id', $id)->lockForUpdate()->first() ?? throw ModeleRefuse::introuvable();
        if ($m->archive_le !== null) {
            throw ModeleRefuse::archive();
        }
        $n = (int) DB::table('envoi_modele_versions')->where('workspace_id', $ws)->where('modele_id', $id)->max('numero') + 1;
        $this->ajouter($ws, $id, $n, $objet, $corps, $auteur, $restaureDe);

        return $n;
    }

    private function ajouter(string $ws, string $id, int $numero, string $objet, string $corps, string $auteur, ?int $restaureDe): void
    {
        DB::table('envoi_modele_versions')->insert([
            'workspace_id' => $ws, 'modele_id' => $id, 'numero' => $numero, 'objet' => $objet, 'corps' => $corps,
            'auteur' => $auteur, 'restaure_de' => $restaureDe,
        ]);
    }
}
