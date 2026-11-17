<?php

namespace App\Services\Auth\CodeCourriel;

use RuntimeException;

/** La double authentification par courriel est éteinte : aucun code n'est émis. */
final class CanalFermeException extends RuntimeException {}
