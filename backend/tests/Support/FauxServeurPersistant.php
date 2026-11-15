<?php

namespace Tests\Support;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * P1-REPRISE — faux serveur d'envoi dont le REGISTRE survit à la mort du processus (`FauxServeurEnvoi` garde le sien en mémoire).
 * Chaque appel à `injecter` écrit UNE ligne dans une table de test, sur la connexion propriétaire (auto-commit : visible des
 * autres processus, jamais défaite par une transaction). Il ne dédoublonne PAS par clé et n'implémente pas `RenvoiParMemeCle`
 * (comme `AiguilleurServeurEnvoi`, D11) : un second appel pour la même clé est donc visible, c'est ce que le test traque.
 * Aucun réseau. Table créée et retirée par le test (jamais par une migration).
 */
final class FauxServeurPersistant implements ServeurEnvoi
{
    public const TABLE = 'zz_p1_faux_serveur';

    /** Points d'arrêt : appelés avant / après l'écriture du registre (le processus s'y fait tuer). */
    public ?Closure $pendantAppel = null;

    public ?Closure $apresAcceptation = null;

    public function __construct(private readonly string $run) {}

    public static function creerTable(): void
    {
        DB::connection('pgsql_owner')->unprepared('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (n BIGSERIAL PRIMARY KEY, run TEXT NOT NULL, cle TEXT NOT NULL, cree_le TIMESTAMPTZ NOT NULL DEFAULT now())');
    }

    public static function retirerTable(): void
    {
        DB::connection('pgsql_owner')->unprepared('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    /** @return array<string, int> clé d'idempotence => nombre d'appels qui ont « envoyé » */
    public static function appels(string $run): array
    {
        $out = [];
        foreach (DB::connection('pgsql_owner')->table(self::TABLE)->where('run', $run)->orderBy('n')->pluck('cle') as $cle) {
            $out[(string) $cle] = ($out[(string) $cle] ?? 0) + 1;
        }

        return $out;
    }

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        if (DB::transactionLevel() > 0) {
            throw ServeurEnvoiRefuse::motif('injecter() ne s’appelle pas dans une transaction englobante.');
        }
        if ($this->pendantAppel !== null) {
            ($this->pendantAppel)($message->cleIdempotence);
        }
        $n = (int) DB::connection('pgsql_owner')->table(self::TABLE)->insertGetId(['run' => $this->run, 'cle' => $message->cleIdempotence], 'n');
        if ($this->apresAcceptation !== null) {
            ($this->apresAcceptation)($message->cleIdempotence);
        }

        return ResultatInjection::accepte('sim-p1-' . $n);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        return EtatMessage::Inconnu;
    }

    public function pause(): void {}

    public function reprise(): void {}

    public function modeTemoins(): bool
    {
        return true;
    }

    public function etat(): EtatServeur
    {
        return new EtatServeur(false, count(self::appels($this->run)));
    }

    public function sante(): SanteServeur
    {
        return new SanteServeur(true, 'simulateur persistant');
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        return new LotEvenements([], $curseur);
    }
}
