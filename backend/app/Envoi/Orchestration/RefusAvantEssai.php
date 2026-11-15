<?php

namespace App\Envoi\Orchestration;

use RuntimeException;

/**
 * Refus AVANT tout lancement de l'essai à blanc (auteur invalide, espace inconnu). Seule exception dont la commande affiche le
 * message : son texte est rédigé ici et ne porte jamais de valeur saisie. Toute autre exception reste interne.
 */
final class RefusAvantEssai extends RuntimeException {}
