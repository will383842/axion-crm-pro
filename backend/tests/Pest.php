<?php

use Tests\TestCase;

// Les 25 fichiers de `tests/Feature` déclarent eux-mêmes
// `uses(TestCase::class, RefreshDatabase::class)`. Étendre AUSSI le dossier ici
// faisait échouer Pest au chargement de la suite — « Test case Tests\TestCase
// can not be used. The folder … already uses the test case » — c'est-à-dire que
// la suite backend NE POUVAIT PAS DÉMARRER (échec masqué en CI par un
// `composer test || true`, cf. PR « fix/ci-reelle » du 2026-08-13).
// On n'étend donc que `Unit`, dont la majorité des fichiers ne déclare rien.
pest()->extend(TestCase::class)->in('Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): bool
{
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// FILET DE NON-RÉGRESSION (lot R0) — groupe Pest « non-regression ».
// Les gardes NEUVES vivent dans `Feature/NonRegression/` ; les gardes EXISTANTES
// que le filet « cite et verrouille » (plan 22 § 3) sont rattachées au groupe
// ici, sans toucher à leur fichier. Aucune n’est réécrite. La liste figée
// `Feature/NonRegression/LISTE.json` dit laquelle protège quel risque.
// ─────────────────────────────────────────────────────────────────────────────
pest()->group('non-regression')->in('Feature/NonRegression');

pest()->group('non-regression')->in(
    'Feature/Audiences/RafraichissementNocturneSousRlsTest.php',
    'Feature/Auth/ChangementMotDePasseTest.php',
    'Feature/Auth/CourrielAuthentificationTest.php',
    'Feature/Auth/GardesAuthentificationAgent35Test.php',
    'Feature/Auth/LoginTest.php',
    'Feature/CampaignsTest.php',
    'Feature/Commands/AutomatismesDePurgeTest.php',
    'Feature/Console/CommentairesDuPlanificateurTest.php',
    'Feature/Console/VerrousDuPlanificateurTest.php',
    'Feature/Crm/CampagneAdressesPartageesTest.php',
    'Feature/Crm/CampagnesB2bTest.php',
    'Feature/Crm/CrmOutboundTest.php',
    'Feature/Crm/EntreprisesFermeesExcluesDesEnvoisTest.php',
    'Feature/Crm/InseeNonDiffusiblesHorsProspectionTest.php',
    'Feature/Crm/ResolveurDestinatairesTest.php',
    'Feature/Crm/SiteGdprTest.php',
    'Feature/Crm/SiteSyncIngestTest.php',
    'Feature/Database/AuditLogsPartitionnementTest.php',
    'Feature/Database/MaintenancePartitionsAuditTest.php',
    'Feature/Database/RafraichissementMatriceCouvertureTest.php',
    'Feature/Email/ZeptoMailWebhookTest.php',
    'Feature/EligibiliteCampagneTest.php',
    'Feature/Envoi/AdaptateurSmtpTest.php',
    'Feature/HorodatagesFuseauTest.php',
    'Feature/Infra/AucunEnvoiSansEligibiliteTest.php',
    'Feature/Infra/AucunSimulacreEnProductionTest.php',
    'Feature/Infra/DrapeauxMockAlignesTest.php',
    'Feature/Infra/ImagesConstruitesSontDeployeesTest.php',
    'Feature/Infra/PurgesDestructricesTest.php',
    'Feature/Infra/SimulacresLusHorsDeLaGardeTest.php',
    'Feature/Infra/SurveillanceCanauxWorkflowTest.php',
    'Feature/Infra/WorkflowsBornesDeDureeTest.php',
    'Feature/Internal/CanalPartnersSocleTest.php',
    'Feature/ListeSuppressionTest.php',
    'Feature/PasDeStub501SousCrmEtAnalyticsTest.php',
    'Feature/Rgpd/ExportsRespectentOppositionsTest.php',
    'Feature/Rgpd/GdprErasureTest.php',
    'Feature/Rgpd/OppositionCouvreTousLesUniversTest.php',
    'Feature/Rgpd/OppositionInseeNonDiffusibleTest.php',
    'Feature/Rgpd/PortabiliteCompleteTest.php',
    'Feature/Rgpd/PurgeNonDiffusibleVariantesTest.php',
    'Feature/Rgpd/SondeNonDiffusiblesTest.php',
);
