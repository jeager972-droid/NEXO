/**
 * LogoNexo / NEXO Institucional
 * Logo SVG reutilizable con color controlado por token semántico.
 */
const LogoNexo = ({ className = 'h-12', showText = true, variant = 'default' }) => {
  const color = variant === 'light' ? 'var(--nx-canvas)' : 'var(--nx-text)';
  const accent = 'var(--nx-accent)';
  return (
    <svg className={className} viewBox="0 0 140 36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="NEXO">
      <circle cx="18" cy="18" r="14" fill={accent} />
      <path d="M12 18L18 12L24 18L18 24L12 18Z" fill="var(--nx-accent-text)" />
      {showText && (
        <text x="40" y="24" fill={color} fontFamily="Inter, sans-serif" fontSize="20" fontWeight="800" letterSpacing="0.08em">NEXO</text>
      )}
    </svg>
  );
};

export default LogoNexo;
