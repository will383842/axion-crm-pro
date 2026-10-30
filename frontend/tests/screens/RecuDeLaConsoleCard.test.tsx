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
    expect(screen.getByText('2 sur 5, avant le 5 nov.')).toBeInTheDocument();
    expect(screen.getByText(/Anne Martin/)).toBeInTheDocument();
    expect(screen.getByText('Décideur')).toBeInTheDocument();
    expect(screen.queryByText(/Paul Durand/)).toBeNull();
    expect(screen.getByText(/Relance/)).toHaveTextContent('à faire dans la console · avant le 30 oct.');
    expect(screen.getByText(/4 tâches ouvertes dans la console/)).toHaveTextContent('la plus proche : 29 oct.');
    expect(screen.getByText(/Devis envoyé/)).toHaveTextContent('clôture prévue le 20 nov.');
    expect(screen.getByText('3 e-mails · 1 note')).toBeInTheDocument();
    expect(screen.getByText(MENTION)).toBeInTheDocument();
    expect(screen.getByText(/la relance se valide dans la console d’Axion IA/)).toBeInTheDocument();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByText(/archivé dans la console/)).toBeNull();
  });

  it('signale un client archivé et n’affiche aucun lien', () => {
    render(<RecuDeLaConsoleCard console={{ etape: 'archive', url_console: 'https://secret.example' } as never} />);
    expect(screen.getByText('Ce client est archivé dans la console : il l’est aussi ici.')).toBeInTheDocument();
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.queryByText(/secret\.example/)).toBeNull();
  });
});
