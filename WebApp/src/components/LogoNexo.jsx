import React from 'react';

const LogoNexo = ({ className = "h-12", showText = true, variant = 'default' }) => {
  const isLight = variant === 'light';

  return (
    <div className={`flex items-center gap-3 ${className}`} style={{ height: undefined }}>
      <svg
        viewBox="0 0 48 48"
        className="h-full w-auto"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        aria-label="NEXO"
        role="img"
      >
        {/* Outer frame — architectural square with clipped corner (top-right) */}
        <path
          d="M6 6 H36 L42 12 V42 H6 Z"
          fill={isLight ? '#FFFFFF' : '#003366'}
          strokeWidth="0"
        />

        {/* Inner accent bar — vertical left */}
        <rect x="10" y="10" width="3" height="28" fill={isLight ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.15)'} rx="0.5" />

        {/* Main nexus mark — two diagonal strokes forming an X with a midpoint break */}
        <line x1="17" y1="14" x2="31" y2="34" stroke="white" strokeWidth="2.2" strokeLinecap="square" />
        <line x1="31" y1="14" x2="17" y2="34" stroke="white" strokeWidth="2.2" strokeLinecap="square" />

        {/* Center gap — surgical white rectangle to visually break the X at center */}
        <rect x="21.5" y="21.5" width="5" height="5" fill={isLight ? '#FFFFFF' : '#003366'} />

        {/* Biometric accent node — emerald dot at intersection */}
        <circle cx="24" cy="24" r="2.2" fill="#00A67E" />

        {/* Clipped corner detail — top-right notch line */}
        <line x1="36" y1="6" x2="42" y2="12" stroke={isLight ? 'rgba(255,255,255,0.4)' : 'rgba(0,166,126,0.5)'} strokeWidth="1.5" strokeLinecap="butt" />
      </svg>

      {showText && (
        <span
          className="font-sans font-black tracking-widest uppercase"
          style={{
            fontSize: '1.15em',
            letterSpacing: '0.18em',
            color: isLight ? '#FFFFFF' : '#003366',
            lineHeight: 1,
          }}
        >
          NEXO
        </span>
      )}
    </div>
  );
};

export default LogoNexo;
