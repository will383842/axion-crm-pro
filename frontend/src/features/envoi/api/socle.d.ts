/**
 * Types du contrat d'API d'Envoi, zone « socle ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/socle.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/config/features": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Drapeaux de la console et droits de l'espace par produit */
        get: operations["lireFeatures"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/arret-general": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /**
         * Arrêt général — plus aucun message ne part pour l'espace (idempotent)
         * @description Arme seulement le drapeau d'arrêt de l'espace (permission `users.manage` :
         *     propriétaire et administrateur). Un second appel répond 200 sans nouvelle
         *     écriture. La reprise n'est pas une route : commande locale journalisée
         *     `envoi:reprendre <espace> --auteur=`.
         *
         *     Au second appel : aucune nouvelle ligne de journal ; l'alerte est émise comme
         *     pour la commande ; la pause globale `ServeurEnvoi::pause()` n'est pas utilisée.
         *     Refus (403) : message « demandez à un administrateur ». Le motif est limité en
         *     longueur et jamais recopié tel quel dans le journal ni dans l'alerte (T-SOCLE le
         *     bornera).
         */
        post: operations["arretGeneral"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/compteurs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Compteurs de l'en-tête et du tableau de bord d'Envoi */
        get: operations["lireCompteursEnvoi"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/etat": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** État global des envois (feu de l'en-tête) */
        get: operations["lireEtatEnvois"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/notifications": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Notifications de la cloche (deux produits) */
        get: operations["listerNotifications"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/notifications/{id}/read": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Marquer une notification comme lue */
        post: operations["marquerNotificationLue"];
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
        CompteursEnvoi: {
            alertes_ouvertes: number;
            boites_pretes: number;
            boites_total: number;
            campagnes_en_cours: number;
            en_file: number;
            envoyes_aujourdhui: number;
            plafond_jour: number;
            reponses_a_traiter: number;
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
        EtatEnvois: {
            depuis: components["schemas"]["Horodatage"];
            /** @enum {string} */
            etat: "actif" | "arrete";
            feu: components["schemas"]["EtatFeu"];
            motif: string | null;
            par: string | null;
        };
        /**
         * @description Feu de santé (vert bon, ambre attention, rouge arrêté, bleu en montée, gris inconnu).
         * @enum {string}
         */
        EtatFeu: "vert" | "ambre" | "rouge" | "bleu" | "gris";
        /**
         * @description Droit d'accès d'un espace à un produit. Côté serveur, la garde `produit-envoi` n'ouvre `/envoi/*` (404 sinon) que pour `actif` : `suspendu`, `resilie` et `absent` sont fermés (choix prudent, plus strict que l'affichage du frontend). Source : `workspaces.settings` → `produits.<produit>`, clé réservée à l'exploitation (`PUT /workspace` la refuse et la conserve).
         * @enum {string}
         */
        EtatProduit: "actif" | "suspendu" | "resilie" | "absent";
        Features: {
            console_v2: boolean;
            produits: {
                crm: components["schemas"]["EtatProduit"];
                envoi: components["schemas"]["EtatProduit"];
            };
            universes: {
                business: boolean;
                vivier: boolean;
            };
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
        Notification: {
            /** @description Ancien nom du lien (lu par la cloche actuelle) ; même valeur que `lien`. */
            action_url: string | null;
            body: string | null;
            created_at: components["schemas"]["Horodatage"];
            id: components["schemas"]["Identifiant"];
            /** @description Chemin de l'écran à ouvrir. */
            lien: string | null;
            /** @enum {string} */
            niveau: "a_faire" | "attention" | "nouveau" | "fait";
            produit: components["schemas"]["Produit"];
            read_at: components["schemas"]["Horodatage"] | null;
            title: string;
            type: string;
        };
        /** @enum {string} */
        Produit: "crm" | "envoi" | "commun";
        Succes: {
            message?: string;
            /** @constant */
            ok: true;
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
        Identifiant: components["schemas"]["Identifiant"];
    };
    requestBodies: never;
    headers: never;
    pathItems: never;
}
export type $defs = Record<string, never>;
export interface operations {
    lireFeatures: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Drapeaux et produits (`actif`, `suspendu`, `resilie`, `absent`). */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["Features"];
                };
            };
        };
    };
    arretGeneral: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: {
            content: {
                "application/json": {
                    motif?: string;
                };
            };
        };
        responses: {
            /** @description Envois arrêtés. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["EtatEnvois"];
                };
            };
            403: components["responses"]["Erreur"];
        };
    };
    lireCompteursEnvoi: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Compteurs du jour. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["CompteursEnvoi"];
                };
            };
        };
    };
    lireEtatEnvois: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description État des envois. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["EtatEnvois"];
                };
            };
        };
    };
    listerNotifications: {
        parameters: {
            query?: {
                produit?: components["schemas"]["Produit"];
            };
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Notifications, les plus récentes d'abord. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["Notification"][];
                        degraded?: boolean;
                        unread_count: number;
                    };
                };
            };
        };
    };
    marquerNotificationLue: {
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
            200: components["responses"]["Succes"];
            404: components["responses"]["Erreur"];
        };
    };
}
