<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Refus DÉFINITIF et certain du serveur d'envoi (5xx sur le message) : rien n'est parti, la place est rendue. */
final class RefusDefinitifServeur extends RuntimeException {}
