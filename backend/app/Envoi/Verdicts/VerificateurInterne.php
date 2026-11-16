<?php

namespace App\Envoi\Verdicts;

use App\Crm\Emails\CacheDomaines;
use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResultatDns;
use App\Crm\Emails\QualificationEmail;
use App\Support\ListeSuppression;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vérification SANS COÛT et SANS SONDER PERSONNE : règles pures, faits déjà connus de la base, puis le DNS (domaine
 * seulement, jamais de connexion SMTP). Première règle qui répond, dans l'ordre du cahier §1.1. Les plaintes, STOP et
 * oppositions n'en font pas partie : la porte les refuse déjà avec le bon motif.
 */
final class VerificateurInterne implements VerificateurAdresse
{
    public const SOURCE = 'interne-v1';

    public const ROLES_TECHNIQUES = ['postmaster', 'abuse', 'noreply', 'no-reply', 'mailer-daemon', 'hostmaster', 'webmaster', 'root', 'spam'];

    public const CACHE_DNS_JOURS = 7;

    /** @param  Closure(): ResolveurDns  $resolveur  appelée au premier besoin seulement */
    public function __construct(private readonly QuotaDns $quota, private readonly Closure $resolveur) {}

    public function source(): string
    {
        return self::SOURCE;
    }

    public function verifier(string $ws, array $adresses, DateTimeImmutable $maintenant): array
    {
        $sortie = [];
        $restantes = []; // empreinte => adresse normalisée, sans verdict des règles 1 à 5
        foreach ($adresses as $adresse) {
            $emp = ListeSuppression::empreinte($adresse);
            if (array_key_exists($emp, $sortie) || isset($restantes[$emp])) {
                continue;
            }
            $sortie[$emp] = null;
            $n = QualificationEmail::normaliser($adresse);
            if (! QualificationEmail::syntaxeValide($n)) {
                $sortie[$emp] = new VerdictAdresse(VerdictAdresse::INVALIDE, 'syntaxe', $maintenant);
            } elseif (QualificationEmail::estJetable((string) QualificationEmail::domaine($n))) {
                $sortie[$emp] = new VerdictAdresse(VerdictAdresse::INVALIDE, 'jetable', $maintenant);
            } else {
                $restantes[$emp] = $n;
            }
        }

        // 3. Rebond dur connu : rejoué à chaque fois, un `valide` ne blanchit jamais un `invalide`.
        foreach ($this->rebondsDurs($ws, $restantes) as $emp) {
            $sortie[$emp] = new VerdictAdresse(VerdictAdresse::INVALIDE, 'rebond_dur', $maintenant);
            unset($restantes[$emp]);
        }
        foreach ($restantes as $emp => $n) {
            $local = (string) strstr($n, '@', true);
            $local = explode('+', $local, 2)[0];
            if (in_array($local, self::ROLES_TECHNIQUES, true)) {
                $sortie[$emp] = new VerdictAdresse(VerdictAdresse::INVALIDE, 'role_technique', $maintenant);
                unset($restantes[$emp]);
            } elseif (QualificationEmail::type($n) === 'generique') {
                // ESCALADE 1 : choix par défaut du cahier, jamais inscrite.
                $sortie[$emp] = new VerdictAdresse(VerdictAdresse::RISQUE, 'generique', $maintenant);
                unset($restantes[$emp]);
            }
        }

        return $this->dns($restantes, $sortie, $maintenant);
    }

    /**
     * @param  array<string, string>  $restantes
     * @return list<string> empreintes
     */
    private function rebondsDurs(string $ws, array $restantes): array
    {
        if ($restantes === []) {
            return [];
        }
        $empreintes = array_keys($restantes);
        $adresses = array_values($restantes);
        $trouvees = [];

        // email_suppressions est globale par conception (univers « business » seulement).
        $ligne = DB::table('email_suppressions')->where('scope', 'business')->where('reason', ListeSuppression::REBOND_DUR)
            ->where(fn ($q) => $q->whereIn('email_hash', $empreintes)->orWhereIn('email', $adresses))->get(['email_hash', 'email']);
        foreach ($ligne as $l) {
            $trouvees[] = is_string($l->email_hash) && $l->email_hash !== '' ? $l->email_hash : ListeSuppression::empreinte((string) $l->email);
        }
        foreach (DB::table('envoi_inscriptions_evenements')->where('workspace_id', $ws)->where('evenement', 'rebond_dur')
            ->whereIn('empreinte_adresse', $empreintes)->pluck('empreinte_adresse') as $emp) {
            $trouvees[] = trim((string) $emp);
        }
        foreach (DB::table('contacts')->where('workspace_id', $ws)->whereNull('deleted_at')->where('email_status', 'invalid')
            ->whereIn('email', $adresses)->pluck('email') as $email) {
            $trouvees[] = ListeSuppression::empreinte((string) $email);
        }

        return array_values(array_intersect($empreintes, $trouvees));
    }

