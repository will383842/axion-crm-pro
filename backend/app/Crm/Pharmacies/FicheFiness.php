<?php

namespace App\Crm\Pharmacies;

use stdClass;

/**
 * Colonnes de `companies` lues par TelephonesFiness (valeurs brutes, texte vide si NULL).
 * `lat` / `lon` restent NULL tant que la fiche n'a pas de position.
 */
final readonly class FicheFiness
{
    public function __construct(
        public int $id,
        public string $phone,
        public string $legalForm,
        public string $metadata,
        public string $address,
        public string $postcode,
        public string $city,
        public string $cityName,
        public ?float $lat,
        public ?float $lon,
    ) {}

    /** Ligne `companies` (colonnes : id, phone, legal_form, metadata, address, postcode, city, city_name, lat, lon). */
    public static function depuisLigne(stdClass $c): self
    {
        return new self(
            (int) $c->id,
            (string) $c->phone,
            (string) $c->legal_form,
            (string) $c->metadata,
            (string) $c->address,
            (string) $c->postcode,
            (string) $c->city,
            (string) $c->city_name,
            $c->lat === null ? null : (float) $c->lat,
            $c->lon === null ? null : (float) $c->lon,
        );
    }
}
