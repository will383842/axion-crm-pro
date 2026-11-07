<?php

namespace App\Envoi\Imap;

/**
 * Texte lisible d'un message pour la classification. Fonction pure.
 * Borné à 4 000 caractères : on ne garde jamais plus que ce qu'il faut pour
 * reconnaître un STOP, et rien de cela n'est persisté.
 */
final class ExtraitTexte
{
    public const LIMITE = 4000;

    /** Le texte brut s'il existe, à défaut le HTML débarrassé de ses balises. */
    public static function choisir(string $texteBrut, string $html): string
    {
        $brut = trim($texteBrut);
        if ($brut !== '') {
            return mb_substr($brut, 0, self::LIMITE);
        }

        return self::depuisHtml($html);
    }

    public static function depuisHtml(string $html): string
    {
        // On borne AVANT d'analyser (un HTML énorme ne doit pas coûter cher), mais
        // assez large pour que les balises <style>/<head> n'occupent pas tout le budget.
        $html = substr($html, 0, 200_000);
        $html = (string) preg_replace('#<(script|style|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])\s*>#i', "\n", $html);
        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texte = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $texte);
        $texte = (string) preg_replace('/\s*\n\s*/u', "\n", $texte);

        return mb_substr(trim($texte), 0, self::LIMITE);
    }
}
