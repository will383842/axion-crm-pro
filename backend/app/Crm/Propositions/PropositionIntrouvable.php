<?php

namespace App\Crm\Propositions;

use RuntimeException;

/** Aucune proposition de ce numéro dans l'espace (ou dans un autre) — 404 côté API. */
final class PropositionIntrouvable extends RuntimeException {}
