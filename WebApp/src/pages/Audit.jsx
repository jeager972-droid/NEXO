import { useState } from 'react';
import { 
  FileText, 
  Users, 
  ShieldAlert, 
  MessageSquare, 
  Clock, 
  Activity, 
  HardDrive, 
  History, 
  ChevronRight, 
  Search,
  FileSpreadsheet,
  File as FilePdf,
  AlertTriangle,
  X
} from 'lucide-react';

const Audit = () => {
  const [activeSub, setActiveSub] = useState(null);

  const modules = [
    {
      id: 'asistencia',
      title: 'Asistencia',
      icon: Users,
      subdivisions: ['Reporte general', 'Inasistencias', 'Llegadas tarde', 'Evasión interna', 'Por grupo', 'Por estudiante'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'disciplina',
      title: 'Disciplina',
      icon: ShieldAlert,
      subdivisions: ['Incidentes', 'Vulneraciones', 'Intentos salón incorrecto', 'Spam biométrico', 'Reporte disciplinario', 'Historial estudiante'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'permisos',
      title: 'Permisos y Salidas',
      icon: Activity,
      subdivisions: ['Salidas clase', 'Salidas colegio', 'Salidas pedagógicas', 'Retornos pendientes', 'Historial permisos'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'mensajeria',
      title: 'Mensajería',
      icon: MessageSquare,
      subdivisions: ['WhatsApp enviados', 'Respuestas acudientes', 'Mensajes fallidos', 'Citaciones', 'Mensajería interna', 'Historial conversaciones'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'docente',
      title: 'Actividad Docente',
      icon: Clock,
      subdivisions: ['Actividad profesores', 'Clases registradas', 'Permisos emitidos', 'Incidencias asociadas', 'Actividad sistema docente'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'seguridad',
      title: 'Seguridad',
      icon: ShieldAlert,
      subdivisions: ['Auditoría global', 'Accesos', 'Sesiones', 'Comandos ejecutados', 'Actividad administrativa', 'Intentos fallidos'],
      exports: ['Reportes', 'Excel']
    },
    {
      id: 'sos',
      title: 'Alertas SOS',
      icon: AlertTriangle,
      subdivisions: ['Alertas emitidas', 'Alertas resueltas', 'Tiempo resolución', 'Historial SOS'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'historicos',
      title: 'Históricos',
      icon: History,
      subdivisions: ['Histórico estudiante', 'Histórico docente', 'Histórico asistencia', 'Histórico disciplina', 'Histórico permisos', 'Histórico mensajes', 'Buscar histórico', 'Descargar individual', 'Descargar consolidado'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'consolidados',
      title: 'Reportes Consolidados',
      icon: FileText,
      subdivisions: ['Consolidado asistencia', 'Consolidado disciplina', 'Consolidado permisos', 'Consolidado mensajería', 'Consolidado docente', 'Consolidado seguridad', 'Consolidado institucional'],
      exports: ['Excel', 'PDF']
    }
  ];

  return (
    <div className="space-y-12 py-8 animate-in fade-in duration-500">
      <div className="text-center space-y-4">
        <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight italic">Sección de Auditoría</h2>
        <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.3em]">Control institucional de alto nivel</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 max-w-7xl mx-auto">
        {modules.map((mod) => (
          <div 
            key={mod.id}
            className="group bg-white dark:bg-slate-900 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 p-10 hover:border-institutional-400 transition-all duration-500"
          >
            <div className="flex items-center gap-6 mb-8">
              <div className="p-5 rounded-2xl bg-institutional-50 dark:bg-institutional-900/20 text-institutional-900 dark:text-institutional-400 group-hover:scale-110 transition-transform shadow-inner">
                <mod.icon size={32} />
              </div>
              <h3 className="text-2xl font-black text-gray-900 dark:text-white uppercase tracking-tighter">{mod.title}</h3>
            </div>

            <div className="space-y-3 mb-8">
              {mod.subdivisions.map((sub, i) => (
                <button 
                  key={i}
                  onClick={() => setActiveSub(sub)}
                  className="flex items-center justify-between w-full p-4 bg-gray-50 dark:bg-slate-800/50 rounded-2xl text-left hover:bg-institutional-900 hover:text-white transition-all group/item"
                >
                  <span className="text-sm font-bold uppercase tracking-tight dark:text-institutional-400 group-hover/item:text-white transition-colors">{sub}</span>
                  <ChevronRight size={18} className="text-gray-300 group-hover/item:text-white" />
                </button>
              ))}
            </div>

            <div className="flex gap-3 pt-6 border-t border-gray-50 dark:border-slate-800">
              {mod.exports.map((exp, i) => (
                <button
                  key={i}
                  onClick={() => setActiveSub(`${mod.title} - ${exp}`)}
                  className="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl bg-institutional-50 dark:bg-slate-800 text-[10px] font-black uppercase tracking-widest text-institutional-900 dark:text-institutional-400 hover:bg-institutional-900 hover:text-white transition-all"
                >
                  {exp === 'Excel' ? <FileSpreadsheet size={14} /> : exp === 'PDF' ? <FilePdf size={14} /> : <FileText size={14} />}
                  <span>{exp}</span>
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>

      {/* MODAL DE SUBMÓDULO */}
      {activeSub && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md animate-in fade-in duration-300">
          <div className="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-[3.5rem] shadow-2xl border border-gray-100 dark:border-slate-800 overflow-hidden">
            <div className="bg-institutional-900 px-10 py-8 text-white flex items-center justify-between">
              <div className="flex items-center gap-4">
                <div className="p-3 bg-white/10 rounded-2xl">
                  <Search size={24} />
                </div>
                <div>
                  <h3 className="font-black text-2xl uppercase tracking-tight">{activeSub}</h3>
                  <p className="text-[10px] font-black uppercase tracking-widest opacity-60">Módulo de Auditoría</p>
                </div>
              </div>
              <button onClick={() => setActiveSub(null)} className="hover:bg-white/10 p-3 rounded-2xl transition-colors">
                <X size={28} />
              </button>
            </div>
            <div className="p-16 text-center space-y-8">
              <div className="inline-flex p-10 bg-institutional-50 dark:bg-institutional-900/20 text-institutional-900 dark:text-institutional-400 rounded-full shadow-inner">
                <Activity size={80} strokeWidth={1} className="animate-pulse" />
              </div>
              <div className="space-y-3">
                <p className="text-gray-900 dark:text-white text-xl font-black uppercase tracking-widest">Sin datos disponibles</p>
                <p className="text-gray-400 dark:text-slate-500 text-sm font-bold leading-relaxed max-w-md mx-auto">
                  Este módulo aún no tiene endpoint operativo. Cuando exista integración, aquí se verán resultados reales.
                </p>
              </div>
              <button 
                onClick={() => setActiveSub(null)}
                className="w-full bg-institutional-900 text-white py-6 rounded-[2rem] font-black uppercase tracking-widest shadow-xl shadow-institutional-900/20 transition-all hover:scale-105 active:scale-95"
              >
                Regresar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Audit;
