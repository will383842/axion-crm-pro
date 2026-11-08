<?php

namespace App\Envoi\Imap;

use RuntimeException;

/**
 * L'arrêt est impossible à appliquer pour CE message (adresse invalide…), sans
 * que le CRM soit en cause. La relève isole le message (« à rattacher », sans
 * effet) et continue. Toute autre exception = vraie indisponibilité : elle
 * remonte et suspend la relève, pour que l'événement soit relu.
 */
final class RetourInapplicable extends RuntimeException {}
