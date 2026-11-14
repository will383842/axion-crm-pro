<?php

namespace Tests\Support;

use App\Envoi\Contrat\RenvoiParMemeCle;

/** Variante qui dédoublonne par clé (comme Kumo) : un renvoi de la même clé est permis. */
class ServeurEspionDedoublonneur extends ServeurEspion implements RenvoiParMemeCle {}
