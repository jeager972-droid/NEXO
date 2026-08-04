/**
 * groupFormat / NEXO
 * Helper para formatear nombres de grupos: agrega ° y elimina paréntesis redundantes.
 * Ej: "7A" -> "7°A", "6-B" -> "6°B", "11A" -> "11°A"
 */

export const formatGroupName = (name) => {
  if (!name) return '';
  const s = String(name).trim();
  const match = s.match(/^(\d+)[-.\s]*([A-Za-z].*)$/);
  if (match) return `${match[1]}°${match[2]}`;
  return s;
};

export const formatGroupOption = (g) => {
  const raw = g.name || g.group_name || g;
  return formatGroupName(raw);
};
