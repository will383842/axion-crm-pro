<?php

namespace App\Envoi\Imap;

/**
 * Classe un message reçu. Fonction pure : aucune base, aucun réseau.
 *
 * Ordre (le premier qui s'applique gagne) :
 *  1. rapport de remise (DSN) : 5.x.x = rebond dur, 4.x.x = rebond mou ;
 *  2. absence / auto-réponse (en-têtes normalisés RFC 3834, ou objet type) ;
 *  3. hostile (plainte, menace, « spam »…) — traité comme une plainte ;
 *  4. STOP / désinscription (mots-clés fr/en, texte NON cité seulement) ;
 *  5. sinon : réponse humaine.
 *
 * Les erreurs vont toujours dans le sens PRUDENT : un faux « STOP » arrête un
 * envoi de trop, un vrai « STOP » manqué enverrait un message de trop.
 */
final class Classificateur
{
    private const MOTS_HOSTILES = [
        'spam', 'pourriel', 'plainte', 'porter plainte', 'cnil', 'avocat', 'harcelement', 'harceler',
        'poursuite', 'poursuivre', 'signaler', 'signalement', 'lawyer', 'harass', 'harassment',
        'legal action', 'report you', 'reported you', 'sue you', 'illegal', 'illegal', 'scam', 'arnaque',
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

    public static function classer(MessageBrut $m): ClasseRetour
    {
        if ($m->statutDsn !== null) {
            if (preg_match('/^5\.\d{1,3}\.\d{1,3}$/', $m->statutDsn) === 1) {
                return ClasseRetour::RebondDur;
            }
            if (preg_match('/^4\.\d{1,3}\.\d{1,3}$/', $m->statutDsn) === 1) {
                return ClasseRetour::RebondMou;
            }

            return ClasseRetour::AutoReponse; // 2.x.x : accusé de remise, aucun effet
        }

        if (self::estAutoReponse($m)) {
            return ClasseRetour::AutoReponse;
        }

        $texte = self::normaliser(self::texteNonCite($m->corps) . ' ' . $m->sujet);
        if (self::contient($texte, self::MOTS_HOSTILES)) {
            return ClasseRetour::Hostile;
        }
        if (self::contient($texte, self::MOTS_STOP)) {
            return ClasseRetour::Desinscription;
        }

        return ClasseRetour::ReponseHumaine;
    }

    private static function estAutoReponse(MessageBrut $m): bool
    {
        $auto = strtolower(trim($m->enTetes['auto-submitted'] ?? ''));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if (isset($m->enTetes['x-autoreply']) || isset($m->enTetes['x-autorespond'])) {
            return true;
        }
        if (in_array(strtolower(trim($m->enTetes['precedence'] ?? '')), ['auto_reply', 'bulk', 'junk'], true)) {
            return true;
        }
        $objet = self::normaliser($m->sujet);

        return self::contient($objet, self::OBJETS_AUTO);
    }

    /** Retire les lignes citées (« > … ») : on classe ce que la personne a ÉCRIT. */
    private static function texteNonCite(string $corps): string
    {
        $gardees = [];
        foreach (preg_split('/\R/u', mb_substr($corps, 0, 4000)) ?: [] as $ligne) {
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

        return (string) preg_replace('/\s+/u', ' ', $t);
    }

    /** @param list<string> $mots */
    private static function contient(string $texte, array $mots): bool
    {
        foreach ($mots as $mot) {
            // Début de mot seulement : « stop » n'attrape ni « desktop » ni « laptop ».
            if (preg_match('/(?<![a-z0-9])' . preg_quote($mot, '/') . '/u', $texte) === 1) {
                return true;
            }
        }

        return false;
    }
}
