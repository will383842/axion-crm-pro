<?php

namespace App\Envoi\Verdicts;

use App\Support\ListeSuppression;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Appelée par `MaterialisationCampagne` AVANT l'écriture des inscriptions : vérifie, écrit, puis
 * rend le DERNIER verdict de chaque contact. La matérialisation écarte `invalide` et `risque`.
 */
final class PreparationVerdicts
{
    public function __construct(private readonly VerificateurAdresse $verificateur, private readonly EcrivainVerdicts $ecrivain) {}

    /**
     * @param  list<int>  $contactIds
     * @return array<int, VerdictAdresse|null> contact => dernier verdict utilisable ; absent = contact hors espace, supprimé ou sans adresse
     */
    public function pourContacts(string $ws, array $contactIds, DateTimeImmutable $maintenant): array
    {
        if (! EcrivainVerdicts::schemaPret()) {
            throw new RuntimeException('Schéma des verdicts incomplet (colonne motif absente) : rien n’est inscrit.');
        }
        $adresses = []; // contact => adresse
        foreach (array_chunk($contactIds, 1000) as $paquet) {
            $lignes = DB::table('contacts')->where('workspace_id', $ws)->whereNull('deleted_at')->whereNotNull('email')
                ->whereIn('id', $paquet)->orderBy('id')->get(['id', 'email']);
            foreach ($lignes as $l) {
                if (trim((string) $l->email) !== '') {
                    $adresses[(int) $l->id] = (string) $l->email;
                }
            }
        }
        if ($adresses === []) {
            return [];
        }

        $this->ecrivain->ecrire($ws, $this->verificateur->verifier($ws, array_values($adresses), $maintenant), $this->verificateur->source(), $maintenant);

        $derniers = [];
        $empreintes = array_values(array_unique(array_map(ListeSuppression::empreinte(...), $adresses)));
        foreach (array_chunk($empreintes, 500) as $paquet) {
            $lignes = DB::select(
                'SELECT DISTINCT ON (empreinte_adresse) empreinte_adresse, verdict, motif, verifie_le
                 FROM envoi_verdicts_adresse WHERE workspace_id = ? AND empreinte_adresse IN (' . implode(', ', array_fill(0, count($paquet), '?')) . ')
                 ORDER BY empreinte_adresse, verifie_le DESC, id DESC',
                array_merge([$ws], $paquet),
            );
            foreach ($lignes as $l) {
                $derniers[trim((string) $l->empreinte_adresse)] = $this->valeur($l);
            }
        }

        $res = [];
        foreach ($adresses as $contact => $adresse) {
            $res[$contact] = $derniers[ListeSuppression::empreinte($adresse)] ?? null;
        }

        return $res;
    }

    /** Vrai si ce verdict permet d'inscrire maintenant : VALIDE, daté du passé, âgé de moins de 20 jours. */
    public static function permetInscription(?VerdictAdresse $v, DateTimeImmutable $maintenant): bool
    {
        if ($v === null || $v->verdict !== VerdictAdresse::VALIDE) {
            return false;
        }
        $age = $maintenant->getTimestamp() - $v->verifieLe->getTimestamp();

        return $age >= 0 && $age < EcrivainVerdicts::RAFRAICHIR_APRES_JOURS * 86400;
    }

    private function valeur(\stdClass $l): ?VerdictAdresse
    {
        // Ligne antérieure (sans motif) ou « inconnu » : pas de valeur utilisable, la porte reste seule juge.
        if (! is_string($l->motif) || ! in_array($l->verdict, [VerdictAdresse::VALIDE, VerdictAdresse::INVALIDE, VerdictAdresse::RISQUE], true)) {
            return null;
        }

        return new VerdictAdresse($l->verdict, $l->motif, new DateTimeImmutable((string) $l->verifie_le));
    }
}
