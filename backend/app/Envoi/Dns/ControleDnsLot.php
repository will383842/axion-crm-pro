<?php

namespace App\Envoi\Dns;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Parcourt tous les domaines d'envoi : une erreur sur un domaine (ou sur la lecture des
 * domaines d'un espace) est comptée puis le lot continue. Les avertissements ne citent
 * jamais le nom du domaine.
 */
final class ControleDnsLot
{
    /**
     * @param  array<string, array<string, string>>  $config
     * @param  Closure(): iterable<string>  $espaces
     * @param  Closure(string): iterable<string>  $domainesDe
     * @param  Closure(string): void  $avertir
     * @param  Closure(string): void  $afficher
     */
    public function __construct(
        private readonly ControleQuotidien $controle,
        private readonly array $config,
        private readonly Closure $espaces,
        private readonly Closure $domainesDe,
        private readonly Closure $avertir,
        private readonly Closure $afficher,
    ) {}

    /** @return int nombre de domaines (ou lectures) en échec */
    public function executer(): int
    {
        $echecs = 0;
        try {
            $espaces = [...($this->espaces)()];
        } catch (Throwable) {
            ($this->avertir)('ALERTE : lecture de la liste des espaces impossible : aucun domaine contrôlé');

            return 1;
        }
        foreach ($espaces as $ws) {
            $ws = (string) $ws;
            try {
                $noms = [...($this->domainesDe)($ws)];
            } catch (Throwable) {
                $echecs++;
                ($this->avertir)('lecture des domaines d\'un espace impossible : espace ignoré');

                continue;
            }
            foreach ($noms as $nom) {
                $p = $this->config[(string) $nom] ?? null;
                if ($p === null) {
                    $echecs++; // un domaine d'envoi non contrôlé n'est pas « tout va bien »
                    ($this->avertir)('domaine sans configuration DNS : non contrôlé');

                    continue;
                }
                try {
                    $c = new ConfigurationDomaine((string) $nom, (string) ($p['selecteur'] ?? ''), (string) ($p['helo'] ?? ''), (string) ($p['ip'] ?? ''));
                } catch (InvalidArgumentException) {
                    $echecs++;
                    ($this->avertir)('configuration DNS invalide : non contrôlé');

                    continue;
                }
                try {
                    $r = $this->controle->controler($ws, $c);
                    ($this->afficher)(sprintf('%s : %s%s', $c->domaine, $r->pret() ? 'prêt' : 'pas prêt', $r->pause() ? ' (pause)' : ''));
                } catch (Throwable) {
                    $echecs++;
                    ($this->avertir)('contrôle DNS en échec pour un domaine : suite du lot');
                }
            }
        }

        return $echecs;
    }
}
