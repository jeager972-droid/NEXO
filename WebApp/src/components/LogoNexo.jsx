import React from 'react';

const LogoNexo = ({ className = "h-12", showText = true }) => {
  return (
    <div className={`flex items-center gap-3 ${className}`}>
      {/* Representación SVG del Icono NEXO basado en la imagen */}
      <svg 
        viewBox="0 0 100 100" 
        className="h-full aspect-square"
        fill="none" 
        xmlns="http://www.w3.org/2000/svg"
      >
        <path 
          d="M20 30C20 24.4772 24.4772 20 30 20H70C75.5228 20 80 24.4772 80 30V70C80 75.5228 75.5228 80 70 80H30C24.4772 80 20 75.5228 20 70V30Z" 
          className="fill-institutional-900"
        />
        <path 
          d="M35 35L50 50L65 35V65L50 50L35 65V35Z" 
          className="fill-white opacity-90"
        />
        <circle cx="50" cy="15" r="5" className="fill-institutional-600" />
      </svg>
      
      {showText && (
        <span className="text-2xl font-black tracking-tighter text-institutional-900 dark:text-white">
          NEXO
        </span>
      )}
    </div>
  );
};

export default LogoNexo;
