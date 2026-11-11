/**
 * Types du contrat d'API d'Envoi, zone « commun ».
 * FICHIER GÉNÉRÉ par `pnpm api:envoi` depuis `backend/openapi/envoi/commun.yaml`.
 * Ne pas modifier à la main : modifier la description, puis régénérer.
 */
export type paths = Record<string, never>;
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
        /**
         * @description Boîte d'envoi. Elle n'a plus de chauffe propre : la montée en douceur se
         *     lit par domaine et par adresse IP (`GET /envoi/chauffe`). Jamais d'identifiants
         *     chiffrés, d'hôte ni de port dans la liste ni dans la fiche.
         */
        BoiteResume: {
            /** Format: email */
            adresse: string;
            /** @description Identifiant du domaine de la boîte ; nul si ce domaine n'est pas enregistré dans l'espace. */
            domaine_id: components["schemas"]["Identifiant"] | null;
            envoyes_aujourdhui: number;
            etat: components["schemas"]["EtatFeu"];
            id: components["schemas"]["Identifiant"];
            nom_affiche: string;
            quota_jour: number;
            reponses_7j: number;
            statut: components["schemas"]["StatutBoite"];
            /** @description Refus (durs et mous) sur 7 jours, en pourcentage ; nul tant qu'il n'est pas mesurable (aucun envoi, ou source des refus mous absente). */
            taux_refus: number | null;
        };
        CampagneResume: {
            /** @description Test A/B sur l'objet du premier message. */
            ab_objet: boolean;
            boites: components["schemas"]["Identifiant"][];
            cible: components["schemas"]["CibleCampagne"];
            cree_le: components["schemas"]["Horodatage"];
            envoyes: number;
            /** @description Nombre de messages de la séquence (1 à 4). */
            etapes: number;
            id: components["schemas"]["Identifiant"];
            nom: string;
            prochain_envoi_le: components["schemas"]["Horodatage"] | null;
            reponses: number;
            reponses_interessees: number;
            statut: components["schemas"]["StatutCampagne"];
            total: number;
        };
        /**
         * @description Alertes par la cloche de la console et par e-mail seulement.
         * @enum {string}
         */
        CanalAlerte: "console" | "email";
        /** @description Position d'un domaine dans la montée en douceur. */
        Chauffe: {
            jours_effectifs: number;
            palier: components["schemas"]["PalierChauffe"];
            /** @description Date jusqu'à laquelle le palier est tenu (frein, pause manuelle ou inactivité), sinon nul. */
            tenu_jusqu_au: string | null;
            total_palier: number;
        };
        CibleCampagne: {
            libelle: string;
            /** @description Identifiant de la liste ou de l'audience, ou nom de l'étiquette. */
            reference: string;
            total: number;
            type: components["schemas"]["TypeCible"];
        };
        /** @enum {string} */
        ClassementReponse: "interesse" | "pas_maintenant" | "question" | "refus" | "stop" | "absence" | "a_rattacher";
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
        Desinscription: {
            /** Format: email */
            adresse: string;
            campagne_id: components["schemas"]["Identifiant"] | null;
            id: components["schemas"]["Identifiant"];
            source: components["schemas"]["SourceDesinscription"];
            survenue_le: components["schemas"]["Horodatage"];
        };
        Destinataire: {
            /** Format: email */
            adresse: string;
            campagne_id: components["schemas"]["Identifiant"];
            /** @description Code renvoyé par le serveur destinataire (ex. « 550 5.1.1 »). */
            code_serveur: string | null;
            /** @description Contact du CRM (adaptateur CRM Pro) ; nul pour le carnet d'Envoi seul. */
            contact_id: number | null;
            dernier_evenement_le: components["schemas"]["Horodatage"] | null;
            /** @description Dernier message de la séquence envoyé (0 = aucun). */
            etape: number;
            etat: components["schemas"]["EtatDestinataire"];
            id: components["schemas"]["Identifiant"];
            /** @description Code serveur traduit en français (ex. « Adresse inexistante »). */
            motif: string | null;
            nom: string | null;
            organisation: string | null;
            prenom: string | null;
        };
        DomaineResume: {
            ajoute_le: components["schemas"]["Horodatage"];
            boites: number;
            chauffe: components["schemas"]["Chauffe"] | null;
            /** @description Politique DMARC publiée ; nulle tant qu'elle n'est pas connue. */
            dmarc: components["schemas"]["PolitiqueDmarc"] | null;
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
        Erreur: {
            details?: {
                [key: string]: string[];
            };
            /** @description Code machine stable (ex. `non_trouve`, `validation`, `envois_arretes`). */
            error: string;
            /** @description Phrase en français, au vouvoiement, affichable telle quelle. */
            message: string;
        };
        /** @enum {string} */
        EtatDestinataire: "a_envoyer" | "envoye" | "remis" | "rebond_mou" | "rebond_dur" | "a_repondu" | "desinscrit" | "plainte" | "exclu" | "relances_annulees";
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
        EvenementEnvoi: {
            /** Format: email */
            adresse: string;
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
        MessageType: {
            id: components["schemas"]["Identifiant"];
            nom: string;
            /** @description Nombre de campagnes qui l'utilisent. */
            utilise_par: number;
            version_courante: number;
            versions: components["schemas"]["VersionMessage"][];
        };
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
        /**
         * @description Palier de la montée en douceur (P1 le plus bas, P8 le plus haut).
         * @enum {string}
         */
        PalierChauffe: "P1" | "P2" | "P3" | "P4" | "P5" | "P6" | "P7" | "P8";
        /** @enum {string} */
        PolitiqueDmarc: "none" | "quarantine" | "reject";
        /** @enum {string} */
        Produit: "crm" | "envoi" | "commun";
        RegleAlerte: {
            actif: boolean;
            canaux: components["schemas"]["CanalAlerte"][];
            condition: components["schemas"]["TypeAlerte"];
            id: components["schemas"]["Identifiant"];
            nom: string;
            seuil: number | null;
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
            /** @description Version de configuration appliquée ; nulle si aucune n'a été appliquée. */
            version_configuration: number | null;
        };
        /** @enum {string} */
        SourceDesinscription: "lien" | "reponse_stop" | "plainte" | "manuel";
        /**
         * @description Statut d'une boîte ou d'une adresse IP ; `inconnu` (ajouté de façon compatible) quand la santé n'est pas mesurable.
         * @enum {string}
         */
        StatutBoite: "prete" | "ralentie" | "en_chauffe" | "suspendue" | "inconnu";
        /** @enum {string} */
        StatutCampagne: "brouillon" | "a_valider" | "programmee" | "en_cours" | "en_pause" | "terminee" | "arretee";
        Succes: {
            message?: string;
            /** @constant */
            ok: true;
        };
        /**
         * @description Type d'alerte d'envoi. Les six derniers (ajoutés de façon compatible) sont les motifs de frein
         *     de la garde de chauffe : témoin en indésirables, réponses en baisse, reports en hausse,
         *     tableau de bord non validé (homme mort), santé silencieuse, réponses non traitées.
         * @enum {string}
         */
        TypeAlerte: "taux_refus" | "plainte" | "liste_noire" | "boite_ralentie" | "test_matin" | "reponse_interessee" | "quota_atteint" | "temoin_indesirable" | "reponses_en_baisse" | "reports_en_hausse" | "homme_mort" | "sante_silencieuse" | "reponses_non_traitees";
        /** @enum {string} */
        TypeCible: "liste" | "audience" | "etiquette" | "carnet";
        /** @enum {string} */
        TypeEvenementEnvoi: "remis" | "rebond_dur" | "rebond_mou" | "plainte" | "desinscription" | "reponse";
        VersionMessage: {
            corps: string;
            modifie_le: components["schemas"]["Horodatage"];
            numero: number;
            objet: string;
            /** @description Objet B du test A/B, s'il y en a un. */
            objet_b?: string | null;
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
export type operations = Record<string, never>;
