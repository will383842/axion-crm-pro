/**
 * Types du contrat d'API d'Envoi, zone « reponses ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/reponses.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/reponses": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** File des réponses */
        get: operations["listerReponses"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reponses/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Lire une réponse et son fil */
        get: operations["lireReponse"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reponses/{id}/classer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Classer une réponse */
        post: operations["classerReponse"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reponses/{id}/creer-fiche": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Créer une nouvelle personne à partir d'une réponse sans fiche
         * @description Crée la personne dans le CRM avec sa provenance, rattache la réponse
         *     et la remet dans « À traiter ». Rien n'est jamais supprimé.
         */
        post: operations["creerFicheDepuisReponse"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reponses/{id}/rattacher": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Rattacher une réponse d'adresse inconnue à une fiche */
        post: operations["rattacherReponse"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reponses/{id}/repondre": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Répondre dans le même fil, depuis la même boîte */
        post: operations["repondreReponse"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reponses/raccourcis": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Raccourcis de réponse */
        get: operations["listerRaccourcis"];
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
        /** @enum {string} */
        ClassementReponse: "interesse" | "pas_maintenant" | "question" | "refus" | "stop" | "absence" | "a_rattacher";
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
         * Format: date-time
         * @description Date et heure ISO 8601 (UTC).
         */
        Horodatage: string;
        /**
         * @description Identifiant stable, opaque.
         * @example cmp-01
         */
        Identifiant: string;
        MessageFil: {
            corps: string;
            de: string;
            id: components["schemas"]["Identifiant"];
            le: components["schemas"]["Horodatage"];
            objet: string;
            /** @enum {string} */
            sens: "envoye" | "recu";
        };
        Pagination: {
            page: number;
            pages: number;
            par_page: number;
            total: number;
        };
        ReponseApresGeste: components["schemas"]["ReponseResume"] & {
            resume_crm: components["schemas"]["ResumeCrm"] | null;
        };
        ReponseResume: {
            boite_id: components["schemas"]["Identifiant"];
            campagne_id: components["schemas"]["Identifiant"] | null;
            classement: components["schemas"]["ClassementReponse"];
            /** Format: email */
            de: string;
            destinataire_id: components["schemas"]["Identifiant"] | null;
            extrait: string;
            id: components["schemas"]["Identifiant"];
            nom: string | null;
            objet: string;
            organisation: string | null;
            prenom: string | null;
            recue_le: components["schemas"]["Horodatage"];
            suggestion: {
                classement: components["schemas"]["ClassementReponse"];
                confiance: number;
            };
            traitee: boolean;
        };
        /**
         * @description Résumé envoyé au CRM après un geste (les réponses restent dans Envoi,
         *     le CRM n'en reçoit que ce résumé).
         */
        ResumeCrm: {
            classement: components["schemas"]["ClassementReponse"];
            le: components["schemas"]["Horodatage"];
            /** @description Lien vers la réponse dans Envoi. */
            lien: string;
            /**
             * @description Vrai si le contact est mis à l'écart des envois (données gardées).
             * @default false
             */
            opposition: boolean;
            /** @description Libellé de la tâche créée dans le CRM. */
            tache: string | null;
        };
        SuggestionRattachement: {
            libelle: string;
            raison: string;
            reference: string;
            /** @enum {string} */
            type: "contact" | "entreprise";
        };
    };
    responses: {
        /** @description Erreur, avec un message en français. */
        Erreur: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Erreur"];
            };
        };
        /** @description Réponse après le geste, avec le résumé envoyé au CRM. */
        Reponse: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["ReponseApresGeste"];
            };
        };
    };
    parameters: {
        Identifiant: components["schemas"]["Identifiant"];
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
    listerReponses: {
        parameters: {
            query?: {
                classement?: components["schemas"]["ClassementReponse"];
                file?: "a_traiter" | "a_rattacher" | "traitees";
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
            /** @description Réponses paginées et compte par file. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["ReponseResume"][];
                        meta: components["schemas"]["Pagination"];
                        par_file: {
                            a_rattacher: number;
                            a_traiter: number;
                            traitees: number;
                        };
                    };
                };
            };
        };
    };
    lireReponse: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Réponse et fil complet. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        fil: components["schemas"]["MessageFil"][];
                        reponse: components["schemas"]["ReponseResume"];
                        suggestions_rattachement: components["schemas"]["SuggestionRattachement"][];
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    classerReponse: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    classement: components["schemas"]["ClassementReponse"];
                    /** @default false */
                    creer_tache?: boolean;
                    /**
                     * @description Pour « stop » — opposition étendue à toute l'organisation.
                     * @default false
                     */
                    toute_organisation?: boolean;
                };
            };
        };
        responses: {
            200: components["responses"]["Reponse"];
        };
    };
    creerFicheDepuisReponse: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    nom: string;
                    organisation?: string | null;
                    prenom: string;
                    provenance: string;
                };
            };
        };
        responses: {
            200: components["responses"]["Reponse"];
        };
    };
    rattacherReponse: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    reference: string;
                    /** @enum {string} */
                    type: "contact" | "entreprise" | "carnet";
                };
            };
        };
        responses: {
            200: components["responses"]["Reponse"];
        };
    };
    repondreReponse: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                id: components["parameters"]["Identifiant"];
            };
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    /** @description Collègue à qui la suite est confiée (facultatif). */
                    attribuer_a?: string | null;
                    corps: string;
                    raccourci_id?: components["schemas"]["Identifiant"];
                };
            };
        };
        responses: {
            200: components["responses"]["Reponse"];
        };
    };
    listerRaccourcis: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Raccourcis. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        /** @description Collègues à qui une réponse peut être attribuée. */
                        collegues: {
                            id: string;
                            nom: string;
                        }[];
                        data: {
                            corps: string;
                            id: components["schemas"]["Identifiant"];
                            nom: string;
                        }[];
                    };
                };
            };
        };
    };
}
