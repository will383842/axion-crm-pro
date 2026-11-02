/**
 * Types du contrat d'API d'Envoi, zone « pilotage ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/pilotage.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/envois/chercher": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Chercher un envoi par adresse (tous les événements) */
        get: operations["chercherEnvoi"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/feu-vert": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Liste « feu vert » avant le premier envoi réel (15 points) */
        get: operations["lireFeuVert"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/resultats": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Résultats regroupés */
        get: operations["lireResultats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/resultats/courbe": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Courbe jour par jour (30 jours) */
        get: operations["lireCourbeResultats"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/sante": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Santé des envois (vue d'ensemble) */
        get: operations["lireSante"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
}
export type webhooks = Record<string, never>;
export interface components {
    schemas: {
        AdresseIp: {
            /** @description Adresse IP. En démo, plages de documentation seulement (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24). */
            adresse: string;
            domaine_id: components["schemas"]["Identifiant"];
            envoyes_aujourdhui: number;
            flux: components["schemas"]["FluxEnvoi"];
            statut: components["schemas"]["StatutBoite"];
        };
        BoiteResume: {
            /** Format: email */
            adresse: string;
            chauffe: components["schemas"]["Chauffe"] | null;
            domaine_id: components["schemas"]["Identifiant"];
            envoyes_aujourdhui: number;
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            nom_affiche: string;
            quota_jour: number;
            reponses_7j: number;
            statut: components["schemas"]["StatutBoite"];
            /** @description Refus (durs et mous) sur 7 jours, en pourcentage. */
            taux_refus: number;
        };
        Chauffe: {
            jour: number;
            plafond_aujourdhui: number;
            sur: number;
        };
        DomaineResume: {
            ajoute_le: components["schemas"]["Horodatage"];
            boites: number;
            chauffe: components["schemas"]["Chauffe"] | null;
            dmarc: components["schemas"]["PolitiqueDmarc"];
            /** @description DMARC progressif — prochaine politique et date prévue. */
            dmarc_prochaine_etape: {
                le: components["schemas"]["Horodatage"];
                politique: components["schemas"]["PolitiqueDmarc"];
            } | null;
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            listes_noires: number;
            nom: string;
        };
        /**
         * @description Feu de santé (vert bon, ambre attention, rouge arrêté, bleu en montée, gris inconnu).
         * @enum {string}
         */
        EtatFeu: "vert" | "ambre" | "rouge" | "bleu" | "gris";
        EvenementEnvoi: {
            /** Format: email */
            adresse: string;
            /** @description Pour une ouverture seulement ; nul pour les autres types. */
            automatique_probable: boolean | null;
            boite_id: components["schemas"]["Identifiant"];
            campagne_id: components["schemas"]["Identifiant"];
            code_serveur: string | null;
            destinataire_id: components["schemas"]["Identifiant"] | null;
            id: components["schemas"]["Identifiant"];
            /** @description Explication en français (code serveur traduit). */
            libelle: string | null;
            survenu_le: components["schemas"]["Horodatage"];
            type: components["schemas"]["TypeEvenementEnvoi"];
        };
        /** @enum {string} */
        FluxEnvoi: "prospection" | "lettre";
        /**
         * Format: date-time
         * @description Date et heure ISO 8601 (UTC).
         */
        Horodatage: string;
        /**
         * @description Identifiant stable, opaque.
         * @example cmp-01
         */
        Identifiant: string;
        LigneResultat: {
            cle: string;
            desinscriptions: number;
            envoyes: number;
            libelle: string;
            /** @description Estimation ; nul si le groupe ne contient aucune campagne qui suit les ouvertures. */
            ouvertures: components["schemas"]["ResumeOuvertures"] | null;
            plaintes: number;
            rebonds_durs: number;
            rebonds_mous: number;
            remis: number;
            reponses: number;
            reponses_interessees: number;
        };
        /** @enum {string} */
        PolitiqueDmarc: "none" | "quarantine" | "reject";
        /**
         * @description Ouvertures d'une campagne qui les suit. Toujours affichées comme une
         *     « estimation » ; `automatiques_probables` est déjà retiré de `estimees`.
         */
        ResumeOuvertures: {
            automatiques_probables: number;
            estimees: number;
            /** @constant */
            mention: "estimation";
            /** @description Pourcentage des messages remis, ouvertures automatiques retirées. */
            taux_estime: number;
        };
        ServeurResume: {
            adresses_ip: components["schemas"]["AdresseIp"][];
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            nom: string;
            version_configuration: number;
        };
        /** @enum {string} */
        StatutBoite: "prete" | "ralentie" | "en_chauffe" | "suspendue";
        /** @enum {string} */
        TypeEvenementEnvoi: "remis" | "rebond_dur" | "rebond_mou" | "plainte" | "desinscription" | "ouverture" | "reponse";
    };
    responses: never;
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    chercherEnvoi: {
        parameters: {
            query: {
                adresse: string;
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Événements de cette adresse, et peut-on encore lui écrire. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        adresse: string;
                        evenements: components["schemas"]["EvenementEnvoi"][];
                        peut_ecrire: boolean;
                        raison: string | null;
                    };
                };
            };
        };
    };
    lireFeuVert: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Points de contrôle. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        faits: number;
                        points: {
                            code: string;
                            detail: string | null;
                            fait: boolean;
                            libelle: string;
                        }[];
                        total: number;
                    };
                };
            };
        };
    };
    lireResultats: {
        parameters: {
            query: {
                jours?: 7 | 30 | 90;
                par: "campagne" | "boite" | "domaine" | "liste" | "message";
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Une ligne par groupe. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["LigneResultat"][];
                        periode: {
                            /** Format: date */
                            au: string;
                            /** Format: date */
                            du: string;
                        };
                        totaux: components["schemas"]["LigneResultat"];
                    };
                };
            };
        };
    };
    lireCourbeResultats: {
        parameters: {
            query?: {
                campagne_id?: components["schemas"]["Identifiant"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Points par jour. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: {
                            /** Format: date */
                            jour: string;
                            /** @description Nul si aucune campagne de la période ne suit les ouvertures. */
                            ouvertures_estimees: number | null;
                            rebonds: number;
                            remis: number;
                            reponses: number;
                        }[];
                    };
                };
            };
        };
    };
    lireSante: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Feux par boîte, domaine et serveur, et test du matin. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        alertes: number;
                        boites: components["schemas"]["BoiteResume"][];
                        domaines: components["schemas"]["DomaineResume"][];
                        feu: components["schemas"]["EtatFeu"];
                        serveurs: components["schemas"]["ServeurResume"][];
                        test_du_matin: {
                            etat: components["schemas"]["EtatFeu"];
                            le: components["schemas"]["Horodatage"];
                            temoins_ok: number;
                            temoins_total: number;
                        };
                    };
                };
            };
        };
    };
}
