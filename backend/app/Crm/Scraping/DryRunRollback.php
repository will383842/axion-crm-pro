<?php

namespace App\Crm\Scraping;

use RuntimeException;

/**
 * Exception-sentinelle du mode `--dry-run` : levée à la FIN du funnel, dans la
 * transaction, pour que Laravel la fasse rollback — le dry-run a donc parcouru
 * exactement le vrai chemin d'écriture, et il n'en reste rien en base.
 * Elle transporte l'outcome, attrapé une couche au-dessus.
 */
final class DryRunRollback extends RuntimeException
{
    public function __construct(public readonly ScrapeIngestOutcome $outcome)
    {
        parent::__construct('dry-run rollback');
    }
}
