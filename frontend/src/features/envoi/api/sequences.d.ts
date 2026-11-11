/**
 * Types du contrat d'API d'Envoi, zone « sequences ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/sequences.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/sequences": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Séquences */
        get: operations["listerSequences"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/sequences/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Fiche d'une séquence */
        get: operations["lireSequence"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/sequences/{id}/pause": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Mettre une séquence en pause
         * @description Permission requise, `users.manage` (403 sinon).
         */
        post: operations["mettreSequenceEnPause"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/sequences/{id}/reprise": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Reprendre une séquence en pause
         * @description Permission requise, `workspaces.manage` (403 sinon). Refusée (409) tant
         *     qu'un frein, l'arrêt général ou une pause automatique est actif.
         */
        post: operations["reprendreSequence"];
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
        Erreur: {
            details?: {
                [key: string]: string[];
            };
            /** @description Code machine stable (ex. `non_trouve`, `validation`, `envois_arretes`). */
            error: string;
            /** @description Phrase en français, au vouvoiement, affichable telle quelle. */
            message: string;
        };
        /** @description Compteurs d'une étape. Jamais le corps du message. */
        EtapeSequence: {
            delai_jours: number;
            desinscriptions: number;
            envoyes: number;
            objet: string;
            rang: number;
            rebonds_durs: number;
            reponses: number;
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
        InscriptionsSequence: {
            arretees: number;
            en_cours: number;
            reportees: number;
            terminees: number;
            total: number;
        };
        /** @enum {string} */
        MotifPauseSequence: "manuelle" | "frein" | "arret_general" | "automatique";
        SequenceDetail: components["schemas"]["SequenceResume"] & {
            etapes: components["schemas"]["EtapeSequence"][];
            peut_reprendre: boolean;
            raison_refus_reprise: string | null;
        };
        SequenceResume: {
            creee_le: components["schemas"]["Horodatage"];
            id: components["schemas"]["Identifiant"];
            inscriptions: components["schemas"]["InscriptionsSequence"];
            motif_pause: components["schemas"]["MotifPauseSequence"] | null;
            nb_etapes: number;
            nom: string;
            prochain_envoi: components["schemas"]["Horodatage"] | null;
            reservee_aux_temoins: boolean;
            statut: components["schemas"]["StatutSequence"];
        };
        /** @enum {string} */
        StatutSequence: "active" | "en_pause" | "terminee";
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
    };
    parameters: {
        Identifiant: components["schemas"]["Identifiant"];
    };
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    listerSequences: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Séquences. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        sequences: components["schemas"]["SequenceResume"][];
                    };
                };
            };
        };
    };
    lireSequence: {
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
            /** @description Séquence, étapes et possibilité de reprise. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SequenceDetail"];
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    mettreSequenceEnPause: {
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
            /** @description La séquence après la pause. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SequenceDetail"];
                };
            };
            403: components["responses"]["Erreur"];
            404: components["responses"]["Erreur"];
        };
    };
    reprendreSequence: {
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
            /** @description La séquence après la reprise. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["SequenceDetail"];
                };
            };
            403: components["responses"]["Erreur"];
            404: components["responses"]["Erreur"];
            409: components["responses"]["Erreur"];
        };
    };
}
