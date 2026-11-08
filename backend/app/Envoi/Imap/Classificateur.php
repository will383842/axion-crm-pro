<?php

namespace App\Envoi\Imap;

/**
 * Classe un message reçu. Fonction pure : aucune base, aucun réseau.
 *
 * Ordre (le premier qui s'applique gagne) :
 *  0. rapport d'abus ARF (JMRP, Yahoo CFL) : plainte, sauf « not-spam » / « auth-failure » (sans effet) ;
 *  1. rapport de remise (DSN) : 5.7.x = politique/réputation (PAS une adresse morte : aucun effet),
 *     autres 5.x.x = rebond dur, 4.x.x = rebond mou ;
 *  2. analyse échouée (UTF-8 invalide, limite PCRE) : STOP par prudence, jamais « réponse humaine » ;
 *  3. auto-réponse déclarée par en-tête normalisé (RFC 3834 : Auto-Submitted, X-Autoreply, Precedence: auto_reply) ;
 *  3b. message reçu sur abuse@ : plainte ;
 *  4. STOP / désinscription (mots-clés fr/en, texte NON cité seulement) : STOP PRIME ;
 *  5. hostile (menace, avocat, « porter plainte »…) — traité comme une plainte ;
 *  6. auto-réponse devinée (objet « absent », « congé » ; `Precedence: bulk/junk`) : heuristique FAIBLE,
 *     donc après 4 et 5 — jamais avant la recherche d'un STOP ;
 *  7. sinon : réponse humaine.
 *
 * Les erreurs vont toujours dans le sens PRUDENT : un faux « STOP » arrête un
 * envoi de trop, un vrai « STOP » manqué enverrait un message de trop.
 */
final class Classificateur
{
    private const MOTS_HOSTILES = [
        // Pas de « spam », « plainte », « signaler », « illegal » seuls : faux positifs (« aucune plainte »,
        // « dans mes spams »). Seules les formules d'action ou de menace comptent ; un STOP prime de toute façon.
        'porter plainte', 'deposer plainte', 'depose plainte', 'cnil', 'avocat', 'harcelement', 'harceler',
        'poursuite', 'poursuivre', 'signalement', 'lawyer', 'harass', 'harassment',
        'legal action', 'report you', 'reported you', 'sue you', 'scam', 'arnaque',
    ];

    private const MOTS_STOP = [
        'stop', 'desinscri', 'desabonn', 'unsubscribe', 'opt out', 'opt-out', 'optout',
        'ne plus recevoir', 'plus recevoir', 'ne souhaite plus', 'ne veux plus', 'no longer wish', 'no longer want', 'stop emailing', 'stop sending', 'ne plus me contacter', 'ne me contactez plus', 'ne m ecrivez plus',
        'retirez moi', 'retirez mon', 'supprimez mon', 'supprimer mon adresse', 'remove me',
        'remove my', 'take me off', 'do not contact', 'don t contact', 'do not email',
        'arretez', 'cessez de', 'plus jamais',
    ];

    private const OBJETS_AUTO = [
        'absence', 'absent', 'out of office', 'automatic reply', 'auto reply', 'autoreply',
        'auto-reply', 'reponse automatique', 'reponse automatique', 'conge', 'vacation',
    ];

    /**
     * @param  list<string>  $nosDomaines  domaines d'envoi de l'espace : seuls leurs `abuse@` valent plainte
     *                                     (liste vide = aucun `abuse@` reconnu : jamais de plainte devinée)
     */
    public static function classer(MessageBrut $m, array $nosDomaines = []): ClasseRetour
    {
        try {
            return self::classerOuEchouer($m, $nosDomaines);
        } catch (EchecAnalyse) {
            return ClasseRetour::Desinscription; // prudence : jamais « réponse humaine » sur un échec d'analyse
        }
    }

