<?php

namespace App\Crm\Propositions;

use RuntimeException;

/**
 * Une décision qui ne peut pas être prise en l'état (la fiche visée n'existe
 * plus, ou la proposition est déjà décidée) — 409 côté API.
 */
class PropositionImpossible extends RuntimeException {}
