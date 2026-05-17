import { useState } from 'react';
import {
  FileText, Users, ShieldAlert, MessageSquare,
  Clock, Activity, History, ChevronRight,
  FileSpreadsheet, File as FilePdf, AlertTriangle, X,
  Lock, Unlock, ShieldCheck,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

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

  const CHAIN = [
    { id: 1, ts: '08:41:22.101', event: 'SESION_INICIADA',    user: 'rector@nexo.edu',       hash: 'a3f2e1b4c7d8', valid: true  },
    { id: 2, ts: '08:41:23.891', event: 'CONSULTA_REGISTRO',  user: 'rector@nexo.edu',       hash: '9b7c3d4f2a1e', valid: true  },
    { id: 3, ts: '08:52:11.344', event: 'COMANDO_SOS',        user: 'portero01@nexo.edu',    hash: 'e5d8a2c1f9b3', valid: true  },
    { id: 4, ts: '09:03:55.780', event: 'REGISTRO_ESTUDIANTE',user: 'secre01@nexo.edu',      hash: '2f4b7e9c1d6a', valid: true  },
    { id: 5, ts: '09:17:33.211', event: 'ACCESO_FALLIDO',     user: 'unknown@external.com',  hash: '7c1d9b3e5f4a', valid: false },
    { id: 6, ts: '09:22:44.509', event: 'PERMISO_EMITIDO',    user: 'coord01@nexo.edu',      hash: '1b8f4a6c3e2d', valid: true  },
    { id: 7, ts: '09:31:08.115', event: 'EXPORTACION_PDF',    user: 'rector@nexo.edu',       hash: '5e9a2c7b1f3d', valid: true  },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none' }}>Auditoría</p>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', marginTop: '2px' }} className="dark:text-slate-200">Control institucional de alto nivel</p>
      </div>

      {/* ── Audit Chain Terminal ── */}
      <section>
        <div className="flex items-center justify-between mb-3">
          <div>
            <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase' }}>Audit Chain</p>
            <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366' }} className="dark:text-slate-200">Log de seguridad criptográfico</p>
          </div>
          <div className="flex items-center gap-1.5">
            <ShieldCheck size={14} strokeWidth={2} style={{ color: '#00A67E' }} />
            <span style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#00A67E', textTransform: 'uppercase' }}>SHA-256</span>
          </div>
        </div>

        <div className="overflow-x-auto" style={{ backgroundColor: '#070D1B', border: '1.5px solid #1E293B' }}>
          {/* Terminal header */}
          <div className="flex items-center gap-1.5 px-4 py-2.5" style={{ borderBottom: '1px solid #1E293B' }}>
            {['#DC2626','#D97706','#00A67E'].map(c => (
              <span key={c} className="block w-2.5 h-2.5 rounded-full" style={{ backgroundColor: c, opacity: 0.7 }} />
            ))}
            <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#475569', marginLeft: '8px', letterSpacing: '0.1em' }}>
              nexo-audit-chain — bash
            </span>
          </div>

          {/* Log entries */}
          <div className="min-w-[700px]">
            {CHAIN.map((entry, i) => (
              <motion.div
                key={entry.id}
                initial={{ opacity: 0, x: -6 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ delay: i * 0.06, duration: 0.2 }}
                className="flex items-center gap-0 px-4 py-2.5 hover:bg-white/[0.03] transition-colors"
                style={{ borderBottom: i < CHAIN.length - 1 ? '1px solid rgba(30,41,59,0.5)' : 'none' }}
              >
                {/* Seq */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#334155', width: '28px', flexShrink: 0 }}>
                  {String(entry.id).padStart(3, '0')}
                </span>
                {/* Timestamp */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '11px', color: '#00A67E', width: '100px', flexShrink: 0 }}>
                  [{entry.ts}]
                </span>
                {/* Event */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '11px', color: entry.valid ? '#F1F5F9' : '#FCA5A5', width: '200px', flexShrink: 0, letterSpacing: '0.05em' }}>
                  {entry.event}
                </span>
                {/* User */}
                <span className="truncate" style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#64748B', width: '180px', flexShrink: 0 }}>
                  {entry.user}
                </span>
                {/* Hash */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#334155', width: '110px', flexShrink: 0 }}>
                  {entry.hash}…
                </span>
                {/* Valid indicator */}
                <div className="flex items-center gap-1.5 shrink-0">
                  {entry.valid
                    ? <Lock size={12} strokeWidth={2.5} style={{ color: '#00A67E' }} />
                    : <Unlock size={12} strokeWidth={2.5} style={{ color: '#DC2626' }} />
                  }
                  <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '9px', fontWeight: 700,
                    color: entry.valid ? '#00A67E' : '#DC2626', letterSpacing: '0.1em' }}>
                    {entry.valid ? 'OK' : 'INVÁLIDO'}
                  </span>
                </div>
              </motion.div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Module grid ── */}
      <section>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', marginBottom: '12px', userSelect: 'none' }}>
          Módulos de Reporte
        </p>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {modules.map((mod, idx) => (
            <motion.div key={mod.id}
              initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.2, delay: idx * 0.03 }}
              className="bg-white dark:bg-slate-900"
              style={{ border: '1.5px solid #E2E8F0' }}
            >
              {/* Module header */}
              <div className="flex items-center justify-between px-4 py-3" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
                <div className="flex items-center gap-2.5">
                  <div className="flex items-center justify-center w-7 h-7" style={{ backgroundColor: 'rgba(0,51,102,0.07)', color: '#003366' }}>
                    <mod.icon size={14} strokeWidth={2} />
                  </div>
                  <p className="text-xs font-black uppercase dark:text-white" style={{ letterSpacing: '0.08em', color: '#1E293B' }}>{mod.title}</p>
                </div>
                {/* Export buttons */}
                <div className="flex items-center gap-1">
                  {mod.exports.map((exp, i) => (
                    <button key={i} onClick={() => setActiveSub(`${mod.title} — ${exp}`)}
                      className="flex items-center gap-1 px-2 py-1 text-slate-400 hover:text-gov-900 hover:bg-gov-50 dark:hover:bg-gov-900/20 transition-colors"
                      title={exp}>
                      {exp === 'Excel' ? <FileSpreadsheet size={12} strokeWidth={2} />
                        : exp === 'PDF' ? <FilePdf size={12} strokeWidth={2} />
                        : <FileText size={12} strokeWidth={2} />}
                    </button>
                  ))}
                </div>
              </div>
              {/* Subdivisions */}
              <div>
                {mod.subdivisions.map((sub, i) => (
                  <button key={i} onClick={() => setActiveSub(sub)}
                    className="group flex items-center justify-between w-full px-4 py-2.5 text-left bg-white dark:bg-slate-900 hover:bg-gov-900 dark:hover:bg-gov-900 transition-colors duration-150"
                    style={{ borderBottom: i < mod.subdivisions.length - 1 ? '1px solid #F8FAFC' : 'none' }}>
                    <span className="text-xs font-medium text-slate-600 dark:text-slate-400 group-hover:text-white transition-colors truncate"
                          style={{ letterSpacing: '0.04em' }}>{sub}</span>
                    <ChevronRight size={11} strokeWidth={2} className="text-slate-300 group-hover:text-white/60 transition-colors shrink-0 ml-2" />
                  </button>
                ))}
              </div>
            </motion.div>
          ))}
        </div>
      </section>

      {/* ── Sub-module Drawer ── */}
      <AnimatePresence>
        {activeSub && (
          <>
            <motion.div key="ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }} className="fixed inset-0 z-40"
              style={{ backgroundColor: 'rgba(2,6,23,0.5)', backdropFilter: 'blur(2px)' }}
              onClick={() => setActiveSub(null)} />
            <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
              transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
              className="fixed right-0 inset-y-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
              style={{ maxWidth: '480px', borderLeft: '1.5px solid #E2E8F0' }}
            >
              <div className="shrink-0 flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
                <div className="flex items-center gap-3">
                  <div className="flex items-center justify-center w-9 h-9" style={{ backgroundColor: 'rgba(0,51,102,0.08)' }}>
                    <Activity size={16} strokeWidth={2} style={{ color: '#003366' }} />
                  </div>
                  <div>
                    <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>{activeSub}</p>
                    <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>Módulo de Auditoría</p>
                  </div>
                </div>
                <button onClick={() => setActiveSub(null)} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                  <X size={18} strokeWidth={2} />
                </button>
              </div>
              <div className="flex-1 flex flex-col items-center justify-center p-8 gap-5">
                <div className="flex items-center justify-center w-14 h-14" style={{ backgroundColor: '#070D1B', border: '1.5px solid #1E293B' }}>
                  <Activity size={24} strokeWidth={1.5} style={{ color: '#00A67E' }} />
                </div>
                <div className="text-center space-y-1">
                  <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em', color: '#CBD5E1', textTransform: 'uppercase' }}>Sin datos disponibles</p>
                  <p style={{ fontSize: '11px', color: '#CBD5E1', maxWidth: '280px', lineHeight: 1.5 }}>
                    Este módulo estará disponible cuando exista integración con el endpoint operativo.
                  </p>
                </div>
              </div>
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Audit;
