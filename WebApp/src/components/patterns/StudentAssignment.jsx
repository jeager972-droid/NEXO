/**
 * StudentAssignment — UI para asignar estudiantes a grupos manualmente.
 * El rector ve la lista de grupos, hace clic en uno, busca estudiantes
 * por nombre y los asigna al grupo.
 *
 * Filosofía: lenguaje directo, sin tecnicismos. El rector no es programador.
 */
import { useState, useEffect, useCallback, useMemo } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Users, Search, ChevronLeft, Check, X, UserPlus, UserCheck,
  GraduationCap, ArrowLeft,
} from 'lucide-react';
import { studentsApi } from '../../api/students';
import { Card } from '../ui/Card';
import { Button } from '../ui/Button';
import { Badge } from '../ui/Badge';
import { Input } from '../ui/Input';
import { EmptyState } from '../ui/EmptyState';
import { humanizeError } from '../../utils/messages';

const EASE = [0.22, 1, 0.36, 1];

const GRADE_LABELS = {
  '1': 'Primero', '2': 'Segundo', '3': 'Tercero', '4': 'Cuarto',
  '5': 'Quinto', '6': 'Sexto', '7': 'Séptimo', '8': 'Octavo',
  '9': 'Noveno', '10': 'Décimo', '11': 'Once',
};

