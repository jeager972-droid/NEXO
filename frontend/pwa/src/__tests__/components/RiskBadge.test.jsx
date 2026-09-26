import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RiskBadge } from '@/components/patterns/RiskBadge';

describe('RiskBadge', () => {
  it('renders the "riesgo" label inside the badge', () => {
    render(<RiskBadge level="alto" />);
    expect(screen.getByText(/riesgo/i)).toBeInTheDocument();
  });

  it('renders the period text when provided', () => {
    render(<RiskBadge level="medio" period="Últimos 30 días" />);
    expect(screen.getByText('Últimos 30 días')).toBeInTheDocument();
  });

  it('does not render period when not provided', () => {
    const { container } = render(<RiskBadge level="medio" />);
    expect(container.querySelector('.text-caption')).not.toBeInTheDocument();
  });

  it('renders the explanation paragraph when provided', () => {
    render(<RiskBadge level="alto" explanation="Falta de asistencia reiterada" />);
    expect(screen.getByText('Falta de asistencia reiterada')).toBeInTheDocument();
  });

  it('does not render explanation when not provided', () => {
    const { container } = render(<RiskBadge level="alto" />);
    expect(container.querySelector('p')).not.toBeInTheDocument();
  });

  it.each([
    ['subiendo', '↑'],
    ['bajando', '↓'],
    ['estable', '→'],
  ])('renders the correct trend icon for trend=%s', (trend, icon) => {
    render(<RiskBadge level="alto" trend={trend} />);
    expect(screen.getByText(icon)).toBeInTheDocument();
  });

  it('does not render trend icon when trend is not provided', () => {
    render(<RiskBadge level="alto" />);
    expect(screen.queryByText('↑')).not.toBeInTheDocument();
    expect(screen.queryByText('↓')).not.toBeInTheDocument();
    expect(screen.queryByText('→')).not.toBeInTheDocument();
  });

  it('does not render trend icon for an unknown trend value', () => {
    render(<RiskBadge level="alto" trend="desconocido" />);
    expect(screen.queryByText('↑')).not.toBeInTheDocument();
  });

  it('maps "critico" level to danger scheme (dot rendered)', () => {
    const { container } = render(<RiskBadge level="critico" />);
    const dot = container.querySelector('span[aria-hidden]');
    expect(dot).toBeInTheDocument();
  });

  it('maps "crítico" (accented) level to danger scheme', () => {
    const { container } = render(<RiskBadge level="crítico" />);
    const dot = container.querySelector('span[aria-hidden]');
    expect(dot).toBeInTheDocument();
  });

  it('falls back to warning scheme for unknown level', () => {
    const { container } = render(<RiskBadge level="desconocido" />);
    // warning scheme still renders a dot
    const dot = container.querySelector('span[aria-hidden]');
    expect(dot).toBeInTheDocument();
  });

  it('handles case-insensitive level matching', () => {
    const { container } = render(<RiskBadge level="ALTO" />);
    const dot = container.querySelector('span[aria-hidden]');
    expect(dot).toBeInTheDocument();
  });

  it('handles undefined level gracefully', () => {
    render(<RiskBadge />);
    expect(screen.getByText(/riesgo/i)).toBeInTheDocument();
  });

  it('applies custom className to the root container', () => {
    const { container } = render(<RiskBadge level="alto" className="my-risk" />);
    expect(container.firstChild.className).toContain('my-risk');
  });

  it('renders both period and explanation together', () => {
    render(<RiskBadge level="alto" period="Semana 12" explanation="Riesgo elevado" />);
    expect(screen.getByText('Semana 12')).toBeInTheDocument();
    expect(screen.getByText('Riesgo elevado')).toBeInTheDocument();
  });
});
