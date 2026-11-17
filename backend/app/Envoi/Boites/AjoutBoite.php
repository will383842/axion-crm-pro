<?php

namespace App\Envoi\Boites;

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Creation\ErreurCreation;
use App\Envoi\Smtp\DepotBoites;
use App\Envoi\Smtp\EnvoiSmtpRefuse;
use App\Socle\Alertes\CanalEmail;
use App\Support\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F3-B1 — ajout d'une boîte par l'écran, en deux temps : (1) code de confirmation envoyé à l'adresse de connexion du
 * propriétaire (mêmes règles que R11 : HMAC, 10 min, 5 essais, 3 codes par 15 min, transport sûr) ; (2) création avec le
 * secret et le code. Le code est lié à la demande SANS secret (`demande_empreinte`) : un code demandé pour A ne crée pas B.
 * La boîte naît « en_pause / suspension_manuelle » DANS la transaction : elle ne part jamais seule.
 * Jamais de journal du corps ni du secret ; aucune connexion SMTP n'est ouverte ici.
 */
final class AjoutBoite
{
    public const CODE_VALIDITE_MINUTES = 10;

    public const CODES_PAR_FENETRE = 3;

    public const FENETRE_MINUTES = 15;

    public const ESSAIS_MAX = 5;

    public function __construct(private readonly DepotBoites $boites, private readonly CanalEmail $canal) {}

    /**
     * @param  array{adresse: string, hote: string, port: int, chiffrement: string, utilisateur: string}  $demande
     * @return array{expire_le: string}
     */
    public function demanderCode(string $ws, string $userId, string $email, array $demande): array
    {
        // Le code est un secret : AVANT toute écriture et tout tirage, refuser un transport qui le laisserait dans un journal.
        if (! CanalEmail::transportSur()) {
            throw new ErreurCreation('transport_courriel_non_sur', 'Le courriel du code ne peut pas partir : le transport d\'authentification n\'est pas sûr (MAIL_MAILER_AUTH). Prévenez l\'administrateur.', 503);
        }
        $code = sprintf('%06d', random_int(0, 999999));
        $empreinte = DepotCampagnes::empreinteCode($code, (int) config('envoi.cle_codes_campagne_version', 1));
        $liaison = self::empreinteDemande($demande);
        $expire = $this->sousVerrou($ws, function () use ($ws, $userId, $demande, $empreinte, $liaison): string {
            $this->exigerDomaine($ws, $demande['adresse']);
            $recents = DB::table('envoi_boites_codes_ajout')->where('workspace_id', $ws)->where('user_id', $userId)
                ->where('cree_le', '>', DB::raw("now() - interval '" . self::FENETRE_MINUTES . " minutes'"))->count();
            if ($recents >= self::CODES_PAR_FENETRE) {
                throw new ErreurCreation('code_trop_frequent', 'Trois codes ont déjà été envoyés ces 15 dernières minutes : patientez avant d\'en demander un autre.', 429);
            }

            return (string) DB::selectOne(
                "INSERT INTO envoi_boites_codes_ajout (workspace_id, user_id, empreinte, demande_empreinte, expire_le) VALUES (?, ?, ?, ?, now() + interval '" . self::CODE_VALIDITE_MINUTES . " minutes') RETURNING expire_le",
                [$ws, $userId, $empreinte, $liaison],
            )->expire_le;
        });
        $envoye = $this->canal->envoyerA($email, 'Votre code d\'ajout de boîte', "Votre code d'ajout de boîte : {$code}\nIl est valable " . self::CODE_VALIDITE_MINUTES . ' minutes et ne sert qu\'une fois. Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message.');
        if (! $envoye) {
            throw new ErreurCreation('courriel_indisponible', 'Le courriel du code n\'a pas pu partir : réessayez dans quelques minutes.', 503);
        }

        return ['expire_le' => Carbon::parse($expire)->toIso8601String()];
    }

