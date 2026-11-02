/**
 * Types du contrat d'API d'Envoi, zone « conformite ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/conformite.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/alertes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Alertes d'envoi */
        get: operations["listerAlertesEnvoi"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/carnet": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Carnet de destinataires (Envoi sans CRM) */
        get: operations["listerCarnet"];
        put?: never;
        /** Ajouter une entrée au carnet */
        post: operations["ajouterAuCarnet"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/desinscriptions": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Désinscriptions */
        get: operations["listerDesinscriptions"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/opposition": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Registre d'opposition (« ne plus écrire ») */
        get: operations["listerOpposition"];
        put?: never;
        /** Inscrire une adresse, une organisation ou un domaine */
        post: operations["inscrireOpposition"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/opposition/export": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Export du registre (CSV) */
        get: operations["exporterOpposition"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/puis-je-ecrire": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** « Puis-je écrire à… ? » */
        get: operations["puisJeEcrire"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Réglages d'envoi (vue d'ensemble) */
        get: operations["lireReglagesEnvoi"];
        /** Enregistrer les réglages d'envoi */
        put: operations["enregistrerReglagesEnvoi"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/alertes": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Règles d'alerte */
        get: operations["listerReglesAlerte"];
        /** Enregistrer les règles d'alerte */
        put: operations["enregistrerReglesAlerte"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/connexion-crm": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Connexion au CRM (adaptateur) */
        get: operations["lireConnexionCrm"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/connexion-crm/tester": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Tester la connexion au CRM */
        post: operations["testerConnexionCrm"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reglages/identite": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Identité de l'expéditeur (mentions, adresse postale, signature) */
        get: operations["lireIdentite"];
        /** Enregistrer l'identité */
        put: operations["enregistrerIdentite"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/public/desinscription/{jeton}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Page publique de désinscription (sans connexion) */
        get: operations["lirePageDesinscription"];
        put?: never;
        /** Confirmer la désinscription (un clic) */
        post: operations["confirmerDesinscription"];
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
        AlerteEnvoi: {
            close: boolean;
            detail: string;
            id: components["schemas"]["Identifiant"];
            lien: string | null;
            /** @enum {string} */
            niveau: "a_faire" | "attention" | "nouveau" | "fait";
            survenue_le: components["schemas"]["Horodatage"];
            titre: string;
        };
        /**
         * @description Alertes par la cloche de la console et par e-mail seulement.
         * @enum {string}
         */
        CanalAlerte: "console" | "email";
        ConnexionCrm: {
            /** @enum {string} */
            adaptateur: "crm_pro" | "generique" | "aucun";
            contacts_vus: number;
            derniere_synchro_le: components["schemas"]["Horodatage"] | null;
            etat: components["schemas"]["EtatFeu"];
        };
        Desinscription: {
            /** Format: email */
            adresse: string;
            campagne_id: components["schemas"]["Identifiant"] | null;
            id: components["schemas"]["Identifiant"];
            source: components["schemas"]["SourceDesinscription"];
            survenue_le: components["schemas"]["Horodatage"];
        };
        EntreeCarnet: {
            /** Format: email */
            adresse: string;
            ajoute_le: components["schemas"]["Horodatage"];
            etiquettes: string[];
            id: components["schemas"]["Identifiant"];
            nom: string | null;
            organisation: string | null;
            prenom: string | null;
        };
        Erreur: {
            details?: {
                [key: string]: string[];
            };
            /** @description Code machine stable (ex. `non_trouve`, `validation`, `envois_arretes`). */
            error: string;
            /** @description Phrase en français, au vouvoiement, affichable telle quelle. */
            message: string;
        };
        /**
         * @description Feu de santé (vert bon, ambre attention, rouge arrêté, bleu en montée, gris inconnu).
         * @enum {string}
         */
        EtatFeu: "vert" | "ambre" | "rouge" | "bleu" | "gris";
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
        Identite: {
            adresse_postale: string;
            /** @example SAS */
            forme: string;
            /** @constant */
            lien_desinscription: true;
            raison_sociale: string;
            signature: string;
        };
        /** @description Ligne du registre d'opposition (« ne plus écrire »). Jamais supprimée. */
        Opposition: {
            id: components["schemas"]["Identifiant"];
            inscrite_le: components["schemas"]["Horodatage"];
            motif: string;
            /** @enum {string} */
            portee: "adresse" | "organisation" | "domaine";
            source: components["schemas"]["SourceDesinscription"];
            valeur: string;
        };
        Pagination: {
            page: number;
            pages: number;
            par_page: number;
            total: number;
        };
        Reglages: {
            /** @example Europe/Paris */
            fuseau: string;
            heures: {
                a: string;
                de: string;
            };
            jours: ("lun" | "mar" | "mer" | "jeu" | "ven" | "sam" | "dim")[];
            pause_si_refus_pct: number;
            plafond_jour: number;
            /** @description Valeur proposée pour la case « Suivre les ouvertures » d'une nouvelle campagne (faux par défaut). */
            suivre_ouvertures_par_defaut: boolean;
        };
        RegleAlerte: {
            actif: boolean;
            canaux: components["schemas"]["CanalAlerte"][];
            /** @enum {string} */
            condition: "taux_refus" | "plainte" | "liste_noire" | "boite_ralentie" | "test_matin" | "reponse_interessee" | "quota_atteint";
            id: components["schemas"]["Identifiant"];
            nom: string;
            seuil: number | null;
        };
        /** @enum {string} */
        SourceDesinscription: "lien" | "reponse_stop" | "plainte" | "manuel";
        Succes: {
            message?: string;
            /** @constant */
            ok: true;
        };
    };
    responses: {
        /** @description État de la connexion au CRM. */
        ConnexionCrm: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["ConnexionCrm"];
            };
        };
        /** @description Erreur, avec un message en français. */
        Erreur: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Erreur"];
            };
        };
        /** @description Identité de l'expéditeur. */
        Identite: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Identite"];
            };
        };
        /** @description Réglages d'envoi. */
        Reglages: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Reglages"];
            };
        };
        /** @description Geste accepté. */
        Succes: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Succes"];
            };
        };
    };
    parameters: {
        Page: number;
        ParPage: number;
        /** @description Recherche texte (insensible à la casse et aux accents). */
        Recherche: string;
    };
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    listerAlertesEnvoi: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Alertes ouvertes et récentes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["AlerteEnvoi"][];
                    };
                };
            };
        };
    };
    listerCarnet: {
        parameters: {
            query?: {
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Entrées du carnet. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["EntreeCarnet"][];
                        meta: components["schemas"]["Pagination"];
                    };
                };
            };
        };
    };
    ajouterAuCarnet: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** Format: email */
                    adresse: string;
                    etiquettes?: string[];
                    nom?: string;
                    organisation?: string;
                    prenom?: string;
                };
            };
        };
        responses: {
            /** @description Entrée créée. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["EntreeCarnet"];
                };
            };
        };
    };
    listerDesinscriptions: {
        parameters: {
            query?: {
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Désinscriptions paginées. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Desinscription"][];
                        meta: components["schemas"]["Pagination"];
                    };
                };
            };
        };
    };
    listerOpposition: {
        parameters: {
            query?: {
                page?: components["parameters"]["Page"];
                par_page?: components["parameters"]["ParPage"];
                /** @description Recherche texte (insensible à la casse et aux accents). */
                q?: components["parameters"]["Recherche"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Lignes du registre. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Opposition"][];
                        meta: components["schemas"]["Pagination"];
                    };
                };
            };
        };
    };
    inscrireOpposition: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    motif: string;
                    /** @enum {string} */
                    portee: "adresse" | "organisation" | "domaine";
                    valeur: string;
                };
            };
        };
        responses: {
            /** @description Ligne inscrite. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Opposition"];
                };
            };
        };
    };
    exporterOpposition: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Fichier CSV. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "text/csv": string;
                };
            };
        };
    };
    puisJeEcrire: {
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
            /** @description Réponse et raisons. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        adresse: string;
                        raisons: string[];
                        /** @enum {string} */
                        reponse: "oui" | "non" | "prudence";
                    };
                };
            };
        };
    };
    lireReglagesEnvoi: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Reglages"];
        };
    };
    enregistrerReglagesEnvoi: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Reglages"];
            };
        };
        responses: {
            200: components["responses"]["Reglages"];
        };
    };
    listerReglesAlerte: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Règles. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["RegleAlerte"][];
                    };
                };
            };
        };
    };
    enregistrerReglesAlerte: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    data: components["schemas"]["RegleAlerte"][];
                };
            };
        };
        responses: {
            200: components["responses"]["Succes"];
        };
    };
    lireConnexionCrm: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["ConnexionCrm"];
        };
    };
    testerConnexionCrm: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["ConnexionCrm"];
        };
    };
    lireIdentite: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Identite"];
        };
    };
    enregistrerIdentite: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["Identite"];
            };
        };
        responses: {
            200: components["responses"]["Identite"];
        };
    };
    lirePageDesinscription: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                jeton: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Adresse masquée et expéditeur. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        adresse_masquee: string;
                        deja_desinscrit: boolean;
                        expediteur: string;
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    confirmerDesinscription: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                jeton: string;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Succes"];
        };
    };
}
