<?php

namespace App\Envoi\Contrat;

/**
 * Marqueur : ce serveur dédoublonne lui-même par clé d'idempotence (registre d'injections de Kumo ; simulateur).
 * Seul un serveur marqué peut recevoir un RENVOI de la même clé après une issue incertaine. Un SMTP direct n'a
 * pas cette garantie : on préfère perdre un message plutôt que l'envoyer deux fois.
 */
interface RenvoiParMemeCle {}
