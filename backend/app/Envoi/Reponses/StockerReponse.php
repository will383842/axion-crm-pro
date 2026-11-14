<?php

namespace App\Envoi\Reponses;

use App\Envoi\Imap\ExtraitTexte;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Stocke le TEXTE d'une réponse reçue (R12), chiffré. Une ligne par événement (rejeu = sans effet).
 * Seules les classes « réponse humaine », « réponse automatique », « désinscription » et « hostile » sont gardées :
 * jamais un rebond. Extrait en texte seul (balises retirées), 4 000 caractères au plus, aucune pièce jointe.
 * Le classement posé ici est celui du CLASSIFICATEUR : une suggestion, jamais un geste humain.
 * Conservation : cinq ans après réception, sans suppression (règle S8).
 */
final class StockerReponse
{
    public const CLASSES = ['reponse_humaine', 'auto_reponse', 'desinscription', 'hostile'];

    /** Confiance à partir de laquelle un « stop » automatique est proposé (en dessous : jamais « stop »). */
    public const CONFIANCE_STOP = 90;

    /** @var array<string, array{0: string, 1: int}> classe de retour → [classement, confiance] */
    private const SUGGESTION = [
        'desinscription' => ['stop', 95],
        'auto_reponse' => ['absence', 70],
        'hostile' => ['refus', 60],
        'reponse_humaine' => ['a_rattacher', 0],
    ];

    /** @return bool vrai si une ligne a été écrite */
    public function enregistrer(string $workspaceId, string $cleMessage, string $objet, string $extrait, string $adresse, string $classe): bool
    {
        if (! in_array($classe, self::CLASSES, true)) {
            return false;
        }

        return WorkspaceContext::run($workspaceId, function () use ($workspaceId, $cleMessage, $objet, $extrait, $adresse, $classe): bool {
            $evenement = DB::table('envoi_evenements')->where('workspace_id', $workspaceId)
                ->where('empreinte_message', $cleMessage)->orderBy('id')->first();
            if ($evenement === null) {
                return false;
            }
            $texte = str_contains($extrait, '<') ? ExtraitTexte::depuisHtml($extrait) : mb_substr($extrait, 0, ExtraitTexte::LIMITE);

            return DB::transaction(function () use ($workspaceId, $evenement, $objet, $texte, $adresse, $classe): bool {
                $id = DB::selectOne(
                    'INSERT INTO envoi_reponses (workspace_id, evenement_id, objet_chiffre, extrait_chiffre, expediteur_chiffre,
                        empreinte_expediteur, recue_le, conserver_jusqu_au)
                     VALUES (?, ?, ?, ?, ?, ?, ?, (?::timestamptz + interval \'5 years\')::date)
                     ON CONFLICT (evenement_id) DO NOTHING RETURNING id',
                    [
                        $workspaceId, $evenement->id, Crypt::encryptString(mb_substr($objet, 0, 500)), Crypt::encryptString($texte),
                        Crypt::encryptString(mb_strtolower(trim($adresse))), ListeSuppression::empreinte($adresse),
                        $evenement->recu_le, $evenement->recu_le,
                    ],
                );
                if ($id === null) {
                    return false;
                }
                [$classement, $confiance] = self::SUGGESTION[$classe];
                DB::table('envoi_reponses_classements')->insert([
                    'workspace_id' => $workspaceId, 'reponse_id' => $id->id, 'geste' => 'classement',
                    'classement' => $classement, 'confiance' => $confiance, 'auteur' => 'classificateur', 'cree_le' => now(),
                ]);

                return true;
            });
        });
    }
}
