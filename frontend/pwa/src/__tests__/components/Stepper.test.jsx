import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Stepper } from '../../components/ui/Stepper';

describe('Stepper', () => {
  it('renders all step labels', () => {
    render(<Stepper steps={['Datos', 'Confirmar', 'Listo']} current={0} />);
    expect(screen.getByText('Datos')).toBeInTheDocument();
    expect(screen.getByText('Confirmar')).toBeInTheDocument();
    expect(screen.getByText('Listo')).toBeInTheDocument();
  });

  it('shows step numbers starting at 1', () => {
    render(<Stepper steps={['A', 'B']} current={0} />);
    expect(screen.getByText('1')).toBeInTheDocument();
    expect(screen.getByText('2')).toBeInTheDocument();
  });

  it('shows check icon for completed steps', () => {
    render(<Stepper steps={['A', 'B', 'C']} current={2} />);
    // Steps 0 and 1 are done -> should have Check icons (lucide-react svg)
    const items = screen.getAllByRole('listitem');
    // First two items should contain svg (Check icon), third should show "3"
    expect(items[0].querySelector('svg')).toBeInTheDocument();
    expect(items[1].querySelector('svg')).toBeInTheDocument();
    expect(items[2].textContent).toContain('3');
  });

  it('marks current step with aria-current="step"', () => {
    render(<Stepper steps={['A', 'B', 'C']} current={1} />);
    const currentStep = screen.getByText('B');
    expect(currentStep).toHaveAttribute('aria-current', 'step');
  });

  it('does not mark non-current steps with aria-current', () => {
    render(<Stepper steps={['A', 'B', 'C']} current={1} />);
    const stepA = screen.getByText('A');
    expect(stepA).not.toHaveAttribute('aria-current');
  });

  it('renders empty list when no steps', () => {
    const { container } = render(<Stepper steps={[]} />);
    expect(container.querySelector('ol')).toBeInTheDocument();
    expect(container.querySelectorAll('li')).toHaveLength(0);
  });

  it('renders connector line between steps (not after last)', () => {
    const { container } = render(<Stepper steps={['A', 'B', 'C']} current={0} />);
    const connectors = container.querySelectorAll('.h-px.w-6');
    expect(connectors).toHaveLength(2);
  });
});
