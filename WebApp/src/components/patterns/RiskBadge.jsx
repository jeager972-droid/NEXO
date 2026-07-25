/**
 * CMP-103 Indicador de riesgo
 * Nivel + tendencia + evidencia + periodo.
 */
import React from 'react';
import { clsx } from 'clsx';
import { Badge } from '../ui/Badge';

const levelToScheme = {
  bajo: 'success',
  normal: 'success',
  medio: 'warning',
  alto: 'warning',
  critico: 'danger',
  crítico: 'danger',
};

const trendIcon = {
  subiendo: '↑',
  bajando: '↓',
  estable: '→',
};

export const RiskBadge = ({ level, trend, period, explanation, className }) => {
  const scheme = levelToScheme[level?.toLowerCase()] || 'neutral';
  return (
    <div className={clsx('space-y-1', className)}>
      <div className="flex items-center gap-2">
        <Badge scheme={scheme} dot>
          {level || 'Sin riesgo'}
          {trend && trendIcon[trend] && <span className="ml-1">{trendIcon[trend]}</span>}
        </Badge>
        {period && <span className="text-caption text-[var(--nx-text-muted)]">{period}</span>}
      </div>
      {explanation && <p className="text-body-sm text-[var(--nx-text-muted)]">{explanation}</p>}
    </div>
  );
};
