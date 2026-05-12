import { useState, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import {
  AlertOctagon, 
  ShieldCheck, 
  ShieldAlert,
  MapPin, 
  Clock, 
  MessageSquare, 
  Bus, 
  Calendar,
  Wrench,
  Send,
  X,
  UserCheck
} from 'lucide-react';
import { operationsApi } from '../api/operations';
import { studentsApi } from '../api/students';
import { cn } from '../utils/cn';
import { ROLES } from '../config/roles';

const Operation = () => {
  const { user } = useAuth();
  const [activeCommand, setActiveCommand] = useState(null);
  const [groups, setGroups] = useState([]);
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [groupsData, studentsData] = await Promise.all([
          studentsApi.getGroups(),
          studentsApi.getAll()
        ]);
        setGroups(groupsData);
        setStudents(studentsData);
      } catch (error) {
        console.error('Error fetching operations data', error);
      } finally {
        setLoading(false);
      }
    };
    fetchData();
  }, []);

  const commands = [
    { 
      id: 'citar', 
      title: 'Citar acudiente', 
      icon: Calendar, 
      roles: [ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR],
      fields: ['group', 'student', 'date', 'message']
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
      fields: ['description']
    },
    { 
      id: 'solicitud', 
      title: 'Mandar solicitud', 
      icon: Send, 
      roles: Object.values(ROLES),
      fields: ['targetRole', 'message']
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
      roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.RECTOR, ROLES.PSICORIENTADOR],
      fields: ['group', 'student', 'reason', 'timeRange']
    },
    { 
      id: 'incidente', 
      title: 'Reportar incidente', 
      icon: ShieldAlert, 
      roles: [ROLES.DOCENTE, ROLES.PSICORIENTADOR],
      fields: ['student', 'message', 'targets']
    }
  ];

  const filteredCommands = commands.filter(cmd => cmd.roles.includes(user?.role));

  if (loading) return <div className="min-h-[60vh] flex items-center justify-center font-black text-institutional-900 uppercase tracking-widest animate-pulse">Sincronizando datos institucionales...</div>;

  return (
    <div className="space-y-12 animate-in fade-in duration-500">
      <div className="text-center space-y-4">
        <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight italic">Operación Institucional</h2>
        <div className="flex items-center justify-center gap-2">
          <div className="w-12 h-1 bg-institutional-400 rounded-full"></div>
          <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.3em]">Comandos de control y acción</p>
          <div className="w-12 h-1 bg-institutional-400 rounded-full"></div>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-6xl mx-auto">
        {filteredCommands.map((cmd) => (
          <button
            key={cmd.id}
            onClick={() => setActiveCommand(cmd)}
            className={cn(
              "group relative overflow-hidden bg-white dark:bg-slate-900 p-8 rounded-[2.5rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800 transition-all duration-500 hover:scale-[1.02] flex flex-col items-center text-center gap-6",
              cmd.isUrgent && "border-red-100 dark:border-red-900/30 hover:border-red-500 bg-red-50/30 dark:bg-red-900/5"
            )}
          >
            <div className={cn(
              "p-6 rounded-3xl transition-all duration-500 group-hover:scale-110 group-hover:rotate-3",
              cmd.isUrgent 
                ? "bg-red-500 text-white shadow-xl shadow-red-500/20" 
                : "bg-institutional-50 dark:bg-institutional-900/20 text-institutional-900 dark:text-institutional-400 shadow-inner"
            )}>
              <cmd.icon size={40} strokeWidth={1.5} />
            </div>
            <div className="space-y-2">
              <span className="text-xl font-black text-gray-900 dark:text-white uppercase tracking-widest">{cmd.title}</span>
              <p className="text-[10px] text-gray-400 dark:text-slate-500 font-black uppercase tracking-[0.1em]">{cmd.warning ? '⚠️ Acción con notificación global' : 'Comando de sistema'}</p>
            </div>
          </button>
        ))}
      </div>

      {activeCommand && (
        <CommandModal 
          command={activeCommand} 
          onClose={() => setActiveCommand(null)} 
          groups={groups}
          students={students}
        />
      )}
    </div>
  );
};

