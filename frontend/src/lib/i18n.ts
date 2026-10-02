import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import fr from '@/locales/fr.json';

/**
 * Langue FIXÉE au français (finitions P2, audit UX du 2026-10-02).
 *
 * La console a un seul utilisateur, francophone. L'ancien détecteur lisait la
 * langue du navigateur : un navigateur réglé en anglais basculait l'interface
 * sur un dictionnaire anglais de quelques libellés, au milieu d'écrans écrits
 * en dur en français. Il n'y a plus ni détecteur, ni dictionnaire anglais :
 * une seule langue.
 */
i18n.use(initReactI18next).init({
  lng: 'fr',
  fallbackLng: 'fr',
  supportedLngs: ['fr'],
  resources: {
    fr: { translation: fr },
  },
  interpolation: { escapeValue: false },
});

export default i18n;
