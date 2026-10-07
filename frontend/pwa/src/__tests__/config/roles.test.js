import { describe, it, expect } from 'vitest';
import {
  ROLES,
  SIDEBAR_ITEMS,
  OPERATION_COMMANDS,
  ROLE_DISPLAY,
  getOperationsForRole,
  getRoleDisplay,
  getPrimaryActions,
  getSecondaryActions,
} from '../../config/roles';

describe('ROLES constants', () => {
  it('exports all expected roles with correct backend values', () => {
    expect(ROLES.RECTOR).toBe('RECTOR');
    expect(ROLES.COORDINADOR).toBe('COORDINATOR');
    expect(ROLES.DOCENTE).toBe('TEACHER');
    expect(ROLES.SECRETARIA).toBe('SECRETARY');
    expect(ROLES.PORTERO).toBe('SECURITY');
    expect(ROLES.AUXILIAR).toBe('AUXILIARY');
    expect(ROLES.PSICORIENTADOR).toBe('COUNSELOR');
  });

  it('has exactly 7 roles', () => {
    expect(Object.keys(ROLES)).toHaveLength(7);
  });
});

describe('getOperationsForRole', () => {
  it('returns situacion_critica for all roles (solicitud ya no existe)', () => {
    const roles = Object.values(ROLES);
    for (const role of roles) {
      const ops = getOperationsForRole(role);
      const ids = ops.map((o) => o.id);
      expect(ids).toContain('situacion_critica');
      expect(ids).not.toContain('solicitud');
    }
  });

  it('returns citar for RECTOR, COORDINATOR, DOCENTE, PSICORIENTADOR, SECRETARIA', () => {
    for (const role of [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR, ROLES.SECRETARIA]) {
      const ops = getOperationsForRole(role);
      expect(ops.some((o) => o.id === 'citar')).toBe(true);
    }
  });

  it('does not return citar for SECURITY, AUXILIARY', () => {
    for (const role of [ROLES.PORTERO, ROLES.AUXILIAR]) {
      const ops = getOperationsForRole(role);
      expect(ops.some((o) => o.id === 'citar')).toBe(false);
    }
  });

  it('returns autorizar only for RECTOR and COORDINATOR', () => {
    expect(getOperationsForRole(ROLES.RECTOR).some((o) => o.id === 'autorizar')).toBe(true);
    expect(getOperationsForRole(ROLES.COORDINADOR).some((o) => o.id === 'autorizar')).toBe(true);
    expect(getOperationsForRole(ROLES.DOCENTE).some((o) => o.id === 'autorizar')).toBe(false);
  });

  it('returns daño for SECURITY, AUXILIARY, RECTOR and COORDINATOR', () => {
    expect(getOperationsForRole(ROLES.PORTERO).some((o) => o.id === 'daño')).toBe(true);
    expect(getOperationsForRole(ROLES.AUXILIAR).some((o) => o.id === 'daño')).toBe(true);
    expect(getOperationsForRole(ROLES.RECTOR).some((o) => o.id === 'daño')).toBe(true);
    expect(getOperationsForRole(ROLES.COORDINADOR).some((o) => o.id === 'daño')).toBe(true);
    expect(getOperationsForRole(ROLES.DOCENTE).some((o) => o.id === 'daño')).toBe(false);
  });

  it('horario y pedagogica son exclusivas de RECTOR y COORDINATOR', () => {
    for (const op of ['horario', 'pedagogica']) {
      expect(getOperationsForRole(ROLES.RECTOR).some((o) => o.id === op)).toBe(true);
      expect(getOperationsForRole(ROLES.COORDINADOR).some((o) => o.id === op)).toBe(true);
      for (const role of [ROLES.DOCENTE, ROLES.SECRETARIA, ROLES.PSICORIENTADOR, ROLES.PORTERO, ROLES.AUXILIAR]) {
        expect(getOperationsForRole(role).some((o) => o.id === op)).toBe(false);
      }
    }
  });
});

describe('getRoleDisplay', () => {
  it('returns display name for each role', () => {
    expect(getRoleDisplay(ROLES.RECTOR)).toBe('Rector');
    expect(getRoleDisplay(ROLES.COORDINADOR)).toBe('Coordinador');
    expect(getRoleDisplay(ROLES.DOCENTE)).toBe('Docente');
    expect(getRoleDisplay(ROLES.SECRETARIA)).toBe('Secretaria');
    expect(getRoleDisplay(ROLES.PORTERO)).toBe('Portero');
    expect(getRoleDisplay(ROLES.AUXILIAR)).toBe('Auxiliar');
    expect(getRoleDisplay(ROLES.PSICORIENTADOR)).toBe('Psicorientador');
  });

  it('returns the raw role string for unknown roles', () => {
    expect(getRoleDisplay('UNKNOWN_ROLE')).toBe('UNKNOWN_ROLE');
  });
});

describe('getPrimaryActions', () => {
  it('returns 4 actions for RECTOR con insignia de operación y sin /casos', () => {
    const actions = getPrimaryActions(ROLES.RECTOR);
    expect(actions).toHaveLength(4);
    const paths = actions.map((a) => a.path);
    expect(paths).toContain('/');
    expect(paths).toContain('/operacion?cmd=Emergencia');
    expect(paths).toContain('/notificaciones');
    // /casos vive en el sidebar, no en la barra inferior
    expect(paths).not.toContain('/casos');
    // el deep-link hereda título/icono del catálogo
    const flagship = actions.find((a) => a.path.includes('cmd='));
    expect(flagship.title).toBe('Emergencia');
  });

  it('returns 4 actions for SECRETARY including enrolamiento', () => {
    const actions = getPrimaryActions(ROLES.SECRETARIA);
    expect(actions).toHaveLength(4);
    const paths = actions.map((a) => a.path);
    expect(paths).toContain('/enrolamiento');
  });

  it('returns default actions for unknown role', () => {
    const actions = getPrimaryActions('UNKNOWN');
    expect(actions).toHaveLength(4);
    const paths = actions.map((a) => a.path);
    expect(paths).toContain('/');
    expect(paths).toContain('/operacion');
  });
});

describe('getSecondaryActions', () => {
  it('excludes primary actions from the result', () => {
    const primary = getPrimaryActions(ROLES.RECTOR);
    const primaryPaths = primary.map((a) => a.path);
    const secondary = getSecondaryActions(ROLES.RECTOR);
    const secondaryPaths = secondary.map((a) => a.path);

    for (const p of primaryPaths) {
      expect(secondaryPaths).not.toContain(p);
    }
  });

  it('returns perfil as secondary for RECTOR (not in primary)', () => {
    const secondary = getSecondaryActions(ROLES.RECTOR);
    const paths = secondary.map((a) => a.path);
    expect(paths).toContain('/perfil');
  });
});

describe('SIDEBAR_ITEMS', () => {
  it('all items have title, path, icon, and roles', () => {
    for (const item of SIDEBAR_ITEMS) {
      expect(item.title).toBeTruthy();
      expect(item.path).toBeTruthy();
      expect(item.icon).toBeDefined();
      expect(item.roles).toBeInstanceOf(Array);
      expect(item.roles.length).toBeGreaterThan(0);
    }
  });

  it('enrolamiento is only for SECRETARY', () => {
    const enr = SIDEBAR_ITEMS.find((i) => i.path === '/enrolamiento');
    expect(enr.roles).toEqual([ROLES.SECRETARIA]);
  });
});