    /**
     * @param  array{adresse: string, hote: string, port: int, chiffrement: string, utilisateur: string}  $demande
     * @return array{id: string, etat: string, pause_motif: string}
     */
    public function creer(string $ws, string $userId, array $demande, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code): array
    {
        $liaison = self::empreinteDemande($demande);
        $empreinte = DepotCampagnes::empreinteCode($code, (int) config('envoi.cle_codes_campagne_version', 1));
        // Le verdict d'un code faux est rendu APRÈS le COMMIT : un refus ne doit pas défaire le compte des tentatives.
        $resultat = $this->sousVerrou($ws, function () use ($ws, $userId, $demande, $secret, $liaison, $empreinte): array|string {
            $vivants = DB::table('envoi_boites_codes_ajout')->where('workspace_id', $ws)->where('user_id', $userId)
                ->whereNull('consomme_le')->where('expire_le', '>', DB::raw('now()'))->where('tentatives', '<', self::ESSAIS_MAX)
                ->orderByDesc('cree_le')->get(['id', 'empreinte', 'demande_empreinte']);
            $bon = null;
            foreach ($vivants as $v) {
                if (hash_equals((string) $v->empreinte, $empreinte) && hash_equals((string) $v->demande_empreinte, $liaison)) {
                    $bon = $v;
                    break;
                }
            }
            if ($bon === null) {
                // Faux, ou code valable pour une AUTRE demande : une tentative de plus sur chaque code vivant.
                DB::table('envoi_boites_codes_ajout')->whereIn('id', $vivants->pluck('id')->all())
                    ->update(['tentatives' => DB::raw('LEAST(tentatives + 1, ' . self::ESSAIS_MAX . ')')]);

                return 'code';
            }
            $this->exigerDomaine($ws, $demande['adresse']);
            if (DB::table('envoi_boites')->where('workspace_id', $ws)->whereRaw('lower(adresse) = ?', [$demande['adresse']])->exists()) {
                throw ErreurCreation::conflit('boite_existante', 'Cette boîte existe déjà dans cet espace.');
            }
            // Usage unique : l'UPDATE conditionnel est l'arbitre (une seule des demandes simultanées le gagne).
            $pris = DB::select('UPDATE envoi_boites_codes_ajout SET consomme_le = now() WHERE id = ? AND consomme_le IS NULL AND expire_le > now() AND tentatives < ? RETURNING id', [$bon->id, self::ESSAIS_MAX]);
            if ($pris === []) {
                return 'code';
            }
            try {
                $id = $this->boites->creer($ws, $demande['adresse'], $demande['hote'], $demande['port'], $demande['chiffrement'], $demande['utilisateur'], $secret);
            } catch (EnvoiSmtpRefuse) {
                throw ErreurCreation::invalide('hote_refuse', 'Cet hôte, ce port ou ce chiffrement est refusé.');   // annule aussi la consommation du code
            }
            // Dans la MÊME transaction : une boîte neuve ne part jamais seule (le propriétaire la réactive par le geste existant).
            $this->boites->mettreEnPause($ws, $id, DepotBoites::MOTIF_SUSPENSION_MANUELLE);

            return ['id' => $id, 'etat' => 'en_pause', 'pause_motif' => DepotBoites::MOTIF_SUSPENSION_MANUELLE];
        });
        if ($resultat === 'code') {
            throw ErreurCreation::conflit('code_invalide', 'Code invalide, expiré, déjà utilisé ou trop d\'essais : demandez un nouveau code.');
        }

        return $resultat;
    }

    /**
     * SHA-256 du corps SANS le secret, forme canonique : lie le code à CETTE demande exacte.
     *
     * @param  array<string, mixed>  $demande
     */
    public static function empreinteDemande(array $demande): string
    {
        return hash('sha256', json_encode([
            strtolower((string) $demande['adresse']), strtolower(trim((string) $demande['hote'])), (int) $demande['port'],
            (string) $demande['chiffrement'], (string) $demande['utilisateur'],
        ], JSON_THROW_ON_ERROR));
    }

    private function exigerDomaine(string $ws, string $adresse): void
    {
        $domaine = strtolower(substr(strrchr($adresse, '@') ?: '', 1));
        $existe = $domaine !== '' && DB::table('envoi_domaines')->where('workspace_id', $ws)->whereRaw('lower(nom) = ?', [$domaine])->exists();
        if (! $existe) {
            throw ErreurCreation::conflit('domaine_inconnu', 'Le domaine de cette adresse n\'est pas un domaine d\'envoi de cet espace.');
        }
    }

    /** Une transaction sous le verrou consultatif de l'espace, dans le contexte d'espace (RLS). */
    private function sousVerrou(string $ws, \Closure $f): mixed
    {
        return WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($ws, $f) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_boites_ajout:' . $ws]);

            return $f();
        }));
    }
}
