/**
 * Types du contrat d'API d'Envoi, zone « reputation ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/reputation.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/reputation": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /**
         * Derniers constats de réputation
         * @description Réservé à `users.manage` (owner, admin). Les plus récents d'abord, 100 au plus.
         */
        get: operations["lireReputation"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/reputation/constats": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Saisir un constat lu dans une console de réputation
         * @description Réservé à `workspaces.manage` (owner seul ; 403 sinon). Un constat « mauvaise » ouvre une pause
         *     vers le fournisseur concerné (google_postmaster : gmail ; microsoft_snds et microsoft_jmrp :
         *     microsoft ; orange : autre) et l'alerte `reputation_mauvaise`. Toute autre valeur n'ouvre rien
         *     et ne lève rien. L'en-tête `Idempotency-Key` (facultatif) rend le rejeu inoffensif : sans lui,
         *     un constat identique est reconnu par son empreinte. Même clé avec un autre contenu : 409.
         *     422 si la preuve (10 à 500 caractères) contient une adresse e-mail.
         */
        post: operations["saisirConstatReputation"];
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
        ConstatReputation: {
            auteur: string;
            /** Format: date-time */
            cree_le: string;
            erreurs_auth: number | null;
            id: number;
            /** Format: date */
            jour: string;
            /** @description Vrai si CE constat a ouvert une pause (jamais pour un rejeu). */
            pause_ouverte: boolean;
            pieges: number | null;
            preuve: string;
            reputation: components["schemas"]["ValeurReputation"];
            ressource: string;
            /** @enum {string} */
            ressource_type: "ip" | "domaine";
            /** @enum {string} */
            saisie: "manuelle" | "automatique";
            source: components["schemas"]["SourceReputation"];
            taux_plaintes: number | null;
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
        SaisieConstatReputation: {
            erreurs_auth?: number | null;
            /**
             * Format: date
             * @description Jour du constat (Paris), pas dans le futur.
             */
            jour: string;
            pieges?: number | null;
            /** @description Ce qui a été lu dans la console : sans adresse e-mail. */
            preuve: string;
            reputation: components["schemas"]["ValeurReputation"];
            /** @description Adresse IP ou nom de domaine (jamais une adresse e-mail). */
            ressource: string;
            /** @enum {string} */
            ressource_type: "ip" | "domaine";
            source: components["schemas"]["SourceReputation"];
            taux_plaintes?: number | null;
        };
        /** @enum {string} */
        SourceReputation: "google_postmaster" | "microsoft_snds" | "microsoft_jmrp" | "orange";
        /** @enum {string} */
        ValeurReputation: "bonne" | "moyenne" | "mauvaise" | "inconnue";
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
    parameters: never;
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    lireReputation: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Les constats. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["ConstatReputation"][];
                    };
                };
            };
            403: components["responses"]["Erreur"];
        };
    };
    saisirConstatReputation: {
        parameters: {
            query?: never;
            header?: {
                "Idempotency-Key"?: string;
            };
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": components["schemas"]["SaisieConstatReputation"];
            };
        };
        responses: {
            /** @description Rejeu reconnu, le constat déjà enregistré (aucun nouvel effet). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ConstatReputation"];
                };
            };
            /** @description Constat enregistré. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ConstatReputation"];
                };
            };
            403: components["responses"]["Erreur"];
            409: components["responses"]["Erreur"];
            422: components["responses"]["Erreur"];
        };
    };
}