const CommandModal = ({ command, onClose, groups, students }) => {
  const [step, setStep] = useState(command.warning ? 'warning' : 'form');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitStatus, setSubmitStatus] = useState({ type: '', message: '' });
  const [formData, setFormData] = useState({
    group: '',
    student: '',
    date: '',
    time: '',
    timeStart: '',
    timeEnd: '',
    location: '',
    message: '',
    reason: '',
    targetRole: '',
    description: '',
    targets: []
  });

  const roles = Object.values(ROLES);
  const incidentTargets = [
    { id: 'rector', label: 'Rectoría' },
    { id: 'coordinacion', label: 'Coordinación' },
    { id: 'padre', label: 'Padre de Familia' }
  ];

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsSubmitting(true);
    setSubmitStatus({ type: '', message: '' });
    try {
      let response;
      switch (command.id) {
        case 'sos':
          response = await operationsApi.sos(formData);
          break;
        case 'inasistencia':
          response = await operationsApi.inasistencia(formData);
          break;
        case 'citar':
          response = await operationsApi.citacion(formData);
          break;
        case 'autorizar':
          response = await operationsApi.salida(formData);
          break;
        case 'permiso':
          response = await operationsApi.permiso(formData);
          break;
        case 'solicitud':
          response = await operationsApi.execute('solicitud', formData, '/operations/solicitud');
          break;
        case 'daño':
          response = await operationsApi.execute('daño', formData, '/operations/daño');
          break;
        case 'pedagogica':
          response = await operationsApi.execute('pedagogica', formData, '/operations/pedagogica');
          break;
        case 'horario':
          response = await operationsApi.execute('horario', formData, '/operations/horario');
          break;
        case 'incidente':
          response = await operationsApi.execute('incidente', formData, '/operations/incidente');
          break;
        default:
          throw new Error('Comando no soportado');
      }
      
      onClose();
    } catch (error) {
      setSubmitStatus({ type: 'error', message: error.response?.data?.message || 'Error al ejecutar el comando institucional' });
    } finally {
      setIsSubmitting(false);
    }
  };

  const filteredStudents = students.filter((s) => (s.group || '') === formData.group);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md animate-in fade-in duration-300">
      <div className="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-[3.5rem] shadow-2xl border border-gray-100 dark:border-slate-800 overflow-hidden">
        
        {/* Header del Modal */}
        <div className="px-10 py-8 border-b border-gray-50 dark:border-slate-800/50 flex items-center justify-between">
          <div className="flex items-center gap-4">
            <div className={cn(
              "p-4 rounded-2xl",
              command.isUrgent ? "bg-red-50 text-red-600" : "bg-institutional-50 text-institutional-900"
            )}>
              <command.icon size={24} />
            </div>
            <div>
              <h3 className="text-2xl font-black text-gray-900 dark:text-white uppercase tracking-tight">{command.title}</h3>
              <p className="text-[10px] font-black text-gray-400 uppercase tracking-widest">Configuración de Comando</p>
            </div>
          </div>
          <button onClick={onClose} className="p-3 text-gray-400 hover:text-gray-600 dark:hover:text-white transition-colors">
            <X size={28} />
          </button>
        </div>

        {/* Contenido del Modal */}
        <div className="p-10">
          {step === 'warning' ? (
            <div className="space-y-8 text-center py-6">
              <div className="inline-flex p-6 bg-amber-50 text-amber-600 rounded-full">
                <Clock size={48} />
              </div>
              <div className="space-y-3">
                <h4 className="text-xl font-black text-gray-900 dark:text-white uppercase tracking-tight">¡Atención!</h4>
                <p className="text-gray-500 dark:text-gray-400 font-bold leading-relaxed max-w-md mx-auto">{command.warning}</p>
              </div>
              <div className="flex gap-4">
                <button onClick={onClose} className="flex-1 py-5 rounded-2xl font-black uppercase tracking-widest text-gray-400 hover:text-gray-600 transition-colors text-xs">Cancelar</button>
                <button onClick={() => setStep('form')} className="flex-1 bg-institutional-900 text-white py-5 rounded-2xl font-black uppercase tracking-widest shadow-xl shadow-institutional-900/20 transition-all hover:scale-105 text-xs">Aceptar y Continuar</button>
              </div>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-6">
              
              {/* Selectores de Grupo y Estudiante */}
              {command.fields.includes('group') && (
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Seleccionar Grupo</label>
                  <select 
                    required
                    value={formData.group}
                    onChange={(e) => setFormData({...formData, group: e.target.value, student: ''})}
                    className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all appearance-none"
                  >
                    <option value="">-- Elige un Grupo --</option>
                    {groups.map((g) => {
                      const groupName = g?.name || g?.group_name || '';
                      return (
                        <option key={g?.id || groupName} value={groupName}>
                          {groupName}
                        </option>
                      );
                    })}
                  </select>
                </div>
              )}

              {command.fields.includes('student') && formData.group && (
                <div className="space-y-2 animate-in slide-in-from-top-2 duration-300">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Seleccionar Estudiante</label>
                  <select 
                    required
                    value={formData.student}
                    onChange={(e) => setFormData({...formData, student: e.target.value})}
                    className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all appearance-none"
                  >
                    <option value="">-- Selecciona el Estudiante --</option>
                    {filteredStudents.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
                  </select>
                </div>
              )}

              {/* Otros Campos */}
              {command.fields.includes('targetRole') && (
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Enviar Solicitud a:</label>
                  <select 
                    required
                    value={formData.targetRole}
                    onChange={(e) => setFormData({...formData, targetRole: e.target.value})}
                    className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all appearance-none"
                  >
                    <option value="">-- Seleccionar Rol --</option>
                    {roles.map(r => <option key={r} value={r}>{r}</option>)}
                  </select>
                </div>
              )}

              {command.fields.includes('location') && (
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Ubicación Actual</label>
                  <div className="relative">
                    <MapPin className="absolute left-5 top-1/2 -translate-y-1/2 text-gray-300" size={20} />
                    <input 
                      type="text" required
                      value={formData.location}
                      onChange={(e) => setFormData({...formData, location: e.target.value})}
                      placeholder="Ej: Patio Central, Aula 102..."
                      className="w-full pl-14 pr-6 py-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all"
                    />
                  </div>
                </div>
              )}

              {command.fields.includes('date') && (
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Fecha de Citación</label>
                  <input 
                    type="date" required
                    value={formData.date}
                    onChange={(e) => setFormData({...formData, date: e.target.value})}
                    className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all"
                  />
                </div>
              )}

              {command.fields.includes('time') && (
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Hora de Salida</label>
                  <input 
                    type="time" required
                    value={formData.time}
                    onChange={(e) => setFormData({...formData, time: e.target.value})}
                    className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all"
                  />
                </div>
              )}

              {command.fields.includes('timeRange') && (
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Desde</label>
                    <input type="time" required value={formData.timeStart} onChange={(e) => setFormData({...formData, timeStart: e.target.value})} className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all" />
                  </div>
                  <div className="space-y-2">
                    <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Hasta</label>
                    <input type="time" required value={formData.timeEnd} onChange={(e) => setFormData({...formData, timeEnd: e.target.value})} className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all" />
                  </div>
                </div>
              )}

              {command.fields.includes('targets') && (
                <div className="space-y-3">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Enviar Reporte a (Múltiple):</label>
                  <div className="flex flex-wrap gap-3">
                    {incidentTargets.map(t => (
                      <button
                        key={t.id}
                        type="button"
                        onClick={() => {
                          const newTargets = formData.targets.includes(t.id)
                            ? formData.targets.filter(id => id !== t.id)
                            : [...formData.targets, t.id];
                          setFormData({...formData, targets: newTargets});
                        }}
                        className={cn(
                          "px-6 py-3 rounded-xl text-xs font-black uppercase tracking-widest transition-all border-2",
                          formData.targets.includes(t.id)
                            ? "bg-institutional-900 border-institutional-900 text-white"
                            : "bg-transparent border-gray-100 dark:border-slate-700 text-gray-400 dark:text-slate-500"
                        )}
                      >
                        {t.label}
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {command.fields.includes('reason') || command.fields.includes('message') || command.fields.includes('description') ? (
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Mensaje / Motivo</label>
                  <textarea 
                    value={formData.reason || formData.message || formData.description}
                    onChange={(e) => setFormData({...formData, reason: e.target.value, message: e.target.value, description: e.target.value})}
                    className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all h-32 resize-none"
                    placeholder="Escribe aquí los detalles..."
                  />
                </div>
              ) : null}

              <button 
                type="submit"
                disabled={isSubmitting}
                className={cn(
                  "w-full py-6 rounded-3xl font-black uppercase tracking-[0.2em] text-white shadow-2xl transition-all hover:scale-[1.02] active:scale-95 mt-4 disabled:opacity-50",
                  command.isUrgent ? "bg-red-600 shadow-red-900/20" : "bg-institutional-900 shadow-institutional-900/20"
                )}
              >
                {isSubmitting ? 'Procesando...' : 'Ejecutar Comando'}
              </button>
              {submitStatus.message && (
                <p className={cn(
                  "text-xs font-bold text-center mt-2",
                  submitStatus.type === 'error' ? "text-red-500" : "text-gray-500"
                )}>
                  {submitStatus.message}
                </p>
              )}
            </form>
          )}
        </div>
      </div>
    </div>
  );
};

export default Operation;
