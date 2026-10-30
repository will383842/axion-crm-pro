import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RecuDeLaConsoleCard } from '@/features/companies/components/RecuDeLaConsoleCard';

const MENTION = 'Aucun montant, aucun texte de note ne passe.';

describe('RecuDeLaConsoleCard', () => {
  it('dit « Rien reçu » sans dossier', () => {
    render(<RecuDeLaConsoleCard console={null} />);
    expect(screen.getByText('Rien reçu pour l’instant')).toBeInTheDocument();
    expect(screen.queryByText(MENTION)).toBeNull();
  });

  it('liste exactement ce que la console envoie', () => {
    render(
      <RecuDeLaConsoleCard
        console={{
          statut: 'client',
          axi_cli: 'AXI-CLI-0042',
          etape: 'devis_envoye',
          cloture_prevue: '2026-11-20',
          questionnaire: { nb_reponses_equipe: 2, attendus: 5, date_limite: '2026-11-05' },
          prochaine_action: { type: 'relance', date: '2026-10-30' },
          taches_ouvertes: { nb: 4, prochaine_le: '2026-10-29' },
          nb_emails_console: 3,
          nb_notes_console: 1,
        }}
        contacts={[
          { id: 1, first_name: 'Anne', last_name: 'Martin', metadata: { role_decision: 'decideur' } },
          { id: 2, first_name: 'Paul', last_name: 'Durand', metadata: {} },
        ]}
      />,
    );
    expect(screen.getByText('Client')).toBeInTheDocument();
    expect(screen.getByText('AXI-CLI-0042')).toBeInTheDocument();
    expect(screen.getByText('2 réponses d’équipe sur 5, avant le 5 nov.')).toBeInTheDocument();
    expect(screen.getByText('1 décideur')).toBeInTheDocument();
    expect(screen.getByText(/peut inclure des personnes parties/)).toBeInTheDocument();
    expect(screen.queryByText(/Anne|Martin|Paul|Durand/)).toBeNull();
    expect(screen.getByText(/Relance/)).toHaveTextContent('à faire dans la console · avant le 30 oct.');
    expect(screen.getByText(/4 tâches ouvertes dans la console/)).toHaveTextContent('la plus proche : 29 oct.');
    expect(screen.getByText(/Devis envoyé/)).toHaveTextContent('clôture prévue le 20 nov.');
    expect(screen.getByText('3 e-mails · 1 note')).toBeInTheDocument();
    expect(screen.getByText(MENTION)).toBeInTheDocument();
    expect(screen.getByText(/Les actions se font dans la console d’Axion IA/)).toBeInTheDocument();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByText(/archivé dans la console/)).toBeNull();
  });

  it('signale un client archivé et n’affiche aucun lien', () => {
    render(<RecuDeLaConsoleCard console={{ etape: 'archive', url_console: 'https://secret.example' } as never} archiveIci={false} />);
    expect(screen.getByText('Ce client est archivé dans la console.')).toBeInTheDocument();
    expect(screen.queryByText(/il l’est aussi ici/)).toBeNull();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByText(/secret\.example/)).toBeNull();
  });

  it('affirme l’archivage ici seulement avec archiveIci', () => {
    render(<RecuDeLaConsoleCard console={{ etape: 'archive' }} archiveIci />);
    expect(screen.getByText('Ce client est archivé dans la console : il l’est aussi ici.')).toBeInTheDocument();
  });

  it('ne montre aucun nom pour un contact avec role_decision, seulement un décompte', () => {
    render(
      <RecuDeLaConsoleCard
        console={{ statut: 'client' }}
        contacts={[
          { id: 1, first_name: 'Anne', last_name: 'Martin', metadata: { role_decision: 'decideur' } },
          { id: 2, first_name: 'Luc', last_name: 'Petit', metadata: { role_decision: 'decideur' } },
          { id: 3, first_name: 'Eva', last_name: 'Roux', metadata: { role_decision: 'influenceur' } },
        ]}
      />,
    );
    expect(screen.getByText('2 décideurs, 1 influenceur')).toBeInTheDocument();
    expect(screen.queryByText(/Anne|Martin|Luc|Petit|Eva|Roux/)).toBeNull();
  });

  it('questionnaire partiel : seulement les réponses, seulement les attendus', () => {
    const { unmount } = render(<RecuDeLaConsoleCard console={{ questionnaire: { nb_reponses_equipe: 1 } }} />);
    expect(screen.getByText('1 réponse d’équipe reçue')).toBeInTheDocument();
    unmount();
    render(<RecuDeLaConsoleCard console={{ questionnaire: { attendus: 4 } }} />);
    expect(screen.getByText('4 attendus')).toBeInTheDocument();
  });

  it('questionnaire sans compte : envoyé le / reçu le', () => {
    const { unmount } = render(<RecuDeLaConsoleCard console={{ questionnaire: { envoye_le: '2026-10-12' } }} />);
    expect(screen.getByText('envoyé le 12 oct.')).toBeInTheDocument();
    unmount();
    render(<RecuDeLaConsoleCard console={{ questionnaire: { envoye_le: '2026-10-12', recu_le: '2026-10-20' } }} />);
    expect(screen.getByText('reçu le 20 oct.')).toBeInTheDocument();
  });

  it('questionnaire reçu avec zéro réponse d’équipe : la date reste visible', () => {
    render(<RecuDeLaConsoleCard console={{ questionnaire: { recu_le: '2026-10-20', nb_reponses_equipe: 0 } }} />);
    expect(screen.getByText(/reçu le 20 oct\./)).toBeInTheDocument();
    expect(screen.getByText(/0 réponse d’équipe reçue/)).toBeInTheDocument();
  });

  it('zéro tâche ouverte', () => {
    render(<RecuDeLaConsoleCard console={{ taches_ouvertes: { nb: 0, prochaine_le: '2026-10-29' } }} />);
    const t = screen.getByText(/0 tâche ouverte dans la console/);
    expect(t).not.toHaveTextContent('la plus proche');
  });

  it('statut et étape inconnus : AXI-CLI reste affiché, pas de ligne inventée', () => {
    render(<RecuDeLaConsoleCard console={{ statut: 'zzz', etape: 'yyy', axi_cli: 'AXI-CLI-0007' }} />);
    expect(screen.getByText('AXI-CLI-0007')).toBeInTheDocument();
    expect(screen.queryByText(/zzz|yyy/)).toBeNull();
    expect(screen.queryByText('Étape')).toBeNull();
  });

  it('console avec clés non reconnues', () => {
    render(<RecuDeLaConsoleCard console={{ truc: 1 } as never} />);
    expect(screen.getByText('Données reçues, non reconnues par cet écran')).toBeInTheDocument();
    expect(screen.queryByText('Rien reçu pour l’instant')).toBeNull();
  });

  it('ne rend pas de liste vide', () => {
    render(<RecuDeLaConsoleCard console={{ statut: 'zzz' }} />);
    expect(screen.getByText('Données reçues, non reconnues par cet écran')).toBeInTheDocument();
    expect(screen.queryByText('Rien reçu pour l’instant')).toBeNull();
    expect(document.querySelector('dl')).toBeNull();
  });
});
