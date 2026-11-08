<?php

namespace App\Envoi\Imap;

use RuntimeException;

/**
 * L'analyse d'un texte a échoué (UTF-8 invalide, limite PCRE atteinte). Ce n'est JAMAIS
 * « pas de correspondance » : l'appelant traite le message par PRUDENCE (comme un STOP).
 */
final class EchecAnalyse extends RuntimeException {}