    /** @param list<string> $nosDomaines */
    private static function classerOuEchouer(MessageBrut $m, array $nosDomaines): ClasseRetour
    {
        if ($m->statutDsn !== null) {
            if (Pcre::correspond('/^5\.7\.\d{1,3}$/', $m->statutDsn)) {
                return ClasseRetour::AutoReponse; // politique / réputation / « rejected as spam » : pas une adresse morte
            }
            if (Pcre::correspond('/^5\.\d{1,3}\.\d{1,3}$/', $m->statutDsn)) {
                return ClasseRetour::RebondDur;
            }
            if (Pcre::correspond('/^4\.\d{1,3}\.\d{1,3}$/', $m->statutDsn)) {
                return ClasseRetour::RebondMou;
            }

            return ClasseRetour::AutoReponse; // 2.x.x : accusé de remise, aucun effet
        }

        if ($m->typeArf === 'illisible') {
            return ClasseRetour::AutoReponse; // Feedback-Type illisible : ni plainte ni rattachement (revue humaine)
        }

        if ($m->typeArf !== null) {
            // Rapport d'abus ARF (JMRP, Yahoo CFL) : un « Auto-Submitted » est ici normal, il ne l'écarte pas.
            return in_array($m->typeArf, ['not-spam', 'auth-failure'], true) ? ClasseRetour::AutoReponse : ClasseRetour::Hostile;
        }

        if ($m->analyseEchouee) {
            return ClasseRetour::Desinscription;
        }

        if (self::estAutoReponseDeclaree($m)) {
            return ClasseRetour::AutoReponse;
        }

        if (self::recuSurAbuse($m, $nosDomaines)) {
            return ClasseRetour::Hostile; // écrit à abuse@ : plainte (une auto-réponse déclarée a été écartée plus haut)
        }

        if (! mb_check_encoding($m->corps . $m->sujet, 'UTF-8')) {
            throw new EchecAnalyse('Texte non UTF-8.');
        }
        $texte = self::normaliser(self::texteNonCite($m->corps) . ' ' . $m->sujet);
        if (self::contient($texte, self::MOTS_STOP)) {
            return ClasseRetour::Desinscription;
        }
        if (self::contient($texte, self::MOTS_HOSTILES)) {
            return ClasseRetour::Hostile;
        }
        if (self::contient(self::normaliser($m->sujet), self::OBJETS_AUTO)
            || in_array(strtolower(trim($m->enTetes['precedence'] ?? '')), ['bulk', 'junk'], true)) {
            return ClasseRetour::AutoReponse;
        }

        return ClasseRetour::ReponseHumaine;
    }

    /** @param list<string> $nosDomaines */
    private static function recuSurAbuse(MessageBrut $m, array $nosDomaines): bool
    {
        foreach ($m->adressesLocales as $a) {
            $g = Pcre::groupes('/^abuse@([a-z0-9.-]+)$/i', trim($a, ' <>'));
            if ($g === null) {
                continue;
            }
            $hote = strtolower($g[0]);
            foreach ($nosDomaines as $d) {
                $d = strtolower(trim($d));
                if ($d !== '' && ($hote === $d || str_ends_with($hote, '.' . $d))) {
                    return true; // notre domaine (ou un sous-domaine, ex. bounce.<domaine>)
                }
            }
        }

        return false;
    }

    private static function estAutoReponseDeclaree(MessageBrut $m): bool
    {
        $auto = strtolower(trim($m->enTetes['auto-submitted'] ?? ''));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if (isset($m->enTetes['x-autoreply']) || isset($m->enTetes['x-autorespond'])) {
            return true;
        }
        if (in_array(strtolower(trim($m->enTetes['precedence'] ?? '')), ['auto_reply'], true)) {
            return true;
        }

        return false;
    }

    /** Retire les lignes citées (« > … ») : on classe ce que la personne a ÉCRIT. */
    private static function texteNonCite(string $corps): string
    {
        $gardees = [];
        foreach (Pcre::decouper('/\R/u', mb_substr($corps, 0, 4000)) as $ligne) {
            if (str_starts_with(ltrim($ligne), '>')) {
                continue;
            }
            $gardees[] = $ligne;
        }

        return implode("\n", $gardees);
    }

    private static function normaliser(string $t): string
    {
        $t = mb_strtolower($t);
        $t = strtr($t, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', '’' => ' ', "'" => ' ',
        ]);

        return Pcre::remplacer('/\s+/u', ' ', $t);
    }

    /** @param list<string> $mots */
    private static function contient(string $texte, array $mots): bool
    {
        foreach ($mots as $mot) {
            // Début de mot seulement : « stop » n'attrape ni « desktop » ni « laptop ».
            if (Pcre::correspond('/(?<![a-z0-9])' . preg_quote($mot, '/') . '/u', $texte)) {
                return true;
            }
        }

        return false;
    }
}
