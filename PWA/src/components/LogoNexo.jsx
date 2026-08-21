/**
 * LogoNexo / NEXO Institucional
 * Logo reutilizable. Puede mostrar la imagen institucional del
 * usuario (public/logo/logo_nexo_app.png) o fallback al SVG NEXO.
 */
import { useState } from 'react';

const LogoNexo = ({ className = 'h-12', showText = true, variant = 'default', useImage = false }) => {
  const [imageFailed, setImageFailed] = useState(false);

  if (useImage && !imageFailed) {
    return (
      <img
        src={`${import.meta.env.BASE_URL}logo/logo_nexo_app.png`}
        alt="NEXO"
        className={className}
        style={{ objectFit: 'contain', objectPosition: 'left center' }}
        onError={() => setImageFailed(true)}
      />
    );
  }

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