export const StudentAssignment = ({ onClose }) => {
  const [groups, setGroups] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [selectedGroup, setSelectedGroup] = useState(null);
  const [unassigned, setUnassigned] = useState([]);
  const [search, setSearch] = useState('');
  const [assigning, setAssigning] = useState(null);
  const [toast, setToast] = useState(null);

  const fetchGroups = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const grps = await studentsApi.getGroups();
      setGroups(grps);
    } catch (err) {
      setError(humanizeError(err, 'No pudimos cargar los grupos.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchGroups(); }, [fetchGroups]);

  // Buscar estudiantes sin grupo cuando se selecciona un grupo
  useEffect(() => {
    if (!selectedGroup) return;
    const debounce = setTimeout(async () => {
      try {
        const results = await studentsApi.getUnassigned(search);
        setUnassigned(results);
      } catch (err) {
        console.error('Unassigned search failed:', err);
      }
    }, 300);
    return () => clearTimeout(debounce);
  }, [selectedGroup, search]);

  const handleAssign = async (student) => {
    setAssigning(student.id);
    try {
      await studentsApi.assignGroup(student.id, selectedGroup.id);
      setUnassigned((prev) => prev.filter((s) => s.id !== student.id));
      setGroups((prev) => prev.map((g) =>
        g.id === selectedGroup.id
          ? { ...g, student_count: (parseInt(g.student_count) || 0) + 1 }
          : g
      ));
      setToast({ type: 'success', message: `${student.first_name} ${student.last_name} asignado a ${selectedGroup.name}` });
      setTimeout(() => setToast(null), 3000);
    } catch (err) {
      setToast({ type: 'error', message: humanizeError(err, 'No se pudo asignar el estudiante.') });
      setTimeout(() => setToast(null), 4000);
    } finally {
      setAssigning(null);
    }
  };

  // Vista de grupo seleccionado
  if (selectedGroup) {
    return (
      <div className="space-y-4">
        {/* Header del grupo */}
        <div className="flex items-center justify-between gap-3">
          <button
            onClick={() => { setSelectedGroup(null); setSearch(''); setUnassigned([]); }}
            className="flex items-center gap-2 text-body-sm text-[var(--nx-text-muted)] hover:text-[var(--nx-text)] transition-colors"
          >
            <ChevronLeft size={16} />
            Volver a grupos
          </button>
          <Button variant="ghost" size="sm" leftIcon={<X size={14} />} onClick={onClose}>
            Cerrar
          </Button>
        </div>

        <Card tone="accent" edge className="p-5">
          <div className="flex items-center gap-3">
            <span className="grid h-12 w-12 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
              <GraduationCap size={24} />
            </span>
            <div>
              <h2 className="text-h2 text-[var(--nx-text)]">{selectedGroup.name}</h2>
              <p className="text-body-sm text-[var(--nx-text-muted)]">
                {GRADE_LABELS[selectedGroup.grade_level] || `Grado ${selectedGroup.grade_level}`}
                {' · '}{parseInt(selectedGroup.student_count) || 0} estudiante(s)
              </p>
            </div>
          </div>
        </Card>

        {/* Toast */}
        {toast && (
          <div className={`rounded-control px-4 py-3 text-body-sm ${
            toast.type === 'success'
              ? 'bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)]'
              : 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]'
          }`}>
            {toast.message}
          </div>
        )}

        {/* Buscar estudiantes sin grupo */}
        <div className="space-y-3">
          <Input
            leftIcon={<Search size={16} />}
            placeholder="Buscar estudiante por nombre o documento…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            autoFocus
          />

          <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-3">
            <p className="text-caption text-[var(--nx-text-muted)]">
              <Users size={13} className="inline mr-1" />
              Estos son estudiantes que aún no tienen grupo asignado para este año
            </p>
          </div>

          {/* Lista de estudiantes sin grupo */}
          {unassigned.length === 0 ? (
            <EmptyState
              icon={<UserCheck size={22} strokeWidth={1.75} className="text-[var(--nx-text-muted)]" />}
              title={search ? 'Sin resultados' : 'Todos los estudiantes tienen grupo'}
              description={search
                ? 'No encontramos estudiantes con ese nombre o documento.'
                : 'No hay estudiantes pendientes de asignar. Todos están en un grupo.'}
            />
          ) : (
            <div className="space-y-2 max-h-[50vh] overflow-y-auto">
              <AnimatePresence>
                {unassigned.map((student) => (
                  <motion.div
                    key={student.id}
                    layout
                    initial={{ opacity: 0, y: 4 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, x: -20 }}
                    transition={{ duration: 0.15 }}
                    className="flex items-center justify-between gap-3 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-3"
                  >
                    <div className="min-w-0 flex-1">
                      <p className="text-body-sm text-[var(--nx-text)] font-medium truncate">
                        {student.first_name} {student.last_name}
                      </p>
                      <p className="text-caption text-[var(--nx-text-muted)]">
                        {student.document_number}
                        {student.previous_group && student.previous_group !== 'Sin grupo' && (
                          <span> · Anterior: {student.previous_group}</span>
                        )}
                      </p>
                    </div>
                    <Button
                      variant="quiet"
                      size="sm"
                      loading={assigning === student.id}
                      leftIcon={<UserPlus size={14} />}
                      onClick={() => handleAssign(student)}
                    >
                      Asignar
                    </Button>
                  </motion.div>
                ))}
              </AnimatePresence>
            </div>
          )}
        </div>
      </div>
    );
  }

  // Vista de lista de grupos
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <div>
          <h2 className="text-h2 text-[var(--nx-text)]">Asignar estudiantes a grupos</h2>
          <p className="text-body-sm text-[var(--nx-text-muted)]">Selecciona un grupo para buscar y asignar estudiantes</p>
        </div>
        <Button variant="ghost" size="sm" leftIcon={<X size={14} />} onClick={onClose}>
          Cerrar
        </Button>
      </div>

      {error && (
        <div className="rounded-control bg-[var(--nx-subtle-bg-danger)] px-4 py-3 text-body-sm text-[var(--nx-danger)]">
          {error}
        </div>
      )}

      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="h-24 rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] animate-pulse" />
          ))}
        </div>
      ) : groups.length === 0 ? (
        <EmptyState
          icon={<GraduationCap size={22} strokeWidth={1.75} className="text-[var(--nx-text-muted)]" />}
          title="No hay grupos configurados"
          description="El rector debe completar la configuración de grupos antes de asignar estudiantes."
        />
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
          {groups.map((group) => (
            <button
              key={group.id}
              onClick={() => setSelectedGroup(group)}
              className="flex items-center gap-3 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 text-left transition-all hover:border-[var(--nx-border-accent)] hover:shadow-low"
            >
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
                <GraduationCap size={20} />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-body-sm text-[var(--nx-text)] font-semibold">{group.name}</p>
                <p className="text-caption text-[var(--nx-text-muted)]">
                  {parseInt(group.student_count) || 0} estudiante(s)
                </p>
              </div>
              <ChevronLeft size={16} className="text-[var(--nx-text-muted)] rotate-180" />
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

export default StudentAssignment;
