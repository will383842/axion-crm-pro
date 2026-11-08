<?php

namespace App\Envoi\Dns;

use DateTimeImmutable;
use SimpleXMLElement;

/** Lit un rapport DMARC agrégé (XML). Refuse toute DOCTYPE/entité : rien n'est jamais résolu. */
final class LecteurRapportDmarc
{
    public static function lire(string $xml): RapportDmarcAgrege
    {
        if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RapportDmarcIllisible('rapport vide ou DOCTYPE refusée');
        }
        $avant = libxml_use_internal_errors(true);
        try {
            $f = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($avant);
        }
        if ($f === false || $f->getName() !== 'feedback' || ! isset($f->report_metadata, $f->policy_published)) {
            throw new RapportDmarcIllisible('XML DMARC invalide');
        }

        $lignes = [];
        foreach ($f->record as $rec) {
            $nombre = trim((string) $rec->row->count);
            if (preg_match('/^\d{1,9}$/', $nombre) !== 1) {
                throw new RapportDmarcIllisible('compte invalide');
            }
            $ev = $rec->row->policy_evaluated;
            $lignes[] = [
                'ip' => trim((string) $rec->row->source_ip),
                'nombre' => (int) $nombre,
                'disposition' => strtolower(trim((string) $ev->disposition)),
                'dkim' => strtolower(trim((string) $ev->dkim)),
                'spf' => strtolower(trim((string) $ev->spf)),
            ];
        }

        $m = $f->report_metadata;

        return new RapportDmarcAgrege(
            trim((string) $m->org_name),
            trim((string) $m->report_id),
            strtolower(trim((string) $f->policy_published->domain)),
            (new DateTimeImmutable)->setTimestamp((int) $m->date_range->begin),
            (new DateTimeImmutable)->setTimestamp((int) $m->date_range->end),
            $lignes,
        );
    }
}
