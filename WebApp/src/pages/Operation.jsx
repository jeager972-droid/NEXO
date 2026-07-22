/**
 * Operation page / NEXO Institucional
 * Responsabilidad: Centro de comandos institucionales: citar acudiente, autorizar salida,
 * alerta SOS, permisos pedagógicos, etc. Renderiza formularios dinámicos por comando,
 * carga destinatarios según rol y realiza polling de estado de mensajes.
 * Dependencias: React, react-router-dom, framer-motion, useAuth, operationsApi, studentsApi, usersApi, ROLES.
 */
import { useState, useEffect, useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import {
  AlertOctagon, ShieldCheck, ShieldAlert, AlertTriangle,
  MapPin, Clock, Bus, Calendar,
  Wrench, Send, X, UserCheck, ChevronRight, ChevronDown,
  CheckCircle2, Loader2, FileText,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { operationsApi } from '../api/operations';
import { studentsApi } from '../api/students';
import { usersApi } from '../api/users';
import { ROLES } from '../config/roles';

// Definición estática fuera del componente — evita recreación en cada render
const COMMANDS_CATALOG = [
  {
    id: 'citar',
    title: 'Citar acudiente',
    icon: Calendar,
    roles: [ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR],
    fields: ['group', 'student', 'date', 'time', 'message']
  },
  { 
    id: 'autorizar', 
    title: 'Autorizar salida', 
    icon: ShieldCheck, 
    roles: [ROLES.COORDINADOR, ROLES.RECTOR],
    fields: ['group', 'student', 'reason']
  },
  {
    id: 'sos',
    title: 'SOS',
    icon: AlertOctagon,
    roles: Object.values(ROLES),
    fields: ['location', 'message'],
    isUrgent: true
  },
  { 
    id: 'daño', 
    title: 'Reportar daño', 
    icon: Wrench, 
    roles: [ROLES.AUXILIAR, ROLES.PORTERO],
    fields: ['location', 'description']
  },
  { 
    id: 'solicitud', 
    title: 'Mandar solicitud', 
    icon: Send, 
    roles: Object.values(ROLES),
    fields: ['targetRole', 'message']
  },
  { 
    id: 'seguimiento', 
    title: 'Solicitar seguimiento', 
    icon: FileText, 
    roles: [ROLES.COORDINADOR, ROLES.RECTOR],
    fields: ['group', 'student', 'reason']
  },
  { 
    id: 'pedagogica', 
    title: 'Salida pedagógica', 
    icon: Bus, 
    roles: [ROLES.COORDINADOR, ROLES.RECTOR],
    fields: ['group', 'reason']
  },
  { 
    id: 'horario', 
    title: 'Cambio de horario', 
    icon: Clock, 
    roles: [ROLES.COORDINADOR, ROLES.RECTOR],
    fields: ['group', 'reason', 'time'],
    warning: 'Este comando avisará a todos los padres de familia del grupo elegido.'
  },
  {
    id: 'permiso',
    title: 'Generar permiso',
    icon: UserCheck,
    roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.RECTOR],
    fields: ['group', 'student', 'reason', 'timeRange']
  },
  {
    id: 'incidente',
    title: 'Reportar incidente',
    icon: ShieldAlert,
    roles: [ROLES.DOCENTE, ROLES.PSICORIENTADOR],
    fields: ['group', 'student', 'location', 'message', 'targets']
  }
];

const Operation = () => {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const [activeCommand, setActiveCommand] = useState(null);
  const [groups, setGroups] = useState([]);
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState('');

  const fetchData = useCallback(async (signal) => {
    setLoading(true);
    setFetchError('');
    let groupsOk = false;
    let studentsOk = false;
    try {
      const uRole = user?.role_name || user?.role;
      const isTeacherRole = uRole === ROLES.DOCENTE || uRole === ROLES.PSICORIENTADOR;
      const groupsData = await studentsApi.getGroups(isTeacherRole);
      if (!signal.aborted) {
        setGroups(Array.isArray(groupsData) ? groupsData : []);
        groupsOk = true;
      }
    } catch (error) {
      if (!signal.aborted) {
        console.error('Error fetching groups', error);
        const msg = error?.response?.data?.detail || error?.response?.data?.message || 'Error al obtener grupos';
        setFetchError(prev => prev ? `${prev} | ${msg}` : msg);
      }
    }
    try {
      const allStudents = await studentsApi.getAllPaginated();
      if (!signal.aborted) {
        setStudents(Array.isArray(allStudents) ? allStudents : []);
        studentsOk = true;
      }
    } catch (error) {
      if (!signal.aborted) {
        console.error('Error fetching students', error);
        const msg = error?.response?.data?.detail || error?.response?.data?.message || 'Error al obtener estudiantes';
        setFetchError(prev => prev ? `${prev} | ${msg}` : msg);
      }
    }
    if (!signal.aborted) {
      setLoading(false);
    }
    return { groupsOk, studentsOk };
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    fetchData(controller.signal);
    return () => controller.abort();
  }, [fetchData]);

  const userRole = user?.role;
  // BUG-09 FIX: useMemo evita recracion en cada render y estabiliza la dep del useEffect
  const filteredCommands = useMemo(
    () => COMMANDS_CATALOG.filter(cmd => userRole && cmd.roles.includes(userRole)),
    [userRole]
  );

  useEffect(() => {
    const cmdTitle = searchParams.get('cmd');
    if (cmdTitle) {
      const found = filteredCommands.find(c => c.title === cmdTitle);
      if (found) setActiveCommand(found);
      setSearchParams({}, { replace: true });
    }
  }, [searchParams, setSearchParams, filteredCommands]);

  if (!user) {
    return (
      <div className="space-y-5">
        <div>
          <div className="h-2 w-20 bg-slate-200 dark:bg-slate-800 rounded-sm animate-pulse mb-2" />
          <div className="h-4 w-48 bg-slate-200 dark:bg-slate-800 rounded-sm animate-pulse" />
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {[1,2,3,4,5,6].map(i => (
            <div key={i} className="h-28 bg-slate-100 dark:bg-slate-800/50 animate-pulse" style={{ border: '1.5px solid #E2E8F0' }} />
          ))}
        </div>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="space-y-5">
        <div>
          <div className="h-2 w-20 bg-slate-200 dark:bg-slate-800 rounded-sm animate-pulse mb-2" />
          <div className="h-4 w-48 bg-slate-200 dark:bg-slate-800 rounded-sm animate-pulse" />
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {[1,2,3,4,5,6].map(i => (
            <div key={i} className="h-28 bg-slate-100 dark:bg-slate-800/50 animate-pulse" style={{ border: '1.5px solid #E2E8F0' }} />
          ))}
        </div>
      </div>
    );
  }


  return (
    <div className="space-y-5">
      <div>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none' }}>
          Operación Institucional
        </p>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', marginTop: '2px', letterSpacing: '-0.01em' }}
           className="dark:text-slate-200">
          Comandos de control y acción
        </p>
      </div>

      {fetchError && (
        <div className="p-4 bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold">
          <div className="flex items-start gap-2">
            <AlertTriangle size={16} strokeWidth={2} className="shrink-0 mt-0.5" />
            <div className="flex-1">
              <p className="font-bold mb-0.5">Advertencia de carga de datos</p>
              <p className="opacity-80 font-medium">{fetchError}</p>
            </div>
            <button
              onClick={() => {
                const controller = new AbortController();
                fetchData(controller.signal);
              }}
              className="px-3 py-1.5 bg-amber-100 hover:bg-amber-200 text-amber-900 text-[10px] font-bold uppercase tracking-wider transition-colors shrink-0"
            >
              Reintentar
            </button>
          </div>
        </div>
      )}

      {filteredCommands.length === 0 ? (
        <div className="p-6 bg-slate-50 border border-slate-200 text-slate-500 text-sm font-semibold text-center">
          No tienes comandos disponibles para tu rol actual ({userRole || 'desconocido'}).
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {filteredCommands.map((cmd, i) => (
            <ActionCard key={cmd.id} cmd={cmd} index={i} onClick={() => setActiveCommand(cmd)} />
          ))}
        </div>
      )}

      <AnimatePresence>
        {activeCommand && (
          <CommandDrawer
            command={activeCommand}
            onClose={() => setActiveCommand(null)}
            groups={groups}
            students={students}
          />
        )}
      </AnimatePresence>
    </div>
  );
};

// ── Action Card ───────────────────────────────────────────────────────────────

const ActionCard = ({ cmd, index, onClick }) => {
  const isSOS = cmd.isUrgent;

  return (
    <motion.button
      onClick={onClick}
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.22, delay: index * 0.04, ease: [0.25, 0.46, 0.45, 0.94] }}
      whileTap={{ scale: 0.985 }}
      className="relative overflow-hidden flex items-center gap-4 w-full text-left p-5 transition-colors duration-200 group"
      style={{
        border:          isSOS ? '1.5px solid #7F1D1D' : '1.5px solid #E2E8F0',
        backgroundColor: isSOS ? '#1A0606'             : '#FFFFFF',
      }}
    >
      {/* SOS sonar pulse ring */}
      {isSOS && (
        <motion.div
          className="absolute inset-0 pointer-events-none"
          style={{ border: '2px solid rgba(153,27,27,0.35)' }}
          animate={{ scale: [1, 1.07], opacity: [0.6, 0] }}
          transition={{ duration: 2.2, repeat: Infinity, ease: 'easeOut' }}
        />
      )}

      {/* Icon box */}
      <div
        className="shrink-0 flex items-center justify-center w-10 h-10"
        style={{
          backgroundColor: isSOS ? '#7F1D1D' : 'rgba(0,51,102,0.06)',
          color:           isSOS ? '#FFFFFF' : '#003366',
        }}
      >
        <cmd.icon size={20} strokeWidth={isSOS ? 2.5 : 2} />
      </div>

      {/* Text */}
      <div className="flex-1 min-w-0">
        <p
          className="text-sm font-bold uppercase truncate"
          style={{ letterSpacing: '0.08em', color: isSOS ? '#FCA5A5' : '#1E293B' }}
        >
          {cmd.title}
        </p>
        <p
          className="mt-0.5 truncate"
          style={{ fontSize: '9px', fontWeight: 600, letterSpacing: '0.12em', color: isSOS ? 'rgba(252,165,165,0.55)' : '#94A3B8', textTransform: 'uppercase' }}
        >
          {cmd.warning ? 'Notificación global' : isSOS ? 'Protocolo de emergencia' : 'Comando de sistema'}
        </p>
      </div>

      <ChevronRight
        size={14} strokeWidth={2}
        style={{ color: isSOS ? 'rgba(252,165,165,0.4)' : '#CBD5E1' }}
        className="shrink-0 group-hover:translate-x-0.5 transition-transform"
      />

      {/* Non-SOS hover overlay */}
      {!isSOS && (
        <div className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity duration-150 pointer-events-none"
             style={{ backgroundColor: 'rgba(0,51,102,0.03)' }} />
      )}
    </motion.button>
  );
};

