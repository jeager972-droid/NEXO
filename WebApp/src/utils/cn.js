/**
 * cn utility / NEXO Institucional
 * Responsabilidad: Combinar clases CSS condicionalmente con clsx y resolver conflictos
 * de Tailwind mediante tailwind-merge.
 * Dependencias: clsx, tailwind-merge.
 */
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Utilidad para combinar clases de Tailwind de forma segura
 */
export function cn(...inputs) {
  return twMerge(clsx(inputs));
}
