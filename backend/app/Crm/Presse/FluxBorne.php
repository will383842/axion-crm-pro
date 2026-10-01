<?php

namespace App\Crm\Presse;

/**
 * UN FLUX D'ÉCRITURE PLAFONNÉ — la destination (`sink`) d'une réponse HTTP
 * dont on ne veut jamais garder plus de N octets (relecture A09 de #270).
 *
 * Gestionnaire de flux PHP (`stream_wrapper_register`) : curl y écrit le corps
 * AU FIL DU TÉLÉCHARGEMENT. Au premier octet au-delà du plafond, l'écriture
 * est refusée (on rend moins d'octets que reçus) : curl interrompt le transfert
 * (CURLE_WRITE_ERROR), le flux est marqué `depasse`, et RIEN n'est gardé
 * au-delà du plafond — ni en mémoire, ni sur disque. Le corps est gardé tel
 * que reçu, compressé (`decode_content` est coupé par l'appelant) : la
 * décompression, plafonnée elle aussi, est faite à part.
 *
 * Usage : `[$ressource, $id] = FluxBorne::ouvrir($max)`, puis
 * `FluxBorne::depasse($id)` et `FluxBorne::liberer($id)`.
 */
final class FluxBorne
{
    public const PROTOCOLE = 'axionborne';

    /** @var resource|null contexte fourni par PHP */
    public $context;

    /** @var array<string, array{donnees: string, max: int, depasse: bool}> */
    private static array $etats = [];

    private static int $compteur = 0;

    private string $id = '';

    private int $position = 0;

    /**
     * Ouvre un flux plafonné à `$max` octets.
     *
     * @return array{0: resource, 1: string}
     */
    public static function ouvrir(int $max): array
    {
        if (! in_array(self::PROTOCOLE, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOLE, self::class);
        }
        $id = 'f' . (++self::$compteur);
        self::$etats[$id] = ['donnees' => '', 'max' => max(0, $max), 'depasse' => false];
        $ressource = fopen(self::PROTOCOLE . '://' . $id, 'w+');
        if ($ressource === false) {
            throw new \RuntimeException('Flux plafonné impossible à ouvrir.');
        }

        return [$ressource, $id];
    }

    public static function depasse(string $id): bool
    {
        return self::$etats[$id]['depasse'] ?? false;
    }

    public static function liberer(string $id): void
    {
        unset(self::$etats[$id]);
    }

    public function stream_open(string $chemin, string $mode, int $options, ?string &$ouvert): bool
    {
        $this->id = substr($chemin, strlen(self::PROTOCOLE . '://'));

        return isset(self::$etats[$this->id]);
    }

    public function stream_write(string $donnees): int
    {
        if (! isset(self::$etats[$this->id])) {
            return 0;
        }
        $etat = &self::$etats[$this->id];
        if ($etat['depasse']) {
            return 0;
        }
        $longueur = strlen($donnees);
        if (strlen($etat['donnees']) + $longueur > $etat['max']) {
            // Rendre MOINS que reçu : curl arrête le transfert. Rien n'est gardé.
            $etat['depasse'] = true;

            return max(0, $longueur - 1);
        }
        $etat['donnees'] .= $donnees;

        return $longueur;
    }

    public function stream_read(int $combien): string
    {
        $donnees = self::$etats[$this->id]['donnees'] ?? '';
        $morceau = substr($donnees, $this->position, $combien);
        $this->position += strlen($morceau);

        return $morceau;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$etats[$this->id]['donnees'] ?? '');
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $decalage, int $origine = SEEK_SET): bool
    {
        $taille = strlen(self::$etats[$this->id]['donnees'] ?? '');
        $cible = match ($origine) {
            SEEK_CUR => $this->position + $decalage,
            SEEK_END => $taille + $decalage,
            default => $decalage,
        };
        if ($cible < 0 || $cible > $taille) {
            return false;
        }
        $this->position = $cible;

        return true;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return ['size' => strlen(self::$etats[$this->id]['donnees'] ?? '')];
    }

    public function stream_close(): void {}
}
