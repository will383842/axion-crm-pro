<?php

namespace App\Envoi\Temoins;

use App\Envoi\EmpreinteTemoin;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Accès IMAP des témoins (`envoi_temoins_acces`, ajout seul). L'identifiant (adresse + mot de passe) est CHIFFRÉ
 * avec la clé de l'application ; l'hôte vient de la liste fermée {@see HotesTemoins}. Écrit seulement par la commande
 * locale `envoi:temoins-acces`. La dernière ligne d'un témoin fait foi.
 */
final class DepotAccesTemoins
{
    public function poser(string $ws, string $fournisseur, string $adresse, #[SensitiveParameter] string $motDePasse): void
    {
        $fiche = HotesTemoins::pour($fournisseur);
        $empreinte = EmpreinteTemoin::pourDecision($adresse);
        if ($empreinte === null || filter_var($adresse, FILTER_VALIDATE_EMAIL) === false || $motDePasse === '') {
            throw new InvalidArgumentException('Accès refusé : adresse ou mot de passe invalide.');
        }
        $identifiant = Crypt::encryptString(json_encode(['adresse' => EmpreinteTemoin::normaliser($adresse), 'mot_de_passe' => $motDePasse], JSON_THROW_ON_ERROR));
        WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')->insert([
            'workspace_id' => $ws,
            'empreinte_temoin' => $empreinte,
            'fournisseur' => $fournisseur,
            'hote' => $fiche['hote'],
            'identifiant_chiffre' => $identifiant,
        ]));
    }

    /** @return list<AccesTemoin> un par témoin (dernière ligne) */
    public function lister(string $ws): array
    {
        $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')
            ->where('workspace_id', $ws)->orderByDesc('id')->get());
        $vus = [];
        $sortie = [];
        foreach ($lignes as $l) {
            if (isset($vus[$l->empreinte_temoin]) || ! HotesTemoins::hoteAutorise((string) $l->hote)) {
                continue; // plus récent déjà pris ; hôte hors liste (base altérée) : ignoré, jamais joint
            }
            $vus[$l->empreinte_temoin] = true;
            $d = json_decode(Crypt::decryptString((string) $l->identifiant_chiffre), true, 4, JSON_THROW_ON_ERROR);
            $sortie[] = new AccesTemoin($ws, (string) $l->fournisseur, (string) $l->hote, (string) $d['adresse'], (string) $d['mot_de_passe'], (string) $l->empreinte_temoin);
        }

        return array_reverse($sortie);
    }
}
