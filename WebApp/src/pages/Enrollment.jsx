import { useState, useEffect, useCallback } from 'react';
import {
  UserPlus,
  Fingerprint,
  Trash2,
  Search,
  Plus,
  Save,
  UserCircle,
  X,
  ChevronLeft,
  ChevronRight
} from 'lucide-react';
import { studentsApi } from '../api/students';
import { cn } from '../utils/cn';

const Enrollment = () => {
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [lastId, setLastId] = useState(0);
  const [hasMore, setHasMore] = useState(true);
  const [limit, setLimit] = useState(50);
  const [debouncedSearch, setDebouncedSearch] = useState('');

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchTerm);
      setLastId(0);
      setStudents([]);
      setHasMore(true);
    }, 400);
    return () => clearTimeout(timer);
  }, [searchTerm]);

  const fetchStudents = useCallback(async (reset = false) => {
    if (loading && !reset) return;
    setLoading(true);
    try {
      const cursor = reset ? 0 : lastId;
      const result = await studentsApi.getAll({
        last_id: cursor,
        limit,
        search: debouncedSearch,
      });
      setStudents(prev => reset ? result.students : [...prev, ...result.students]);
      setLastId(result.lastId);
      setHasMore(result.hasMore);
    } catch (error) {
      console.error('Error fetching students', error);
    } finally {
      setLoading(false);
    }
  }, [lastId, limit, debouncedSearch]);

  // Carga inicial y recarga cuando cambia la búsqueda
  useEffect(() => {
    fetchStudents(true);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch, limit]);

  const loadMore = () => {
    if (!loading && hasMore) {
      fetchStudents(false);
    }
  };

  if (loading && students.length === 0) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center font-black text-institutional-900 uppercase tracking-widest animate-pulse">
        Sincronizando datos institucionales...
      </div>
    );
  }

  return (
    <div className="space-y-10 py-8 animate-in fade-in duration-500">
      {/* Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div className="space-y-2">
          <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight">Enrolamiento</h2>
          <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.2em]">Registro y vinculación biométrica de estudiantes</p>
        </div>
        <button
          onClick={() => setIsModalOpen(true)}
          className="bg-institutional-900 hover:bg-institutional-800 dark:bg-institutional-700 dark:hover:bg-institutional-600 text-white font-black py-5 px-10 rounded-2xl transition-all flex items-center justify-center gap-3 shadow-xl shadow-institutional-900/20 dark:shadow-none uppercase tracking-widest"
        >
          <Plus size={24} />
          <span>Nuevo Estudiante</span>
        </button>
      </div>

      {/* Search + Limit */}
      <div className="bg-white dark:bg-slate-900 p-8 rounded-[2.5rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 space-y-4">
        <div className="relative">
          <Search className="absolute left-6 top-1/2 -translate-y-1/2 text-gray-400 dark:text-slate-600" size={24} />
          <input
            type="text"
            placeholder="Buscar por nombre, documento o grado..."
            className="w-full pl-16 pr-6 py-5 bg-gray-50 dark:bg-slate-800 border border-gray-100 dark:border-slate-700 rounded-3xl focus:ring-4 focus:ring-institutional-500/10 focus:border-institutional-500 outline-none transition-all font-bold dark:text-white"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
        <div className="flex items-center justify-end">
          <div className="flex items-center gap-3">
            <span className="text-[10px] font-black text-gray-400 uppercase tracking-widest">Mostrar</span>
            <select
              value={limit}
              onChange={(e) => {
                setLimit(Number(e.target.value));
                setLastId(0);
                setStudents([]);
                setHasMore(true);
              }}
              className="p-2 bg-gray-50 dark:bg-slate-800 border border-gray-100 dark:border-slate-700 rounded-xl font-bold text-xs dark:text-white outline-none focus:border-institutional-500"
            >
              <option value={10}>10</option>
              <option value={25}>25</option>
              <option value={50}>50</option>
              <option value={100}>100</option>
            </select>
          </div>
        </div>
      </div>

      {/* Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        {students.map((student) => (
          <div key={student.id} className="bg-white rounded-[2rem] shadow-soft border border-gray-100 overflow-hidden group hover:border-institutional-400 transition-all duration-300">
            <div className="p-8">
              <div className="flex items-start justify-between mb-6">
                <div className="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center text-gray-300 shadow-inner">
                  <UserCircle size={56} />
                </div>
                <div className="flex gap-2">
                  <button className="p-3 text-gray-400 hover:text-institutional-700 hover:bg-institutional-50 rounded-xl transition-all">
                    <Fingerprint size={22} />
                  </button>
                  <button className="p-3 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-xl transition-all">
                    <Trash2 size={22} />
                  </button>
                </div>
              </div>
              <h3 className="text-xl font-black text-gray-900 leading-tight uppercase tracking-tight">{student.name}</h3>
              <p className="text-xs font-bold text-gray-400 uppercase tracking-widest mt-2">ID: {student.id} • Grado {student.group}</p>

              <div className="mt-8 flex items-center justify-between pt-6 border-t border-gray-50">
                <span className={cn(
                  "inline-flex items-center px-4 py-1 rounded-full text-[10px] font-black uppercase tracking-widest border",
                  student.fingerprintId ? "bg-green-50 text-green-700 border-green-100" : "bg-amber-50 text-amber-700 border-amber-100"
                )}>
                  {student.fingerprintId ? 'Huella Vinculada' : 'Pendiente Huella'}
                </span>
                <span className="text-[10px] font-black text-gray-300 uppercase tracking-widest">{student.active ? 'Activo' : 'Inactivo'}</span>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Load More */}
      {hasMore && (
        <div className="flex items-center justify-center">
          <button
            onClick={loadMore}
            disabled={loading}
            className="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 shadow-soft px-8 py-4 rounded-2xl font-black text-sm uppercase tracking-widest hover:border-institutional-400 transition-all disabled:opacity-40"
          >
            {loading ? 'Cargando...' : 'Cargar más'}
          </button>
        </div>
      )}

      {/* Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-md z-50 flex items-center justify-center p-4 animate-in fade-in duration-300">
          <div className="bg-white dark:bg-slate-900 rounded-[3rem] w-full max-w-2xl shadow-2xl overflow-hidden border border-gray-100 dark:border-slate-800">
            <div className="bg-institutional-900 px-10 py-8 text-white flex items-center justify-between">
              <div className="flex items-center gap-4">
                <div className="p-3 bg-white/10 rounded-2xl">
                  <UserPlus size={24} />
                </div>
                <div>
                  <h3 className="font-black text-2xl uppercase tracking-tight">Registrar Estudiante</h3>
                  <p className="text-[10px] font-black uppercase tracking-widest opacity-60">Nuevo ingreso institucional</p>
                </div>
              </div>
              <button onClick={() => setIsModalOpen(false)} className="hover:bg-white/10 p-3 rounded-2xl transition-colors">
                <X size={28} />
              </button>
            </div>
            <form className="p-10 space-y-6" onSubmit={(e) => e.preventDefault()}>
              <div className="grid grid-cols-2 gap-6">
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Nombres</label>
                  <input type="text" className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all" placeholder="Ej. Juan" />
                </div>
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Apellidos</label>
                  <input type="text" className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all" placeholder="Ej. Pérez" />
                </div>
              </div>
              <div className="space-y-2">
                <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Documento de Identidad</label>
                <input type="text" className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all" placeholder="Número de identificación" />
              </div>
              <div className="space-y-2">
                <label className="text-[10px] font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] ml-2">Grado Institucional</label>
                <select className="w-full p-5 bg-gray-50 dark:bg-slate-800 border-2 border-gray-100 dark:border-slate-700 rounded-2xl outline-none focus:border-institutional-400 dark:text-white font-bold transition-all appearance-none">
                  <option>Seleccione un grado</option>
                  <option>6-A</option>
                  <option>7-B</option>
                  <option>10-C</option>
                </select>
              </div>

              <div className="pt-6 flex items-center justify-between gap-6">
                <div className="flex items-center gap-3 text-amber-600 bg-amber-50 dark:bg-amber-900/20 px-5 py-3 rounded-2xl border border-amber-100 dark:border-amber-900/30 text-[10px] font-black uppercase tracking-widest">
                  <Fingerprint size={20} />
                  <span>Pendiente vincular huella</span>
                </div>
                <button
                  onClick={() => setIsModalOpen(false)}
                  className="bg-institutional-900 hover:bg-institutional-800 text-white font-black py-5 px-10 rounded-2xl transition-all flex items-center gap-3 shadow-xl shadow-institutional-900/20 uppercase tracking-widest"
                >
                  <Save size={20} />
                  <span>Guardar Registro</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

export default Enrollment;
