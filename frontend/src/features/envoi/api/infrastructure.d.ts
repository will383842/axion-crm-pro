/**
 * Types du contrat d'API d'Envoi, zone « infrastructure ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/infrastructure.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export interface paths {
    "/envoi/boites": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Boîtes d'envoi */
        get: operations["listerBoites"];
        put?: never;
        /** Ajouter une boîte (lance une chauffe de 30 jours) */
        post: operations["ajouterBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Fiche d'une boîte */
        get: operations["lireBoite"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}/reactiver": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Réactiver une boîte suspendue */
        post: operations["reactiverBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}/reglages": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        /** Enregistrer la signature et le mode absence d'une boîte */
        put: operations["enregistrerReglagesBoite"];
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/{id}/suspendre": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Suspendre une boîte */
        post: operations["suspendreBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/boites/test-connexion": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Tester la connexion d'une boîte avant de l'ajouter */
        post: operations["testerConnexionBoite"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/chauffe": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Chauffe des boîtes et domaines (montée en douceur) */
        get: operations["lireChauffe"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/chauffe/{id}/reculer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Reculer la montée en douceur d'un palier (jours effectifs) */
        post: operations["reculerChauffe"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/configuration": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Configuration versionnée (courante et prête) */
        get: operations["lireConfiguration"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/configuration/appliquer": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Appliquer la version prête */
        post: operations["appliquerConfiguration"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/configuration/versions/{numero}/revenir": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Revenir à une version précédente */
        post: operations["revenirConfiguration"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Domaines d'envoi */
        get: operations["listerDomaines"];
        put?: never;
        /** Ajouter un domaine (rend les enregistrements DNS à créer) */
        post: operations["ajouterDomaine"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines/{id}": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Fiche d'un domaine sur 30 jours */
        get: operations["lireDomaine"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines/{id}/dmarc": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** DMARC progressif — passer à la politique suivante ou poser un rappel */
        post: operations["avancerDmarc"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/domaines/{id}/dns/verifier": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Vérifier les enregistrements DNS */
        post: operations["verifierDns"];
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/listes-noires": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Présence des domaines et adresses IP sur les listes noires */
        get: operations["listerListesNoires"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/serveurs": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        /** Serveurs et adresses IP */
        get: operations["listerServeurs"];
        put?: never;
        post?: never;
        delete?: never;
        options?: never;
        head?: never;
        patch?: never;
        trace?: never;
    };
    "/envoi/serveurs/{id}/adresses-ip": {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        get?: never;
        put?: never;
        /** Ajouter une adresse IP (prépare une nouvelle version de configuration) */
        post: operations["ajouterAdresseIp"];
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
        /** @enum {string} */
        ClassementReponse: "interesse" | "pas_maintenant" | "question" | "refus" | "stop" | "absence" | "a_rattacher";
        Configuration: {
            courante: components["schemas"]["VersionConfiguration"];
            historique: components["schemas"]["VersionConfiguration"][];
            prete: components["schemas"]["VersionConfiguration"] | null;
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
        EnregistrementDns: {
            /** @enum {string} */
            etat: "ok" | "absent" | "different";
            nom: string;
            /**
             * @description Ce que l'enregistrement sert à faire.
             * @enum {string}
             */
            role: "spf" | "dkim" | "dmarc" | "ptr" | "mta_sts";
            /** @enum {string} */
            type: "TXT" | "CNAME" | "MX" | "PTR";
            valeur: string;
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
        JourActivite: {
            envoyes: number;
            /** Format: date */
            jour: string;
            plaintes: number;
            rebonds_durs: number;
            rebonds_mous: number;
            remis: number;
            reponses: number;
        };
        ListeNoire: {
            element: string;
            /** @description Adresse de la demande de retrait de la liste (vide si l'élément est propre). */
            lien_retrait: string | null;
            liste: string;
            present: boolean;
            /** @description Fiche « que faire » en français (vide si l'élément est propre). */
            que_faire: string | null;
            verifie_le: components["schemas"]["Horodatage"];
        };
        /** @description Pendant l'absence, la boîte n'envoie plus et les réponses automatiques sont mises de côté. */
        ModeAbsence: {
            actif: boolean;
            jusqu_au: string | null;
            message: string | null;
        };
        /** @enum {string} */
        PolitiqueDmarc: "none" | "quarantine" | "reject";
        ReglagesBoite: {
            mode_absence: components["schemas"]["ModeAbsence"];
            signature: string;
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
        VersionConfiguration: {
            appliquee_le: components["schemas"]["Horodatage"] | null;
            changements: {
                libelle: string;
                /** @enum {string} */
                sorte: "ajout" | "retrait" | "modification";
            }[];
            creee_le: components["schemas"]["Horodatage"];
            numero: number;
            /** @description Qui a appliqué la version (prénom, ou « Système »). */
            par: string | null;
            /** @enum {string} */
            resultat: "prete" | "appliquee" | "remplacee" | "retour_automatique";
        };
    };
    responses: {
        /** @description Boîte après le geste. */
        Boite: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["BoiteResume"];
            };
        };
        /** @description Configuration versionnée. */
        Configuration: {
            headers: {
                [name: string]: unknown;
            };
            content: {
                "application/json": components["schemas"]["Configuration"];
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
    listerBoites: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Boîtes. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["BoiteResume"][];
                    };
                };
            };
        };
    };
    ajouterBoite: {
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
                    domaine_id: components["schemas"]["Identifiant"];
                    mode_absence?: components["schemas"]["ModeAbsence"];
                    nom_affiche: string;
                    /** @description Signature ajoutée en bas des messages de cette boîte. */
                    signature?: string;
                };
            };
        };
        responses: {
            201: components["responses"]["Boite"];
            422: components["responses"]["Erreur"];
        };
    };
    lireBoite: {
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
            /** @description Boîte, 30 jours d'activité et derniers refus. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        boite: components["schemas"]["BoiteResume"];
                        derniers_refus: components["schemas"]["EvenementEnvoi"][];
                        jours: components["schemas"]["JourActivite"][];
                        reglages: components["schemas"]["ReglagesBoite"];
                        reponses: components["schemas"]["ReponseResume"][];
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    reactiverBoite: {
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
            200: components["responses"]["Boite"];
        };
    };
    enregistrerReglagesBoite: {
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
                "application/json": components["schemas"]["ReglagesBoite"];
            };
        };
        responses: {
            /** @description Réglages enregistrés. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["ReglagesBoite"];
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    suspendreBoite: {
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
            200: components["responses"]["Boite"];
        };
    };
    testerConnexionBoite: {
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
                    domaine_id: components["schemas"]["Identifiant"];
                };
            };
        };
        responses: {
            /** @description Résultat du test, étape par étape. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        etapes: {
                            libelle: string;
                            ok: boolean;
                        }[];
                        reussi: boolean;
                    };
                };
            };
        };
    };
    lireChauffe: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Boîtes en chauffe et courbe prévue. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        boites: components["schemas"]["BoiteResume"][];
                        courbe: {
                            jour: number;
                            plafond: number;
                        }[];
                    };
                };
            };
        };
    };
    reculerChauffe: {
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
            200: components["responses"]["Boite"];
            409: components["responses"]["Erreur"];
        };
    };
    lireConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Configuration"];
        };
    };
    appliquerConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Configuration"];
            409: components["responses"]["Erreur"];
        };
    };
    revenirConfiguration: {
        parameters: {
            query?: never;
            header?: never;
            path: {
                numero: number;
            };
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            200: components["responses"]["Configuration"];
        };
    };
    listerDomaines: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Domaines. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["DomaineResume"][];
                        /** @description Date d'expiration de chaque domaine (identifiant du domaine -> date). */
                        expirations: {
                            [key: string]: components["schemas"]["Horodatage"];
                        };
                        /**
                         * @description Garde anti-dispersion : nombre de domaines utilisés et limite
                         *     (trois domaines par serveur d'envoi). Au-delà, l'ajout est refusé.
                         */
                        garde_dispersion: {
                            limite: number;
                            utilises: number;
                        };
                    };
                };
            };
        };
    };
    ajouterDomaine: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody: {
            content: {
                "application/json": {
                    nom: string;
                };
            };
        };
        responses: {
            /** @description Domaine et enregistrements à créer. */
            201: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        dns: components["schemas"]["EnregistrementDns"][];
                        domaine: components["schemas"]["DomaineResume"];
                    };
                };
            };
            422: components["responses"]["Erreur"];
        };
    };
    lireDomaine: {
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
            /** @description Domaine, DNS, 30 jours, listes noires. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        dns: components["schemas"]["EnregistrementDns"][];
                        domaine: components["schemas"]["DomaineResume"];
                        expire_le: components["schemas"]["Horodatage"];
                        jours: components["schemas"]["JourActivite"][];
                        listes_noires: components["schemas"]["ListeNoire"][];
                    };
                };
            };
            404: components["responses"]["Erreur"];
        };
    };
    avancerDmarc: {
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
                    politique: components["schemas"]["PolitiqueDmarc"];
                    rappel_le?: components["schemas"]["Horodatage"];
                };
            };
        };
        responses: {
            /** @description Domaine après le geste. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": components["schemas"]["DomaineResume"];
                };
            };
        };
    };
    verifierDns: {
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
            /** @description État de chaque enregistrement. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        dns: components["schemas"]["EnregistrementDns"][];
                    };
                };
            };
        };
    };
    listerListesNoires: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Une ligne par liste et par élément surveillé. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["ListeNoire"][];
                    };
                };
            };
        };
    };
    listerServeurs: {
        parameters: {
            query?: never;
            header?: never;
            path?: never;
            cookie?: never;
        };
        requestBody?: never;
        responses: {
            /** @description Serveurs. */
            200: {
                headers: {
                    [name: string]: unknown;
                };
                content: {
                    "application/json": {
                        data: components["schemas"]["ServeurResume"][];
                    };
                };
            };
        };
    };
    ajouterAdresseIp: {
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
                    adresse: string;
                    domaine_id: components["schemas"]["Identifiant"];
                    flux: components["schemas"]["FluxEnvoi"];
                };
            };
        };
        responses: {
            200: components["responses"]["Configuration"];
            422: components["responses"]["Erreur"];
        };
    };
}
