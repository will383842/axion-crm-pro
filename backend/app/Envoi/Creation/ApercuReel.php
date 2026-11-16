<?php

namespace App\Envoi\Creation;

use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Campagnes\SelectionRefusee;
use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\LecteurVariables;
use App\Envoi\Message\LecteurVariablesDb;
use App\Envoi\Message\MessageRefuse;
use App\Envoi\Message\SignatureDkim;
use App\Envoi\Message\ValeursVariables;
use App\Envoi\Message\VariablesMessage;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * F2a-2 — aperçu sur de VRAIS destinataires de la cible d'un brouillon et vrai compte des champs manquants (cahier F2a §2.6).
 *
 * LECTURE SEULE : aucune écriture, aucun envoi, aucun appel à un serveur d'envoi. Les destinataires viennent de
 * `SelectionDestinataires` (X1-L2) : un destinataire opposé ou exclu n'apparaît JAMAIS, et une exclusion illisible fait
 * échouer l'appel (échec fermé : `SelectionRefusee`). Les valeurs suivent EXACTEMENT le chemin de l'envoi
 * (`LecteurVariablesDb` puis `ValeursVariables::nettoyer`, rendu par `ComposeurMessage::rendre`). Aucune adresse n'est rendue.
 */
final class ApercuReel
{
    public const EXEMPLES = 3;

    private const LOT_EXEMPLES = 50;

    private const LOTS_EXEMPLES_MAX = 10;

    private readonly ComposeurMessage $composeur;

    public function __construct(
        private readonly SelectionDestinataires $selection,
        private readonly ServiceMessagesTypes $messages,
        private readonly int $tailleCompte = 500,
        private readonly int $lotsCompteMax = 10,
        private readonly LecteurVariables $lecteur = new LecteurVariablesDb,
    ) {
        // Le rendu d'un gabarit ne dépend ni du domaine ni de la signature : valeurs de pure forme.
        $this->composeur = new ComposeurMessage('apercu.example.test', null, SignatureDkim::Serveur);
    }

    /**
     * Les 3 premiers destinataires RETENUS de la cible (ordre des clés, sans tirage).
     *
     * @return list<array{id:int, libelle:string}>
     */
    public function exemples(string $ws, string $brouillonId): array
    {
        $this->saisie($ws, $brouillonId);
        $sortie = [];
        $apres = 0;
        for ($i = 0; $i < self::LOTS_EXEMPLES_MAX && count($sortie) < self::EXEMPLES; $i++) {
            $lot = $this->selection->lot($ws, $brouillonId, 'entreprises', $apres, self::LOT_EXEMPLES);
            foreach ($lot->destinataires as $d) {
                if (count($sortie) < self::EXEMPLES) {
                    $sortie[] = ['id' => $d->contactId, 'libelle' => 'Destinataire ' . (count($sortie) + 1)];
                }
            }
            if ($lot->curseurSuivant === null) {
                break;
            }
            $apres = $lot->curseurSuivant;
        }

        return $sortie;
    }

    /**
     * Rendu objet + corps de l'étape pour ces destinataires (le pied légal n'est pas rendu ici).
     *
     * @return list<array{id:int, libelle:string, objet:string, corps:string, replis:list<string>}>
     *
     * @throws ErreurCreation étape hors de la séquence
     * @throws MessageRefuse gabarit sans valeur de repli
     */
    public function rendre(string $ws, string $brouillonId, int $etape): array
    {
        $contenu = $this->saisie($ws, $brouillonId)['contenu'] ?? [];
        $messageId = is_array($contenu) && is_array($contenu['sequence'] ?? null) ? ($contenu['sequence'][$etape - 1]['message_id'] ?? null) : null;
        if ($etape < 1 || ! is_string($messageId)) {
            throw ErreurCreation::invalide('etape_inconnue', 'Cette étape n\'existe pas dans la séquence du brouillon.');
        }
        $version = ServiceMessagesTypes::derniere($this->messages->lire($ws, $messageId));
        $utilisees = VariablesMessage::utilisees($version['objet'], $version['corps']);
        $exemples = $this->exemples($ws, $brouillonId);
        $valeurs = $this->valeurs($ws, array_column($exemples, 'id'), $utilisees);

        $sortie = [];
        foreach ($exemples as $e) {
            $v = $valeurs[$e['id']] ?? [];
            $sortie[] = $e + [
                'objet' => $this->composeur->rendre($version['objet'], $v),
                'corps' => $this->composeur->rendre($version['corps'], $v),
                'replis' => array_values(array_filter($utilisees, static fn (string $nom): bool => ! isset($v[$nom]))),
            ];
        }

        return $sortie;
    }

    /**
     * Pour chaque nom : destinataires retenus dont la valeur est vide OU écartée (le repli partira). Au plus
     * `tailleCompte × lotsCompteMax` destinataires sont parcourus, au-delà `partiel` vaut vrai.
     *
     * @param  list<string>  $noms
     * @return array<string, array{manquants:int, sur:int, partiel:bool}>
     */
    public function compter(string $ws, string $brouillonId, array $noms): array
    {
        $this->saisie($ws, $brouillonId);
        $noms = array_values(array_unique($noms));
        $connus = array_values(array_intersect($noms, VariablesMessage::NOMS));
        $manquants = array_fill_keys($noms, 0);
        $sur = 0;
        $partiel = false;
        $apres = 0;
        for ($i = 0; $i < $this->lotsCompteMax; $i++) {
            $lot = $this->selection->lot($ws, $brouillonId, 'entreprises', $apres, $this->tailleCompte);
            $ids = array_map(static fn ($d): int => $d->contactId, $lot->destinataires);
            $valeurs = $this->valeurs($ws, $ids, $connus);
            foreach ($ids as $id) {
                foreach ($noms as $nom) {
                    if (! isset($valeurs[$id][$nom])) {   // nom inconnu : jamais lu, toujours le repli
                        $manquants[$nom]++;
                    }
                }
            }
            $sur += count($ids);
            if ($lot->curseurSuivant === null) {
                break;
            }
            $apres = $lot->curseurSuivant;
            $partiel = $i === $this->lotsCompteMax - 1;
        }

        return array_map(static fn (int $m): array => ['manquants' => $m, 'sur' => $sur, 'partiel' => $partiel], $manquants);
    }

    /**
     * Valeurs NETTOYÉES (même chemin que l'envoi) ; aucune requête sans nom connu.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $noms
     * @return array<int, array<string, string>>
     */
    private function valeurs(string $ws, array $ids, array $noms): array
    {
        $noms = array_values(array_intersect($noms, VariablesMessage::NOMS));
        if ($ids === [] || $noms === []) {
            return [];
        }
        $sortie = [];
        foreach ($this->lecteur->pourContacts($ws, $ids, $noms) as $id => $brutes) {
            $sortie[(int) $id] = ValeursVariables::nettoyer($brutes)['valeurs'];
        }

        return $sortie;
    }

    /** @return array<string, mixed> la saisie du brouillon ; refus de sélection (introuvable) si le brouillon n'est pas de cet espace. */
    private function saisie(string $ws, string $brouillonId): array
    {
        $ligne = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $brouillonId) === 1
            ? WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT saisie FROM envoi_campagnes WHERE workspace_id = ? AND id = ?', [$ws, $brouillonId]))
            : null;
        if ($ligne === null) {
            throw SelectionRefusee::campagneIntrouvable();
        }
        $saisie = json_decode((string) $ligne->saisie, true);

        return is_array($saisie) ? $saisie : [];
    }
}
