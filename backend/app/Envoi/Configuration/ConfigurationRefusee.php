<?php

namespace App\Envoi\Configuration;

use RuntimeException;

/** Le serveur d'envoi a refusé la configuration et garde sa version précédente. */
final class ConfigurationRefusee extends RuntimeException {}
