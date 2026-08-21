<?php

namespace Tests\Support;

use Illuminate\Redis\Connectors\PredisConnector;
use Illuminate\Support\Facades\Redis;
use Predis\Command\CommandInterface;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\ParametersInterface;

/**
 * ENREGISTREUR DE COMMANDES REDIS — il capture la clef REELLEMENT EMISE.
 *
 * ── Pourquoi cette classe existe, et pourquoi un Mockery ne suffisait pas ───
 *
 * `tests/Unit/Http/SsrfSitesJumeauxTest.php` espionne le pont Laravel -> Node
 * avec `Redis::shouldReceive('connection')->with('queue')`. Cet espion-la voit
 * l'argument que le CODE PHP passe (`axion:scrape:website`) et s'arrete la. Or
 * le defaut C18-011 ne vit pas dans cet argument : il vit APRES, dans le client
 * Redis, qui colle `options.prefix` (`axion_crm_pro_database_`) devant la clef
 * avant de l'ecrire. Un espion pose au niveau de la facade est donc
 * structurellement AVEUGLE au defaut, et le sera toujours.
 *
 * Il en va de meme d'un test qui relirait `config('database.redis.…')` : il
 * verifierait qu'une chaine est ecrite quelque part, pas qu'une clef part.
 *
 * Cette classe descend d'un cran : elle se substitue au SOCKET de Predis. Toute
 * la chaine reelle est jouee — `RedisManager::resolve()`, la fusion des options
 * globales et par-connexion de `PredisConnector::connect()`, la fabrique de
 * commandes de Predis et son `KeyPrefixProcessor`. Ce qui arrive ici est
 * exactement la suite d'octets qui serait partie sur le fil.
 *
 * Aucun serveur Redis n'est requis : le banc `a35r` n'en a pas, et un test de
 * pont qui ne rougirait qu'en presence d'un Redis vivant ne serait joue nulle
 * part.
 */
final class PontRedisEnregistreur implements NodeConnectionInterface
{
    /**
     * Les commandes vues sur le fil depuis le dernier `capturer()`.
     *
     * @var list<array{commande: string, arguments: list<string>, base: int|string|null}>
     */
    public static array $emises = [];

    public function __construct(private ParametersInterface $parameters) {}

    /**
     * Branche l'enregistreur a la place du transport TCP de Predis.
     *
     * ⚠️ ORDRE : le `RedisManager` lit `database.redis` AU MOMENT ou il est
     * resolu, puis se garde en singleton. Toute modification de configuration
     * doit donc etre posee AVANT cet appel — d'ou l'oubli volontaire de
     * l'instance existante ici.
     */
    public static function installer(): void
    {
        self::$emises = [];

        app()->forgetInstance('redis');
        app()->forgetInstance('redis.connection');
        Redis::clearResolvedInstance('redis');

        Redis::extend('predis', fn () => new class extends PredisConnector
        {
            public function connect(array $config, array $options)
            {
                // Les options par-connexion l'emportent sur les globales dans
                // `PredisConnector::connect()` : on y greffe la fabrique de
                // connexions sans toucher au `prefix`, qui reste celui que la
                // configuration reelle a decide.
                $config['options'] = array_merge($config['options'] ?? [], [
                    'connections' => array_fill_keys(
                        ['tcp', 'redis', 'unix', 'tls', 'rediss'],
                        PontRedisEnregistreur::class,
                    ),
                ]);

                return parent::connect($config, $options);
            }
        });
    }

    /**
     * Joue `$action` et rend les commandes qu'elle a fait partir.
     *
     * @return list<array{commande: string, arguments: list<string>, base: int|string|null}>
     */
    public static function capturer(callable $action): array
    {
        self::$emises = [];
        $action();

        return self::$emises;
    }

    /** Premiere clef (argument 0) de la premiere commande capturee. */
    public static function premiereClef(array $capture): ?string
    {
        return $capture[0]['arguments'][0] ?? null;
    }

    public function connect() {}

    public function disconnect() {}

    public function isConnected()
    {
        return true;
    }

    public function writeRequest(CommandInterface $command)
    {
        $this->enregistrer($command);
    }

    public function readResponse(CommandInterface $command)
    {
        return null;
    }

    public function executeCommand(CommandInterface $command)
    {
        $this->enregistrer($command);

        // LPUSH rend la longueur de la liste ; 1 est une reponse plausible et
        // suffit a `parseResponse()`.
        return 1;
    }

    public function __toString()
    {
        return 'pont-redis-enregistreur';
    }

    public function getResource()
    {
        return null;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    public function addConnectCommand(CommandInterface $command) {}

    public function read()
    {
        return null;
    }

    private function enregistrer(CommandInterface $command): void
    {
        self::$emises[] = [
            'commande' => $command->getId(),
            'arguments' => array_map(strval(...), $command->getArguments()),
            'base' => $this->parameters->database ?? null,
        ];
    }
}