    /**
     * @param  array<string, string>  $restantes
     * @param  array<string, VerdictAdresse|null>  $sortie
     * @return array<string, VerdictAdresse|null>
     */
    private function dns(array $restantes, array $sortie, DateTimeImmutable $maintenant): array
    {
        $domaines = [];
        foreach ($restantes as $emp => $n) {
            $domaines[$emp] = (string) QualificationEmail::domaine($n);
        }
        $uniques = array_values(array_unique($domaines));
        $connus = CacheDomaines::fraiches($uniques, self::CACHE_DNS_JOURS);
        $aResoudre = array_values(array_filter($uniques, static fn (string $d): bool => ! isset($connus[$d])));
        $resolus = $this->resoudre($this->quota->accorder($aResoudre, $maintenant), $maintenant, $connus);

        foreach ($domaines as $emp => $domaine) {
            $r = $connus[$domaine] ?? $resolus[$domaine] ?? null;
            $recoit = $r === null ? null : $r['resultat']->recoit();
            if ($r === null || $recoit === null) {
                continue; // 7. rien n'est écrit
            }
            $sortie[$emp] = new VerdictAdresse(
                $recoit ? VerdictAdresse::VALIDE : VerdictAdresse::INVALIDE,
                $r['resultat']->verdict,
                // La date de la RÉSOLUTION, jamais « maintenant » pour un résultat repris du cache.
                DateTimeImmutable::createFromInterface($r['resolu_le']),
            );
        }

        return $sortie;
    }

    /**
     * @param  list<string>  $domaines
     * @param  array<string, array{resultat: ResultatDns, resolu_le: CarbonImmutable}>  $connus
     * @return array<string, array{resultat: ResultatDns, resolu_le: CarbonImmutable}>
     */
    private function resoudre(array $domaines, DateTimeImmutable $maintenant, array $connus): array
    {
        if ($domaines === []) {
            return [];
        }
        $resolveur = ($this->resolveur)();
        $reponses = [];
        try {
            $reponses = $resolveur->resoudre($domaines);
        } catch (RuntimeException) {
            // Une panne n'est jamais un « invalide » : domaine par domaine, ceux qui lèvent restent sans réponse.
            foreach ($domaines as $d) {
                try {
                    $reponses += $resolveur->resoudre([$d]);
                } catch (RuntimeException) {
                }
            }
        }
        $reponses = array_filter($reponses, static fn (ResultatDns $r): bool => $r->recoit() !== null);

        // Garde du résolveur : un négatif n'est retenu que si le domaine témoin reçoit bien du courrier.
        $negatifs = array_filter($reponses, static fn (ResultatDns $r): bool => $r->recoit() === false);
        if ($negatifs !== [] && ! $this->temoinRecoit($resolveur)) {
            $reponses = array_diff_key($reponses, $negatifs);
        }
        $le = CarbonImmutable::instance($maintenant);
        CacheDomaines::enregistrer($reponses, $resolveur->nom(), $le);

        return array_map(static fn (ResultatDns $r): array => ['resultat' => $r, 'resolu_le' => $le], $reponses);
    }

    private function temoinRecoit(ResolveurDns $resolveur): bool
    {
        $temoin = strtolower(trim((string) config('crm.emails_verification.domaine_temoin', 'gmail.com')));
        try {
            return ($resolveur->resoudre([$temoin])[$temoin] ?? null)?->verdict === ResultatDns::MX;
        } catch (RuntimeException) {
            return false;
        }
    }
}
