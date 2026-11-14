<?php

namespace App\Envoi\Conformite;

use App\Crm\Outbound\OppositionSortante;
use App\Crm\Personnes\NatureEmail;
use App\Support\ListeSuppression;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registre d'opposition MANUEL (lot R13), par espace. AJOUT SEUL : jamais de retrait ni de suppression de contact.
 *
 * - `adresse` : empreinte + valeur chiffrée ; écrite AUSSI dans `email_suppressions` (même effet que le registre CRM).
 * - `domaine` : en clair, normalisé ; couvre aussi les sous-domaines. Les domaines de messagerie grand public sont REFUSÉS
 *   (bloquerait des milliers d'inconnus sans rapport).
 * - `organisation` : SIREN (9 chiffres, clé de Luhn), en clair.
 * Le contrôle `refuse()` est LU par la porte `EnvoiAutorise` ; il ne fait que lire.
 */
final class RegistreOppositionManuel
{
    public const PORTEES = ['adresse', 'organisation', 'domaine'];

    /**
     * Valeur normalisée de la portée, ou exception au message français destiné à l'écran.
     *
     * @throws InvalidArgumentException
     */
    public static function normaliser(string $portee, string $valeur): string
    {
        $v = mb_strtolower(trim($valeur));

        return match ($portee) {
            'adresse' => filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 254
                ? $v : throw new InvalidArgumentException('Adresse e-mail invalide.'),
            'organisation' => self::siren($v),
            'domaine' => self::domaine($v),
            default => throw new InvalidArgumentException('Portée inconnue.'),
        };
    }

    /** Empreinte d'une valeur normalisée (même calcul que la liste de suppression). */
    public static function empreinte(string $valeurNormalisee): string
    {
        return ListeSuppression::empreinte($valeurNormalisee);
    }

    /**
     * Inscrit (idempotent).
     *
     * @return array{ligne: \stdClass, creee: bool}
     */
    public function inscrire(string $workspaceId, string $portee, string $valeur, string $motif, string $auteur): array
    {
        $norm = self::normaliser($portee, $valeur);
        $empreinte = self::empreinte($norm);
        $inseree = DB::table('envoi_oppositions_registre')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'portee' => $portee,
            'empreinte' => $empreinte,
            'valeur' => $portee === 'adresse' ? null : $norm,
            'valeur_chiffree' => $portee === 'adresse' ? Crypt::encryptString($norm) : null,
            'motif' => trim($motif),
            'source' => 'manuel',
            'auteur' => $auteur,
        ]);
        if ($portee === 'adresse') {
            // Même effet que le registre CRM ; idempotent par construction (l'empreinte est unique).
            ListeSuppression::inscrire($norm, ListeSuppression::MANUEL, 'registre_manuel');
            $this->prevenirLeSite($norm, $empreinte);
        }
        $ligne = DB::table('envoi_oppositions_registre')
            ->where('workspace_id', $workspaceId)->where('portee', $portee)->where('empreinte', $empreinte)->first();
        if ($ligne === null) {
            throw new \RuntimeException('Registre d’opposition : ligne introuvable après inscription.');
        }

        return ['ligne' => $ligne, 'creee' => $inseree === 1];
    }

    /**
     * STOP-T4 : une opposition née au CRM doit prévenir le site, par l'enregistreur unique (`OppositionSortante` →
     * `ConsentOutboundRecorder` → `crm_outbound_events`). ANTI-BOUCLE : si l'adresse est DÉJÀ au registre `opt_out`
     * (venue du site : `site-sync:*`, `gdpr_erasure_bisystem`, `gdpr_erasure_demande_site`…), on n'écrit rien de plus et
     * `OppositionSortante` ne signale que les sources nées au CRM : rien ne repart vers le site. Le domaine et le SIREN
     * n'émettent rien (le site ne connaît que des empreintes d'adresses). Un échec de la file ne défait jamais
     * l'opposition : `crm:outbound-rattraper` reprend.
     */
    private function prevenirLeSite(string $adresseNormalisee, string $empreinte): void
    {
        // `opt_out` n'a pas d'unicité : on ne double jamais une ligne existante (surtout celle née du site, qui porte la source).
        if (! DB::table('opt_out')->where('scope', 'business')->where('email_hash', $empreinte)->exists()) {
            DB::table('opt_out')->insert([
                'email' => null,
                'email_hash' => $empreinte,
                'scope' => 'business',
                'source' => 'registre_manuel',
                'created_at' => now(),
            ]);
        }
        (new OppositionSortante)->signalerSansBloquer($empreinte, 'business', 'registre_manuel', 'registre_manuel');
    }

    /** Vrai si le domaine de l'adresse (ou un domaine parent) ou le SIREN est au registre de l'espace. */
    public static function refuse(string $workspaceId, string $email, ?string $siren): bool
    {
        $cles = [];
        foreach (self::domainesEtParents($email) as $d) {
            $cles[] = ['domaine', self::empreinte($d)];
        }
        $s = $siren === null ? '' : preg_replace('/\s+/', '', $siren);
        if ($s !== null && preg_match('/^\d{9}$/', $s) === 1) {
            $cles[] = ['organisation', self::empreinte($s)];
        }
        if ($cles === []) {
            return false;
        }

        return DB::table('envoi_oppositions_registre')->where('workspace_id', $workspaceId)->where(function ($q) use ($cles): void {
            foreach ($cles as [$portee, $empreinte]) {
                $q->orWhere(fn ($w) => $w->where('portee', $portee)->where('empreinte', $empreinte));
            }
        })->exists();
    }

    /** @return list<string> */
    private static function domainesEtParents(string $email): array
    {
        $pos = strrpos($email, '@');
        $libelles = explode('.', mb_strtolower(trim($pos === false ? '' : substr($email, $pos + 1))));
        $liste = [];
        while (count($libelles) >= 2 && $libelles[0] !== '') {
            $liste[] = implode('.', $libelles);
            array_shift($libelles);
        }

        return $liste;
    }

    private static function domaine(string $v): string
    {
        $v = rtrim($v, '.');
        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $v) !== 1) {
            throw new InvalidArgumentException('Domaine invalide (exemple : societe.example.invalid).');
        }
        foreach (self::domainesEtParents('x@' . $v) as $d) {
            if (NatureEmail::estGrandPublic($d)) {
                throw new InvalidArgumentException('Domaine de messagerie grand public : l’inscrire bloquerait des personnes sans rapport. Inscrivez plutôt les adresses concernées.');
            }
        }

        return $v;
    }

    private static function siren(string $v): string
    {
        $s = (string) preg_replace('/\s+/', '', $v);
        $somme = 0;
        if (preg_match('/^\d{9}$/', $s) === 1 && $s !== '000000000') {
            for ($i = 0; $i < 9; $i++) {
                $c = (int) $s[8 - $i];
                if ($i % 2 === 1 && ($c *= 2) > 9) {
                    $c -= 9;
                }
                $somme += $c;
            }
            if ($somme % 10 === 0) {
                return $s;
            }
        }
        throw new InvalidArgumentException('SIREN invalide (9 chiffres, clé de contrôle comprise).');
    }
}
