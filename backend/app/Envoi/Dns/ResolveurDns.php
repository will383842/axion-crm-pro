<?php

namespace App\Envoi\Dns;

/** Résolveur DNS injecté : réel en production, faux dans les tests (aucun réseau). Lecture seule. */
interface ResolveurDns
{
    /** @return list<string> enregistrements TXT (fragments déjà joints) */
    public function txt(string $nom): array;

    /** @return list<string> adresses IPv4 */
    public function a(string $nom): array;

    /** @return list<string> noms PTR, minuscules, sans point final */
    public function ptr(string $ip): array;

    /** Texte de la politique MTA-STS publiée, ou null si absente. */
    public function politiqueMtaSts(string $domaine): ?string;
}
