<?php

namespace App\Envoi\Campagnes;

use App\Envoi\Exceptions\ModeleRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Modèles de message versionnés. Aucune suppression : restaurer = nouvelle version ; archiver = statut `archive` + `archive_le`.
 * Statuts du contrat (`creation.yaml` StatutMessage) ; `version_validee` = la seule version utilisable dans une campagne.
 */
final class DepotModeles
{
    /** UNE seule table de vérité des transitions (la base porte la même matrice : un test les compare). */
    public const TRANSITIONS = [
        'brouillon' => ['a_relire', 'archive'],
        'a_relire' => ['brouillon', 'valide', 'archive'],
        'valide' => ['brouillon', 'a_relire', 'archive'],
        'archive' => [],
    ];

    public static function autorisee(string $de, string $vers): bool
    {
        return in_array($vers, self::TRANSITIONS[$de] ?? [], true);
    }

    public function creer(string $ws, string $nom, string $objet, string $corps, string $auteur): string
    {
        $this->verifier($objet, $corps, $auteur, $nom);

        return WorkspaceContext::run($ws, function () use ($ws, $nom, $objet, $corps, $auteur): string {
            $id = (string) Str::uuid();
            DB::transaction(function () use ($ws, $id, $nom, $objet, $corps, $auteur): void {
                DB::table('envoi_modeles')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => $nom, 'cree_par' => $auteur]);
                $this->ajouter($ws, $id, 1, $objet, $corps, $auteur, null);
            });

            return $id;
        });
    }

    /** Nouvelle version (numéro suivant). Le modèle repasse brouillon : le nouveau contenu n'est ni relu ni validé. */
    public function nouvelleVersion(string $ws, string $id, string $objet, string $corps, string $auteur): int
    {
        $this->verifier($objet, $corps, $auteur);

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

    /**
     * Change le statut selon la table de transitions. `valide` exige le numéro de la version validée (une version existante
     * de ce modèle) ; les autres statuts n'en gardent pas, sauf `archive` qui conserve celle qui était validée.
     */
    public function changerStatut(string $ws, string $id, string $vers, ?int $versionValidee = null): void
    {
        WorkspaceContext::run($ws, function () use ($ws, $id, $vers, $versionValidee): void {
            $m = DB::table('envoi_modeles')->where('workspace_id', $ws)->where('id', $id)->lockForUpdate()->first() ?? throw ModeleRefuse::introuvable();
            if (! self::autorisee($m->statut, $vers)) {
                throw ModeleRefuse::transition($m->statut, $vers);
            }
            if (($vers === 'valide') !== ($versionValidee !== null) && $vers !== 'archive') {
                throw ModeleRefuse::contenu($vers === 'valide' ? 'la version à valider est obligatoire' : 'seul « valide » porte une version validée');
            }
            $champs = ['statut' => $vers, 'version_validee' => $vers === 'archive' ? $m->version_validee : $versionValidee];
            if ($vers === 'archive') {
                $champs['archive_le'] = DB::raw('now()');   // l'heure de la base, jamais une date libre
            }
            // point de sauvegarde : un refus de la base (clé étrangère, déclencheur) n'abandonne pas la transaction englobante
            DB::transaction(fn () => DB::table('envoi_modeles')->where('workspace_id', $ws)->where('id', $id)->update($champs));
        });
    }

    public function archiver(string $ws, string $id): void
    {
        $this->changerStatut($ws, $id, 'archive');
    }

    /** @return list<\stdClass> versions, de la plus récente à la plus ancienne */
    public function versions(string $ws, string $id): array
    {
        return array_values(WorkspaceContext::run($ws, fn () => DB::table('envoi_modele_versions')->where('workspace_id', $ws)->where('modele_id', $id)->orderByDesc('numero')->get()->all()));
    }

    private function suivante(string $ws, string $id, string $objet, string $corps, string $auteur, ?int $restaureDe): int
    {
        $m = DB::table('envoi_modeles')->where('workspace_id', $ws)->where('id', $id)->lockForUpdate()->first() ?? throw ModeleRefuse::introuvable();
        if ($m->statut === 'archive') {
            throw ModeleRefuse::archive();
        }
        $n = (int) DB::table('envoi_modele_versions')->where('workspace_id', $ws)->where('modele_id', $id)->max('numero') + 1;
        $this->ajouter($ws, $id, $n, $objet, $corps, $auteur, $restaureDe);
        if ($m->statut !== 'brouillon') {
            DB::table('envoi_modeles')->where('workspace_id', $ws)->where('id', $id)->update(['statut' => 'brouillon', 'version_validee' => null]);
        }

        return $n;
    }

    private function verifier(string $objet, string $corps, string $auteur, ?string $nom = null): void
    {
        foreach ([['objet', $objet, 300], ['corps', $corps, 20000], ['auteur', $auteur, 100], ['nom', $nom ?? 'x', 200]] as [$champ, $valeur, $max]) {
            if ($valeur === '' || mb_strlen($valeur) > $max) {
                throw ModeleRefuse::contenu("{$champ} de 1 à {$max} caractères");
            }
        }
    }

    private function ajouter(string $ws, string $id, int $numero, string $objet, string $corps, string $auteur, ?int $restaureDe): void
    {
        DB::table('envoi_modele_versions')->insert([
            'workspace_id' => $ws, 'modele_id' => $id, 'numero' => $numero, 'objet' => $objet, 'corps' => $corps,
            'auteur' => $auteur, 'restaure_de' => $restaureDe,
        ]);
    }
}
