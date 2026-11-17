<?php

namespace App\Services\Auth\CodeCourriel;

use RuntimeException;

/** Trop de codes demandés en 10 minutes pour ce compte (429 côté route). */
final class TropDeRenvois extends RuntimeException {}