// ── Drawer shared input styles ────────────────────────────────────────────────

const FIELD_LABEL_STYLE = {
  fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em',
  color: '#94A3B8', textTransform: 'uppercase', display: 'block', marginBottom: '6px',
};
const INPUT_BASE = {
  width: '100%', padding: '10px 12px', outline: 'none', fontSize: '13px',
  fontWeight: 500, color: '#0F172A', backgroundColor: '#F8FAFC',
  border: '1.5px solid #E2E8F0', transition: 'border-color 0.2s',
};
const focusBorder  = e => { e.target.style.borderColor = '#003366'; };
const blurBorder   = e => { e.target.style.borderColor = '#E2E8F0'; };

const FormField = ({ label, children }) => (
  <div>
    <label style={FIELD_LABEL_STYLE}>{label}</label>
    {children}
  </div>
);

// ── Unified Combobox Component ──────────────────────────────────────────────────
const Combobox = ({ label, value, onChange, options, placeholder, emptyText, required }) => {
  const [query, setQuery] = useState('');
  const [isOpen, setIsOpen] = useState(false);

  // Filter options based on query
  const filtered = query.trim()
    ? options.filter(o => o.label.toLowerCase().includes(query.trim().toLowerCase()))
    : options;

  const selectedOption = options.find(o => o.value === value);
  const displayValue = isOpen ? query : (selectedOption ? selectedOption.label : '');

  return (
    <div className="relative">
      <label style={FIELD_LABEL_STYLE}>{label}</label>
      <div className="relative">
        <input
          type="text"
          value={displayValue}
          required={required && !value}
          onChange={(e) => {
            setQuery(e.target.value);
            if (!isOpen) setIsOpen(true);
            if (value && e.target.value !== selectedOption?.label) {
              onChange(''); // clear selection if they start typing
            }
          }}
          onFocus={() => { setIsOpen(true); setQuery(''); }}
          onBlur={() => setTimeout(() => setIsOpen(false), 200)}
          placeholder={placeholder}
          className="w-full dark:bg-slate-800 dark:text-white"
          style={{ ...INPUT_BASE, paddingRight: '32px' }}
        />
        <ChevronDown size={14} className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" />
      </div>

      <AnimatePresence>
        {isOpen && (
          <motion.div
            initial={{ opacity: 0, y: -5 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -5 }}
            transition={{ duration: 0.15 }}
            className="absolute z-50 w-full mt-1 bg-white dark:bg-slate-800 shadow-lg max-h-48 overflow-y-auto"
            style={{ border: '1.5px solid #E2E8F0' }}
          >
            {filtered.length === 0 ? (
              <div className="px-4 py-3 text-xs text-slate-500 font-medium">
                {emptyText || 'No hay resultados.'}
              </div>
            ) : (
              filtered.map(opt => (
                <div
                  key={opt.value}
                  onMouseDown={(e) => {
                    e.preventDefault(); // Prevent blur
                    onChange(opt.value);
                    setIsOpen(false);
                  }}
                  className="px-4 py-2.5 text-xs font-semibold text-slate-700 dark:text-slate-200 hover:bg-[#003366] hover:text-white cursor-pointer transition-colors"
                >
                  {opt.label}
                </div>
              ))
            )}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
};

// ── Command Drawer ────────────────────────────────────────────────────────────

const CommandDrawer = ({ command, onClose, groups, students }) => {
  const { user } = useAuth();
  const [step, setStep]               = useState(command.warning ? 'warning' : 'form');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitStatus, setSubmitStatus] = useState({ type: '', message: '' });
  const [pollingStatus, setPollingStatus] = useState('QUEUED');
  const [formData, setFormData]         = useState({
    group: '', student: '', date: '', time: '', timeStart: '', timeEnd: '',
    location: '', message: '', reason: '', targetRole: '', targetUser: '', description: '', targets: [], details: '',
  });

  useEffect(() => {
    setFormData(p => ({ ...p, details: '' }));
  }, [command]);
  const [targetUsers, setTargetUsers]   = useState([]);
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [groupSearch, setGroupSearch]   = useState('');
  const [studentSearch, setStudentSearch] = useState('');

  const roles = Object.values(ROLES);
  const incidentTargets = [
    { id: 'rector',       label: 'Rectoría'        },
    { id: 'coordinacion', label: 'Coordinación'     },
    { id: 'padre',        label: 'Padre de Familia' },
  ];

  const fetchTargetUsers = async (role) => {
    if (!role) { setTargetUsers([]); return; }
    setLoadingUsers(true);
    try {
      const res = await usersApi.getByRole(role, true);
      setTargetUsers(res.data || []);
    } catch (e) {
      setTargetUsers([]);
    } finally {
      setLoadingUsers(false);
    }
  };

  const pollTwilioStatus = async (msgIds) => {
      let attempts = 0;
      const interval = setInterval(async () => {
          try {
              const res = await operationsApi.checkTwilioStatus(msgIds);
              if (res && res.data) {
                  const allSent = res.data.every(m => m.delivery_status === 'SENT' || m.delivery_status === 'DELIVERED' || m.delivery_status === 'READ');
                  const anyFailed = res.data.some(m => m.delivery_status && m.delivery_status.startsWith('FAILED'));
                  if (allSent) {
                      setPollingStatus('SENT');
                      clearInterval(interval);
                      setTimeout(() => onClose(), 3000);
                  } else if (anyFailed) {
                      setPollingStatus('FAILED');
                      clearInterval(interval);
                  }
              }
          } catch (e) {
              console.error(e);
          }
          attempts++;
          if (attempts > 15) {
              clearInterval(interval);
              setPollingStatus('TIMEOUT');
          }
      }, 2000);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsSubmitting(true);
    setSubmitStatus({ type: '', message: '' });
    try {
      const payload = { ...formData };
      if (command.fields.includes('reason')) payload.reason = formData.details;
      if (command.fields.includes('message')) payload.message = formData.details;
      if (command.fields.includes('description')) payload.description = formData.details;
      if (command.id === 'solicitud' && formData.targetUser) {
        payload.recipient_id = formData.targetUser;
      }
      let result;
      switch (command.id) {
        case 'sos':         result = await operationsApi.sos(payload);                                         break;
        case 'citar':       result = await operationsApi.citacion(payload);                                    break;
        case 'autorizar':   result = await operationsApi.salida(payload);                                      break;
        case 'permiso':     result = await operationsApi.permiso(payload);                                     break;
        case 'solicitud':   result = await operationsApi.execute('solicitud',  payload, '/operations/solicitud');  break;
        case 'seguimiento': result = await operationsApi.execute('seguimiento',payload, '/operations/seguimiento'); break;
        case 'daño':        result = await operationsApi.execute('daño',       payload, '/operations/daño');       break;
        case 'pedagogica':  result = await operationsApi.execute('pedagogica', payload, '/operations/pedagogica'); break;
        case 'horario':     result = await operationsApi.execute('horario',    payload, '/operations/horario');    break;
        case 'incidente':   result = await operationsApi.execute('incidente',  payload, '/operations/incidente');  break;
        default: throw new Error('Comando no soportado');
      }
      
      if (['citar', 'permiso', 'autorizar', 'solicitud', 'seguimiento'].includes(command.id)) {
        window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count: 1 } }));
      }

      let msgIds = [];
      if (result && result.delivery) {
          msgIds = result.delivery.map(d => d.message_id).filter(Boolean);
      }
      if (msgIds.length > 0) {
          setStep('polling');
          pollTwilioStatus(msgIds);
      } else {
          onClose();
      }
    } catch (error) {
      // BUG-01 FIX: error.message contiene el mensaje real del backend (lo construye operationsApi.execute)
      setSubmitStatus({ type: 'error', message: error.message || error.response?.data?.message || 'Error al ejecutar el comando institucional' });
    } finally {
      setIsSubmitting(false);
    }
  };

  const set = (field) => (e) => setFormData(prev => ({ ...prev, [field]: e.target.value }));
  const normalize = str => str.replace(/[\s\-]/g, '').toUpperCase();
  const filteredByGroup = (students || []).filter(s => normalize(s.group || '') === normalize(formData.group || ''));
  const filteredStudents = studentSearch.trim()
    ? filteredByGroup.filter(s => (s.name || '').toLowerCase().includes(studentSearch.trim().toLowerCase()))
    : filteredByGroup;
  const filteredGroups = groupSearch.trim()
    ? (groups || []).filter(g => {
        const n = (g?.name || g?.group_name || '').toLowerCase();
        return n.includes(groupSearch.trim().toLowerCase());
      })
    : (groups || []);
  const isSOS = command.isUrgent;
  const accentColor = isSOS ? '#7F1D1D' : '#003366';
  const submitBg    = isSOS ? '#7F1D1D' : '#003366';
  const submitHover = isSOS ? '#991B1B' : '#052955';

  return (
    <>
      {/* Overlay */}
      <motion.div
        key="overlay"
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }}
        className="fixed z-40"
        style={{ top: '52px', left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(2,6,23,0.45)' }}
        onClick={onClose}
      />

      {/* Drawer panel */}
      <motion.div
        key="drawer"
        initial={{ x: '100%' }}
        animate={{ x: 0 }}
        exit={{ x: '100%' }}
        transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
        className="fixed right-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
        style={{ top: '56px', bottom: 0, maxWidth: '440px', borderLeft: '1.5px solid #E2E8F0' }}
      >
        {/* Drawer header */}
        <div
          className="shrink-0 flex items-center justify-between px-6 py-4"
          style={{ borderBottom: '1.5px solid #F1F5F9' }}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex items-center justify-center w-9 h-9 shrink-0"
              style={{ backgroundColor: isSOS ? '#7F1D1D' : 'rgba(0,51,102,0.08)', color: isSOS ? '#FCA5A5' : '#003366' }}
            >
              <command.icon size={18} strokeWidth={2.5} />
            </div>
            <div>
              <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>
                {command.title}
              </p>
              <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                Configuración de Comando
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <X size={18} strokeWidth={2} />
          </button>
        </div>

        {/* Drawer body */}
        <div className="flex-1 overflow-y-auto p-6">
          {step === 'polling' ? (
              <div className="flex flex-col items-center justify-center h-full space-y-6 text-center">
                  {pollingStatus === 'QUEUED' && (
                      <motion.div initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} className="flex flex-col items-center">
                          <Loader2 size={48} className="animate-spin mx-auto mb-4" style={{ color: accentColor }} />
                          <div>
                              <p className="text-lg font-bold text-slate-800 dark:text-white">Enviando mensaje...</p>
                              <p className="text-xs text-slate-500 mt-2 font-medium">Puede tardar unos segundos. Por favor espera.</p>
                          </div>
                      </motion.div>
                  )}
                  {pollingStatus === 'SENT' && (
                      <motion.div initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} className="flex flex-col items-center">
                          <CheckCircle2 size={56} className="text-green-500 mx-auto mb-4" />
                          <div>
                              <p className="text-xl font-bold text-slate-800 dark:text-white">¡Mensaje Enviado!</p>
                              <p className="text-xs text-slate-500 mt-2 font-medium">El mensaje fue entregado exitosamente.</p>
                          </div>
                      </motion.div>
                  )}
                  {(pollingStatus === 'FAILED' || pollingStatus === 'TIMEOUT') && (
                      <motion.div initial={{ opacity: 0, scale: 0.9 }} animate={{ opacity: 1, scale: 1 }} className="flex flex-col items-center">
                          <AlertTriangle size={56} className="text-red-500 mx-auto mb-4" />
                          <div>
                              <p className="text-lg font-bold text-slate-800 dark:text-white">Aviso de envío</p>
                              <p className="text-xs text-slate-500 mt-2 font-medium max-w-[250px] mx-auto leading-relaxed">
                                {pollingStatus === 'TIMEOUT' ? 'El sistema está demorando más de lo normal en enviar. Se enviará en segundo plano.' : 'No se pudo verificar el estado del envío. Revisa tu historial de mensajes.'}
                              </p>
                          </div>
                          <button onClick={onClose} className="mt-6 px-6 py-2.5 bg-slate-100 dark:bg-slate-800 dark:text-white text-slate-700 hover:bg-slate-200 font-bold uppercase text-[10px] tracking-widest rounded transition-colors">
                              Cerrar
                          </button>
                      </motion.div>
                  )}
              </div>
          ) : step === 'warning' ? (
            <div className="space-y-6 py-4">
              <div
                className="flex items-start gap-3 p-4"
                style={{ border: '1.5px solid #FEF3C7', backgroundColor: '#FFFBEB' }}
              >
                <Clock size={16} strokeWidth={2} className="text-amber-500 shrink-0 mt-0.5" />
                <p className="text-sm font-semibold text-amber-700 leading-snug">{command.warning}</p>
              </div>
              <div className="flex gap-3">
                <button
                  onClick={onClose}
                  className="flex-1 py-3 text-xs font-bold uppercase text-slate-400 hover:text-slate-600 dark:hover:text-white transition-colors"
                  style={{ border: '1.5px solid #E2E8F0', letterSpacing: '0.15em' }}
                >
                  Cancelar
                </button>
                <button
                  onClick={() => setStep('form')}
                  className="flex-1 py-3 text-xs font-bold uppercase text-white transition-colors"
                  style={{ backgroundColor: accentColor, letterSpacing: '0.15em' }}
                >
                  Aceptar y Continuar
                </button>
              </div>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-5">
              {command.fields.includes('group') && (
                <Combobox
                  label="Grupo"
                  placeholder="Buscar y seleccionar grupo…"
                  value={formData.group}
                  required
                  onChange={(val) => setFormData(p => ({ ...p, group: val, student: '' }))}
                  options={(groups || []).map(g => ({ value: g?.name || g?.group_name || '', label: g?.name || g?.group_name || '' }))}
                  emptyText="No hay grupos disponibles."
                />
              )}

              {command.fields.includes('student') && formData.group && (
                <Combobox
                  label="Estudiante"
                  placeholder="Buscar y seleccionar estudiante…"
                  value={formData.student}
                  required
                  onChange={(val) => setFormData(p => ({ ...p, student: val }))}
                  options={filteredByGroup.map(s => ({ value: s.id, label: s.name }))}
                  emptyText="No hay estudiantes en este grupo."
                />
              )}

              {command.fields.includes('targetRole') && (
                <>
                  <FormField label="Rol destinatario">
                    <select required value={formData.targetRole}
                      onChange={e => {
                        const role = e.target.value;
                        setFormData(p => ({ ...p, targetRole: role, targetUser: '' }));
                        fetchTargetUsers(role);
                      }}
                      className="dark:bg-slate-800 dark:text-white appearance-none"
                      style={INPUT_BASE} onFocus={focusBorder} onBlur={blurBorder}>
                      <option value="">— Seleccionar rol —</option>
                      {roles.map(r => <option key={r} value={r}>{r}</option>)}
                    </select>
                  </FormField>

                  {formData.targetRole && targetUsers.length > 0 && (
                    <FormField label={`Personal de ${formData.targetRole} (${targetUsers.length})`}>
                      <div className="space-y-1 max-h-48 overflow-auto">
                        {targetUsers.map(u => (
                          <button key={u.user_id} type="button"
                            onClick={() => setFormData(p => ({ ...p, targetUser: u.user_id }))}
                            className={`w-full flex items-center gap-3 px-3 py-2 text-left text-xs transition-colors ${
                              formData.targetUser === u.user_id
                                ? 'bg-[#003366] text-white'
                                : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700'
                            }`}
                            style={{ border: '1.5px solid #E2E8F0' }}>
                            <div className="shrink-0 w-7 h-7 flex items-center justify-center text-[10px] font-black text-white overflow-hidden" style={{ backgroundColor: formData.targetUser === u.user_id ? 'rgba(255,255,255,0.2)' : '#003366' }}>
                              {u.profile_photo_url ? (
                                <img src={u.profile_photo_url} alt="" className="w-full h-full object-cover" />
                              ) : (
                                `${u.first_name?.charAt(0) ?? ''}${u.last_name?.charAt(0) ?? ''}`
                              )}
                            </div>
                            <div className="flex-1 min-w-0">
                              <p className="font-semibold truncate">{u.last_name} {u.first_name}</p>
                              <p className="text-[10px] opacity-70 truncate">{u.email}</p>
                            </div>
                            {formData.targetUser === u.user_id && (
                              <CheckCircle2 size={14} className="shrink-0" />
                            )}
                          </button>
                        ))}
                      </div>
                    </FormField>
                  )}
                  {formData.targetRole && !loadingUsers && targetUsers.length === 0 && (
                    <div className="px-3 py-2 text-xs text-slate-400 bg-slate-50 dark:bg-slate-800" style={{ border: '1.5px solid #E2E8F0' }}>
                      No hay personal de {formData.targetRole} en tu misma jornada.
                    </div>
                  )}
                  {loadingUsers && (
                    <div className="flex items-center gap-2 px-3 py-2 text-xs text-slate-400">
                      <Loader2 size={12} className="animate-spin" /> Cargando personal…
                    </div>
                  )}
                </>
              )}

              {command.fields.includes('location') && (
                <FormField label="Ubicación">
                  <div className="relative">
                    <MapPin size={14} strokeWidth={2} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none" />
                    <input type="text" required value={formData.location} onChange={set('location')}
                      placeholder="Patio Central, Aula 102…"
                      className="dark:bg-slate-800 dark:text-white"
                      style={{ ...INPUT_BASE, paddingLeft: '32px' }}
                      onFocus={focusBorder} onBlur={blurBorder} />
                  </div>
                </FormField>
              )}

              {command.fields.includes('date') && (
                <FormField label="Fecha">
                  <input type="date" required value={formData.date} onChange={set('date')}
                    className="dark:bg-slate-800 dark:text-white"
                    style={INPUT_BASE} onFocus={focusBorder} onBlur={blurBorder} />
                </FormField>
              )}

              {command.fields.includes('time') && (
                <FormField label="Hora">
                  <input type="time" required value={formData.time} onChange={set('time')}
                    className="dark:bg-slate-800 dark:text-white"
                    style={INPUT_BASE} onFocus={focusBorder} onBlur={blurBorder} />
                </FormField>
              )}

              {command.fields.includes('timeRange') && (
                <div className="grid grid-cols-2 gap-3">
                  <FormField label="Desde">
                    <input type="time" required value={formData.timeStart} onChange={set('timeStart')}
                      className="dark:bg-slate-800 dark:text-white"
                      style={INPUT_BASE} onFocus={focusBorder} onBlur={blurBorder} />
                  </FormField>
                  <FormField label="Hasta">
                    <input type="time" required value={formData.timeEnd} onChange={set('timeEnd')}
                      className="dark:bg-slate-800 dark:text-white"
                      style={INPUT_BASE} onFocus={focusBorder} onBlur={blurBorder} />
                  </FormField>
                </div>
              )}

              {command.fields.includes('targets') && (
                <FormField label="Enviar reporte a">
                  <div className="flex flex-wrap gap-2">
                    {[
                      { id: 'rector',       label: 'Rectoría'        },
                      { id: 'coordinacion', label: 'Coordinación'     },
                      { id: 'padre',        label: 'Padre de Familia' },
                    ].map(t => (
                      <button key={t.id} type="button"
                        onClick={() => setFormData(p => ({
                          ...p, targets: p.targets.includes(t.id)
                            ? p.targets.filter(id => id !== t.id)
                            : [...p.targets, t.id]
                        }))}
                        className="px-3 py-1.5 text-xs font-bold uppercase transition-colors"
                        style={{
                          letterSpacing: '0.12em',
                          border: '1.5px solid',
                          borderColor:     formData.targets.includes(t.id) ? '#003366' : '#E2E8F0',
                          backgroundColor: formData.targets.includes(t.id) ? '#003366' : 'transparent',
                          color:           formData.targets.includes(t.id) ? '#FFFFFF' : '#94A3B8',
                        }}>
                        {t.label}
                      </button>
                    ))}
                  </div>
                </FormField>
              )}

              {(command.fields.includes('reason') || command.fields.includes('message') || command.fields.includes('description')) && (
                <FormField label="Detalles">
                  <textarea
                    value={formData.details || ''}
                    onChange={e => setFormData(p => ({ ...p, details: e.target.value }))}
                    required={['citar', 'permiso', 'incidente'].includes(command.id)}
                    placeholder="Escribe aquí los detalles…"
                    rows={4}
                    className="resize-none dark:bg-slate-800 dark:text-white"
                    style={INPUT_BASE}
                    onFocus={focusBorder} onBlur={blurBorder}
                  />
                </FormField>
              )}

              {submitStatus.message && (
                <p className="text-xs font-semibold text-red-500">{submitStatus.message}</p>
              )}

              <motion.button
                type="submit"
                disabled={isSubmitting}
                whileTap={!isSubmitting ? { scale: 0.985 } : {}}
                whileHover={!isSubmitting ? { backgroundColor: submitHover } : {}}
                className="w-full py-3.5 text-sm font-bold uppercase text-white transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                style={{ backgroundColor: submitBg, letterSpacing: '0.15em' }}
              >
                {isSubmitting ? (
                  <span className="flex items-center justify-center gap-2">
                    <svg className="animate-spin h-4 w-4 text-white/70" viewBox="0 0 24 24" fill="none">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2.5"/>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    Procesando comando…
                  </span>
                ) : 'Ejecutar Comando'}
              </motion.button>
            </form>
          )}
        </div>
      </motion.div>
    </>
  );
};

export default Operation;
