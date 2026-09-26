# LISTA DE VERIFICACIONES DOCUMENTO → CÓDIGO

**Documento maestro de auditoría NEXO.** Cada verificación corresponde a una afirmación técnica del documento que debe comprobarse en el código (backend PHP, edge C++, schema SQL, PWA React, workers, infraestructura).

Estados posibles por verificación:
- ✅ **CUMPLE** — implementado y verificado en código con evidencia.
- ⚠️ **PARCIAL** — implementado incompleto o con desviaciones.
- ❌ **NO CUMPLE** — no existe implementación.
- ◻️ **N/A-CÓDIGO** — atributo físico/de instalación no verificable en el repositorio.

Resultados detallados por bloque de 50 en `ANALISIS_AUDITORIA.md`.

---

## CAPÍTULO 4 — PROPUESTA TÉCNICA DE SOLUCIÓN

### 4.1 NEXO como sistema de custodia inteligente

- V-001 Registro de presencia al iniciar la actividad en un aula.
- V-002 Asocia la identificación con grupo, aula y bloque horario.
- V-003 Maneja rotación de aulas (nueva identificación → nuevo bloque).
- V-004 Reconoce continuidad de permanencia cuando el docente extiende un bloque (sin exigir nueva identificación).
- V-005 Incorpora modificaciones de jornada (actos, reuniones) para no interpretar ausencia como anomalía.
- V-006 Registra salidas temporales mediante identificación.
- V-007 Vincula la salida con el contexto (permiso, período autorizado).
- V-008 Valida el retorno mediante nueva identificación.
- V-009 Conserva la duración de la salida.
- V-010 Asocia la salida de un estudiante con un permiso generado por un docente.
- V-011 Reconoce la vigencia de la autorización mientras permanece activa.
- V-012 Controla el retorno tras el plazo autorizado.
- V-013 Genera la actuación configurada cuando el plazo transcurre sin retorno.
- V-014 Actualiza la situación cuando el estudiante retorna y se identifica.
- V-015 Aplica la misma lógica a salidas pedagógicas y otras autorizaciones institucionales.
- V-016 Determina presencia en el espacio académico correspondiente al período en curso.
- V-017 Reconoce ausencia respecto del contexto académico previsto cuando hay cambio de aula sin identificación.
- V-018 NO vigila cada desplazamiento (solo puntos funcionales de identificación).
- V-019 Maneja actividades fuera del aula con cambio efectivo de espacio/bloque.
- V-020 Deja bajo criterio del docente los desplazamientos menores.

### 4.2 Articulación de la operación institucional

- V-021 Articula a rectoría, coordinación, docentes, secretaría, portería y auxiliares.
- V-022 Asigna a cada actor operaciones y niveles de consulta según su responsabilidad.
- V-023 Permite a docentes y coordinadores consultar información de estudiantes y grupos bajo su responsabilidad.
- V-024 Permite consultar asistencia, llegadas tardías, inasistencias, permisos y acontecimientos.
- V-025 Permite a secretaría gestionar información administrativa de estudiantes y grupos.
- V-026 Permite a portería y auxiliares reportar daños, novedades, emergencias y situaciones.
- V-027 Implementa comunicación interna entre actores (solicitudes, reportes).
- V-028 Mantiene la comunicación asociada al contexto operativo que la originó.
- V-029 Incorpora cambios de horario durante la jornada.
- V-030 Comunica al acudiente la modificación de horario.
- V-031 Requiere identificación del estudiante en el espacio actualizado para validar la salida.
- V-032 Emite comunicaciones por WhatsApp automáticamente ante condiciones definidas.
- V-033 Recibe respuestas del acudiente por WhatsApp.
- V-034 Utiliza la respuesta del acudiente como parte del proceso (desconocimiento → actuación; confirmación → continuación).
- V-035 Permite iniciar procesos de seguimiento hacia coordinación, psicoorientación u otra dependencia.
- V-036 Conserva la relación entre la condición identificada y la actuación derivada.

### 4.3 Automatización y optimización de procesos

- V-037 Registra presencia y la asocia al contexto académico sin registro manual posterior.
- V-038 Vincula automáticamente la salida con estudiante, espacio, autorización y momento.
- V-039 Actualiza la condición de retorno sin reconstrucción manual.
- V-040 Verifica automáticamente la expiración de permisos.
- V-041 Ejecuta la actuación prevista cuando no hay retorno en el período.
- V-042 Actualiza la situación cuando aparece una nueva identificación válida.
- V-043 Procesa automáticamente cambios de horario (entrada tardía, salida anticipada).
- V-044 Utiliza la nueva condición horaria para interpretar acontecimientos posteriores.
- V-045 Genera comunicaciones necesarias ante cambios de horario.
- V-046 Permite al docente extender un bloque y modificar temporalmente el contexto esperado.
- V-047 Genera avisos a los responsables correspondientes según condiciones definidas.
- V-048 Genera comunicaciones a acudientes cuando el flujo lo establece.
- V-049 Reincorpora la respuesta recibida al proceso correspondiente.
- V-050 Permite a actores autorizados consultar acontecimientos sin reconstrucción manual.

### 4.4 Capacidad de análisis y respuesta

- V-051 Relaciona acontecimientos producidos en diferentes momentos de la jornada.
- V-052 Compara acontecimientos con parámetros establecidos por la institución.
- V-053 Analiza repetición de salidas, llegadas tardías, inasistencias según frecuencia, período y contexto.
- V-054 Acumula acontecimientos y los compara con umbrales establecidos.
- V-055 Genera el aviso cuando se alcanza la condición definida.
- V-056 Permite al docente establecer notificación cuando un estudiante acumula cierta cantidad de llegadas tardías en un período.
- V-057 Reconoce discrepancia cuando un estudiante identificado en un bloque no registra presencia en el siguiente espacio.
- V-058 Genera la actuación configurada para posible ausencia o evasión.
- V-059 Maneja el análisis de permisos sobre una relación temporal (autorización → salida → vigencia → retorno).
- V-060 Genera aviso cuando no existe retorno al cumplirse el plazo.
- V-061 Reconoce inconsistencias de contexto (identificación en espacio que ya no corresponde al bloque vigente).
- V-062 Informa que el espacio dejó de corresponder a la clase actual.
- V-063 NO cierra la autorización por una identificación en lugar incorrecto (conserva la condición hasta el acontecimiento correcto).
- V-064 Mantiene una representación dinámica de las situaciones durante la jornada.
- V-065 Procesa mientras los acontecimientos continúan produciéndose (no solo al final del período).
- V-066 Dirige la respuesta al actor definido por la institución según la naturaleza de la condición.
- V-067 Notifica posible evasión al docente y a coordinación.
- V-068 Dirige incidente crítico reportado desde el aula a coordinación.
- V-069 Activa proceso de seguimiento ante condición de seguimiento.
- V-070 Desencadena comunicación con acudiente ante situación de asistencia.
- V-071 NO traslada la decisión profesional al motor automático (solo señala, proporciona contexto y comunica).
- V-072 Permite al docente, coordinador, rector u otro actor interpretar y determinar la actuación.

### 4.5 Configuración y adaptación institucional

- V-073 Implementa un modelo de configuración que representa la organización académica, operativa y funcional.
- V-074 Permite configurar grados, nomenclaturas, grupos, estudiantes, aulas, docentes, horarios y dispositivos.
- V-075 Establece relaciones necesarias para interpretar una identificación según grupo, espacio y bloque.
- V-076 Permite a coordinación establecer diariamente horario normal, entrada tardía o salida anticipada.
- V-077 Permite al docente extender bloques.
- V-078 Permite a docentes establecer criterios de aviso asociados con su actividad.
- V-079 Permite establecer cantidad de llegadas tardías, inasistencias o salidas dentro de un período definido.
- V-080 Permite a rectoría y coordinación mantener potestad sobre parámetros institucionales y el motor de riesgos.
- V-081 Permite determinar qué situaciones generan actuaciones, qué actores las conocen y bajo qué condiciones se activan.
- V-082 Diferencia entre capacidad técnica del sistema y criterio institucional aplicado.
- V-083 Adapta los procesos de custodia a diferentes estructuras horarias y académicas.
- V-084 Soporta rotación de aulas, grupos en un mismo salón y cambios de horario durante la jornada.
- V-085 Mantiene estables los principios funcionales (identificación, articulación, automatización, análisis, respuesta) entre instituciones.

---

## CAPÍTULO 5 — OBJETIVOS

### 5.2.1 Integrar tecnológicamente la operación cotidiana

- V-086 Consolida procesos, actores y recursos de información en una misma estructura funcional.
- V-087 Evita que la tecnología sea un conjunto de herramientas aisladas.

### 5.2.2 Articular y mantener continuidad de la información

- V-088 Relaciona acontecimientos con estudiante, contexto académico, espacio, momento y elementos pertinentes.
- V-089 Conserva la continuidad de la información.
- V-090 Facilita la utilización de la información en los procesos institucionales.

### 5.2.3 Automatizar y optimizar procesos operativos

- V-091 Automatiza registro, organización, relacionamiento, procesamiento, verificación y comunicación.
- V-092 Ejecuta operaciones conforme a reglas y condiciones previamente establecidas.
- V-093 Disminuye reprocesos.
- V-094 Libera capacidad de trabajo para funciones que requieren intervención profesional.

### 5.2.4 Fortalecer detección, análisis y respuesta oportuna

- V-095 Identifica acontecimientos, secuencias y condiciones que requieren atención.
- V-096 Relaciona la información necesaria para interpretación.
- V-097 Genera oportunamente actuaciones o comunicaciones definidas.

### 5.2.5 Fortalecer custodia y seguimiento contextualizado

- V-098 Registra y relaciona acontecimientos relevantes asociados con la permanencia del estudiante.
- V-099 Cubre presencia académica, salidas, retornos, permisos y situaciones de seguimiento.

### 5.2.6 Mejorar comunicación institucional e interacción con familias

- V-100 Fortalece mecanismos de comunicación entre actores y con acudientes.
- V-101 Implementa flujos oportunos, contextualizados y verificables.
- V-102 Permite trasladar situaciones, recibir respuestas e incorporarlas a los procesos.

### 5.2.7 Contribuir al fortalecimiento de asistencia, puntualidad y seguimiento temprano

- V-103 Utiliza capacidades de identificación, seguimiento, análisis y respuesta.
- V-104 Contribuye al fortalecimiento de asistencia y puntualidad.
- V-105 Apoya cumplimiento de disposiciones y procedimientos institucionales.
- V-106 Favorece identificación temprana de condiciones que requieren seguimiento o intervención.

---

## CAPÍTULO 6 — PLATAFORMA DE GESTIÓN INSTITUCIONAL

### 6.1 Centro de operación y articulación

- V-107 Implementa un entorno de interacción humana que converge información de nodos y componentes.
- V-108 Permite consultar, interpretar y utilizar la información producida por NEXO.
- V-109 Permite establecer condiciones de funcionamiento, ejecutar actuaciones y mantener procesos de seguimiento.
- V-110 Presenta información en tiempo real sobre condiciones relevantes de la jornada.
- V-111 Permite ejecutar actuaciones: permisos, salidas pedagógicas, modificaciones horarias, extensiones de bloques, consulta de acontecimientos, citaciones, reporte de incidentes, inicio de seguimientos.
- V-112 Integra consulta de información y actuación derivada en el mismo entorno.
- V-113 Relaciona capacidades automáticas con decisiones de actores institucionales.
- V-114 Permite que decisiones y configuraciones de usuarios modifiquen el contexto de interpretación de acontecimientos posteriores.

### 6.2 Gestión orientada por roles y responsabilidades

- V-115 Implementa diferenciación por roles.
- V-116 Determina información consultable y actuaciones/configuraciones desarrollables por cada rol.
- V-117 Da a rectoría visión general y configuración estructural (año lectivo, horarios, asignación de docentes y grupos, parámetros institucionales).
- V-118 Permite a rectoría configurar el motor de análisis de riesgos y sus métricas.
- V-119 Da a coordinación función de supervisión y operación transversal.
- V-120 Permite a coordinación establecer condiciones particulares de la jornada (modificaciones, entradas tardías, salidas anticipadas, alteraciones excepcionales).
- V-121 Permite a coordinación intervenir en la configuración del motor de análisis de riesgos.
- V-122 Permite a coordinación recibir situaciones que requieren atención a ese nivel.
- V-123 Da al docente instrumentos de gestión de sus grupos y seguimiento de estudiantes.
- V-124 Permite al docente consultar acontecimientos, gestionar permisos, establecer criterios de aviso, reportar situaciones, iniciar seguimientos.
- V-125 Da a secretaría capacidades de gestión y consulta de información institucional de estudiantes y grupos.
- V-126 Permite a secretaría mantener información administrativa necesaria.
- V-127 Permite a secretaría utilizar mecanismos de comunicación institucional.
- V-128 Da a portería y auxiliares capacidades de reporte de situaciones operativas, daños, novedades y emergencias.
- V-129 Hace que cada usuario encuentre información y capacidades relacionadas con su trabajo.
- V-130 Mantiene la complejidad global integrada mientras la experiencia por actor se concentra en sus responsabilidades.

### 6.3 Consulta y operación de la información institucional

- V-131 Permite consultar información sin aislarla del contexto necesario.
- V-132 Permite a usuarios autorizados consultar estudiantes, grupos, horarios, asistencia, llegadas tardías, inasistencias, permisos, acontecimientos y seguimientos.
- V-133 Permite analizar desde diferentes niveles (situaciones individuales y condiciones de grupos y períodos).
- V-134 Reúne acontecimientos pertinentes de un estudiante durante la jornada.
- V-135 Permite observar relación con el contexto académico en que se produjo cada registro.
- V-136 Permite a un docente consultar frecuencia de llegadas tardías de un estudiante a una clase.
- V-137 Permite a coordinación revisar inasistencias y permisos de un grupo.
- V-138 Permite a un responsable autorizado consultar acontecimientos relacionados con un proceso de seguimiento.
- V-139 Permite utilizar la información directamente para ejecutar actuaciones.
- V-140 Permite generar permiso, iniciar seguimiento, realizar comunicación, registrar incidencia u otra operación desde el mismo contexto.
- V-141 Proporciona representación actualizada de la jornada escolar.
- V-142 Permite conocer condiciones de presencia, llegadas tardías, inasistencias, permisos vigentes y situaciones que requieren atención.

### 6.4 Comunicación y seguimiento institucional

- V-143 Integra la comunicación dentro de los procesos de operación y seguimiento.
- V-144 Permite a actores comunicarse internamente (solicitudes, traslado de información, reportes).
- V-145 Permite a secretaría solicitar información o realizar gestiones con otros actores.
- V-146 Permite a portería y auxiliares trasladar novedades.
- V-147 Permite a docentes comunicar situaciones que requieren intervención.
- V-148 Permite a coordinación recibir y gestionar información desde distintos puntos.
- V-149 Permite iniciar proceso de seguimiento sobre el estudiante.
- V-150 Permite a actores autorizados registrar motivo, incorporar observaciones, actualizar estado, documentar actuaciones y cerrar caso.
- V-151 Permite derivar seguimiento hacia coordinación, psicoorientación u otra instancia.
- V-152 Implementa comunicación con acudientes bidireccional.
- V-153 Genera comunicaciones asociadas con situaciones determinadas.
- V-154 Recibe respuestas y mantiene su relación con el proceso que las originó.
- V-155 Ante ausencia, genera comunicación al acudiente y permite que la respuesta determine la actuación posterior.
- V-156 Ante respuesta de desconocimiento, traslada la condición al responsable institucional.
- V-157 Ante respuesta de estar informado, continúa con solicitud o recordatorio sobre excusa y actividades pendientes.
- V-158 Permite iniciar comunicaciones directas (citaciones y otras).
- V-159 Conserva continuidad entre notificación, respuesta y actuación posterior.

### 6.5 Configuración y adaptación de la operación

- V-160 Proporciona mecanismos para que la institución defina el contexto de interpretación de acontecimientos.
- V-161 Permite configurar grados, nomenclaturas, grupos, estudiantes, aulas, docentes, horarios y relaciones.
- V-162 Permite a rectoría administrar configuraciones generales del año lectivo, organización de grupos y docentes, y condiciones generales.
- V-163 Permite a coordinación modificar condiciones específicas de la jornada y establecer situaciones operativas excepcionales.
- V-164 Incorpora entradas tardías, salidas anticipadas, extensiones de bloques y modificaciones excepcionales.
- V-165 Permite configurar criterios del motor de análisis de riesgos.
- V-166 Permite a rectoría y coordinación establecer parámetros que determinan qué frecuencias, secuencias o condiciones requieren actuación.
- V-167 Permite a docentes establecer criterios relacionados con situaciones de sus grupos.
- V-168 Distingue entre capacidad técnica y criterio institucional.
- V-169 Permite representar diferentes modelos de organización académica (rotación, salón fijo, modificación de jornada).
- V-170 Mantiene estructura funcional común sin imponer una única forma de organización.

### 6.6 Experiencia de interacción e integración con NEXO

- V-171 Traduce la complejidad funcional en una experiencia utilizable sin conocimiento tecnológico.
- V-172 Organiza información y acciones según responsabilidades de cada usuario.
- V-173 Reduce operaciones intermedias entre conocer una situación y ejecutar la actuación.
- V-174 Implementa modos claro y oscuro.
- V-175 Implementa ajuste del tamaño de la fuente.
- V-176 Mantiene procesos complejos sin trasladar complejidad al actor.
- V-177 Permite consultar, configurar condiciones y ejecutar actuaciones sin exponer mecanismos internos.
- V-178 Adapta la tecnología al flujo de trabajo institucional.

---

## CAPÍTULO 7 — INGENIERÍA Y ARQUITECTURA

### 7.1 Arquitectura general y distribución del procesamiento

- V-179 Implementa un nodo de procesamiento local en cada punto de operación.
- V-180 El nodo recibe identificaciones biométricas.
- V-181 El nodo ejecuta localmente el proceso de reconocimiento.
- V-182 El nodo consulta la información necesaria para determinar la identidad.
- V-183 El nodo registra el resultado del evento junto con condiciones temporales y operativas.
- V-184 Mantiene operación de funciones críticas sin conexión permanente con la infraestructura central.
- V-185 El nodo conserva información necesaria para operar durante interrupciones de comunicación.
- V-186 El nodo mantiene localmente los eventos no transmitidos.
- V-187 Implementa una infraestructura central que recibe, valida, almacena y relaciona eventos.
- V-188 Continúa procesos de análisis, generación de novedades, comunicaciones y actuaciones con visión global.
- V-189 Mantiene respuesta inmediata en el borde y operaciones de integración en el central.
- V-190 Preserva propiedades: seguridad, disponibilidad, continuidad operativa, integridad, confiabilidad, mantenibilidad, eficiencia y capacidad de crecimiento.
- V-191 Puede concentrar en un mismo entorno físico interfaz de gestión, lógica de aplicación, mecanismos de procesamiento y persistencia.
- V-192 Mantiene separación funcional y lógica entre componentes aunque estén en el mismo entorno físico.
- V-193 Permite distribuir físicamente los componentes cuando el crecimiento lo justifique.

### 7.2 Flujo de información y comunicación entre componentes

- V-194 Procesa la captura biométrica en el nodo sin enviar la plantilla a la infraestructura central.
- V-195 Determina la identidad en el nodo.
- V-196 El nodo relaciona el resultado con la condición operativa correspondiente.
- V-197 El nodo genera un evento con la información necesaria para su tratamiento posterior.
- V-198 Mantiene la información biométrica confinada al entorno local del nodo.
- V-199 Transmite únicamente los datos requeridos para continuar el procesamiento institucional.
- V-200 NO transmite la representación biométrica utilizada para la comparación.
- V-201 Conserva el evento localmente antes de transmitirlo.
- V-202 Separa el momento del hecho del momento de su comunicación.
- V-203 Mantiene el evento válido aunque haya interrupción temporal de conectividad.
- V-204 Implementa conectividad celular M2M para la comunicación nodo → central.
- V-205 Permite intercambio automático sin conexión convencional independiente en cada espacio.
- V-206 Reduce dependencia de la infraestructura de red local del aula.
- V-207 Proporciona canal propio de comunicación entre nodo y central.
- V-208 Protege la información transmitida con mecanismos criptográficos (confidencialidad e integridad).
- V-209 Implementa mecanismos de autenticación que identifican el nodo de origen.
- V-210 Valida origen e integridad del evento antes de incorporarlo a la información institucional.
- V-211 Continúa el evento hacia persistencia, análisis, generación de incidentes, evaluación de riesgo, comunicación y demás actuaciones.
- V-212 Permite que la infraestructura central genere instrucciones destinadas a los nodos.
- V-213 Privilegia mecanismos que no requieren puertos de entrada expuestos en cada nodo.
- V-214 Reduce la superficie de exposición de los dispositivos.
- V-215 Permite a usuarios institucionales acceder a la infraestructura central mediante la plataforma de gestión.
- V-216 Permite consultar, ejecutar operaciones y recibir resultados.
- V-217 Convierte información del borde en información operativa para actores institucionales sin interacción directa con el nodo.

### 7.3 Persistencia, sincronización y continuidad operativa

- V-218 Implementa mecanismo de persistencia local en el nodo.
- V-219 Almacena temporalmente la información generada durante la operación.
- V-220 Mantiene los eventos pendientes hasta su transmisión correcta.
- V-221 Implementa sincronización diferida.
- V-222 Implementa mecanismos de reintento automático para transmisiones no completadas.
- V-223 Mantiene identificación y generación de registros durante interrupciones de conectividad.
- V-224 Conserva disponibilidad de almacenamiento local y capacidad de procesamiento.
- V-225 Al restablecerse la comunicación, continúa el envío de información pendiente conservando las condiciones temporales originales.
- V-226 Recupera eventos previamente almacenados tras reinicios o interrupciones del nodo.
- V-227 Evita que un reinicio implique desaparición de información pendiente de sincronización.
- V-228 Implementa mecanismos de supervisión del nodo para detectar condiciones anómalas y recuperar funcionamiento.
- V-229 Implementa fuente de energía de respaldo en el nodo.
- V-230 Mantiene operación ante interrupciones del suministro eléctrico.
- V-231 Dimensiona la autonomía energética para cubrir la jornada operativa.
- V-232 Combina procesamiento local, persistencia, sincronización diferida y respaldo energético.
- V-233 Al recuperar conectividad, no exige reconstrucción manual de eventos.
- V-234 En la infraestructura central implementa mecanismos de persistencia y procesamiento.
- V-235 Evita duplicidades al recibir información pendiente.
- V-236 Continúa procesos posteriores sin alterar la referencia temporal del evento.

### 7.4 Protección de la información y arquitectura de seguridad

#### 7.4.1 Confinamiento biométrico

- V-237 Realiza identificación localmente.
- V-238 Mantiene las plantillas dentro del nodo.
- V-239 NO circula la representación biométrica hacia la infraestructura central.
- V-240 Transmite únicamente la referencia necesaria para relacionar el resultado con el estudiante y el evento.
- V-241 Permite auditar que las plantillas permanecen almacenadas en el entorno local y protegidas mediante cifrado.
- V-242 Transmite hacia la central únicamente identificadores y metadatos operativos.

#### 7.4.2 Protección del almacenamiento local

- V-243 Protege la información almacenada localmente con mecanismos criptográficos.
- V-244 Impide que el acceso directo al medio de almacenamiento entregue la información en forma utilizable.
- V-245 Protege ante escenarios de pérdida, extracción o sustracción física del nodo.
- V-246 Trata el almacenamiento local como parte de la arquitectura (no como espacio público).

#### 7.4.3 Protección en tránsito (criptografía)

- V-247 Utiliza canales seguros para la comunicación nodo → central.
- V-248 Aplica mecanismos de cifrado adicionales para información sensible.
- V-249 Implementa cifrado AES-256-GCM a nivel de aplicación para los eventos transmitidos desde el borde.
- V-250 Implementa AES-256-GCM con IV de 12 bytes.
- V-251 Implementa AES-256-GCM con tag de 16 bytes.
- V-252 Aplica protección TLS en la comunicación HTTP.
- V-253 Usa la capa criptográfica adicional sobre el transporte como defensa en profundidad.

#### 7.4.4 Funciones criptográficas (hash, HMAC, cadena de integridad)

- V-254 Utiliza cifrado para proteger información frente a acceso no autorizado.
- V-255 Utiliza funciones hash para operaciones de integridad, autenticidad y detección de modificaciones.
- V-256 Utiliza mecanismos HMAC para operaciones de integridad, autenticidad y detección de modificaciones.
- V-257 Implementa cadena de integridad aplicada a los registros de auditoría.
- V-258 Establece relaciones criptográficas entre registros consecutivos.
- V-259 Permite detectar alteraciones posteriores sobre los registros protegidos.

#### 7.4.5 Autenticación y autorización

- V-260 Controla el acceso a la infraestructura central mediante autenticación.
- V-261 Controla el acceso mediante autorización.
- V-262 Asigna permisos a usuarios según roles y responsabilidades.
- V-263 Identifica dispositivos mediante credenciales propias.
- V-264 Determina la institución de un dispositivo en la infraestructura central a partir de su identidad previamente registrada.
- V-265 Evita que el dispositivo declare libremente la institución a la que desea asociarse.

#### 7.4.6 Aislamiento institucional

- V-266 Implementa frontera lógica entre instituciones.
- V-267 Impide que la información de una organización sea consultada desde el contexto de otra.
- V-268 Mantiene el aislamiento como parte de la arquitectura de datos (no solo de la interfaz visible).

#### 7.4.7 Trazabilidad

- V-269 Asocia acciones con actor, contexto de institución, momento y operación ejecutada.
- V-270 Incorpora mecanismos de integridad para los registros de auditoría.
- V-271 Permite detectar modificaciones de los registros protegidos.

#### 7.4.8 Defensa en profundidad

- V-272 Combina confinamiento local de biometría, protección del almacenamiento, comunicación segura, autenticación, autorización, aislamiento institucional, integridad de registros y controles de acceso.
- V-273 Reduce la dependencia de un único mecanismo de protección.

### 7.5 Interoperabilidad, aislamiento y escalabilidad

- V-274 Implementa contratos y estructuras de comunicación comunes entre componentes.
- V-275 Usa lógica uniforme para generar eventos y comunicarlos a la infraestructura central.
- V-276 Permite que nuevos nodos se integren mediante configuración y registro sin modificar la lógica general de la plataforma.
- V-277 Mantiene independencia funcional de cada nodo.
- V-278 Permite ampliar progresivamente la cobertura física de una institución.
- V-279 Mantiene arquitectura central común.
- V-280 Mantiene aislamiento institucional mediante separación lógica de datos y contextos de operación.
- V-281 Permite que instituciones compartan infraestructura sin compartir registros, configuraciones, usuarios o información biométrica.
- V-282 Permite incorporar nuevas instituciones sin convertir información de unas en accesible para otras.
- V-283 Permite expansión en el borde mediante incorporación de nuevos nodos.
- V-284 Separa la recepción de eventos de los procesos posteriores.
- V-285 Permite absorber cargas de trabajo sin obligar a que todas las operaciones se ejecuten en la misma transacción o en el mismo momento.
- V-286 Implementa mecanismos de almacenamiento central para grandes volúmenes de información histórica.
- V-287 Permite que procesos de análisis, notificación y mantenimiento se ejecuten de manera independiente del momento exacto de recepción del evento.
- V-288 Permite atender el crecimiento del volumen mediante ampliaciones progresivas de infraestructura.
- V-289 Permite mantener inicialmente la arquitectura central en un único entorno físico.
- V-290 Permite evolucionar hacia mayor distribución de recursos sin modificar principios fundamentales.
- V-291 Mantiene responsabilidades y fronteras funcionales claras entre componentes.

### 7.6 Infraestructura física, instalación y protección del nodo

- V-292 (firmware/config) Soporta operación continua en ambientes con circulación permanente de personas.
- V-293 El diseño físico contempla resistencia, protección, estabilidad, disipación térmica y mantenibilidad.
- V-294 El gabinete forma barrera física continua alrededor del núcleo de procesamiento.
- V-295 La estructura es rígida y resistente (construcción monolítica doblada y soldada).
- V-296 El diseño reduce superficies y elementos externos utilizables para palanca o extracción.
- V-297 El cerramiento perimetral limita ingreso de polvo y humedad.
- V-298 (instalación) Usa prensaestopas industriales en entradas de conductores.
- V-299 Los prensaestopas cumplen función de protección ambiental y sujeción mecánica de conductores.
- V-300 El diseño contempla protección de elementos electrónicos frente a condiciones ambientales.
- V-301 La fijación del nodo es directa sobre la estructura física de la institución.
- V-302 Los elementos de fijación permanecen protegidos dentro del cerramiento.
- V-303 Los puntos de sujeción no quedan disponibles desde la superficie exterior.
- V-304 El acceso al interior se concentra en un único mecanismo de apertura mediante llave física.
- V-305 El cerramiento incorpora bisagras ocultas.
- V-306 El diseño evita exposición de tornillería externa.
- V-307 El lector biométrico se instala desde el interior del gabinete.
- V-308 Desde el exterior solo permanece expuesta la superficie necesaria para la captura.
- V-309 El lector no es fácilmente desmontable desde la cara externa.
- V-310 (gestión térmica) Implementa ventilación activa con control de velocidad.
- V-311 Implementa elementos de disipación pasiva.
- V-312 Implementa circulación de aire regulada mediante perforaciones diseñadas.
- V-313 El diseño complementa perforaciones con deflectores y filtros antipolvo.
- V-314 La masa estructural del chasis participa en la disipación térmica.
- V-315 El cableado de alimentación y comunicación se canaliza mediante tubería Conduit y canaletas de alto impacto.
- V-316 El diseño evita recorridos expuestos sujetos a tránsito o manipulación.
- V-317 Los prensaestopas proporcionan alivio de tensión mecánica en puntos de entrada al gabinete.
- V-318 El diseño mantiene separación física entre líneas de alimentación y líneas de comunicación y datos.
- V-319 Los conductores permanecen sujetos mediante ductos y elementos de fijación.
- V-320 (instalación) Incorpora conexión del chasis a un punto de puesta a tierra.
- V-321 El diseño contempla mantenibilidad (intervención, reparación o sustitución de componentes sin comprometer la totalidad del nodo).
- V-322 El diseño permite sustituir componentes internos desde el interior sin alterar la estructura externa ni el sistema de fijación.
- V-323 La infraestructura física permanece instalada durante largos periodos mientras se renuevan componentes.

### 7.7 Decisiones arquitectónicas fundamentales

- V-324 Mantiene procesamiento biométrico en el borde.
- V-325 Conserva localmente los eventos antes de su transmisión.
- V-326 Utiliza conectividad celular M2M.
- V-327 Protege la información mediante diferentes capas de seguridad.
- V-328 Combina protección del almacenamiento, cifrado de aplicación y canales seguros de transporte.
- V-329 Mantiene frontera de datos por institución dentro de la infraestructura central.
- V-330 Determina la identidad institucional desde la infraestructura central (no desde datos declarados arbitrariamente por el dispositivo).
- V-331 Separa la recepción de eventos de los procesos posteriores asíncronos.
- V-332 Permite que generación de incidentes, análisis de riesgos, notificaciones y tareas de auditoría no bloqueen el procesamiento inicial del evento.
- V-333 Mantiene los nodos físicamente protegidos y con acceso interno restringido.
- V-334 Diseña la infraestructura física para un ciclo de vida prolongado.
- V-335 Permite que la infraestructura central comience en un único entorno físico y evolucione posteriormente.

---

## CAPÍTULO 8 — MARCO LEGAL Y NORMATIVO

### 8.1 Protección de datos personales y finalidad del tratamiento

- V-336 Aplica el principio de finalidad desde la definición de las condiciones de operación.
- V-337 Permite a la institución definir estructura y parámetros que determinan qué información es pertinente.
- V-338 Proporciona mecanismos para ejecutar y mantener esas condiciones de manera consistente.
- V-339 Permite que la tecnología se adapte a la finalidad institucional.
- V-340 Relaciona la información de un estudiante con contexto determinado (momento, espacio, actividad académica y demás condiciones).
- V-341 Evita que un dato aislado sea utilizado fuera del proceso en que adquiere sentido.
- V-342 Se desarrolla de acuerdo con la base jurídica que corresponda a cada tratamiento.
- V-343 Proporciona estructura para identificar, relacionar, consultar y gestionar información dentro de un contexto determinado.
- V-344 Permite a la institución aplicar procedimientos para atender los derechos de los titulares.
- V-345 Refleja el principio de finalidad como condición previa a la generación de actuaciones.

### 8.2 Tratamiento de información biométrica

- V-346 NO conserva la huella como imagen que pueda circular por la infraestructura.
- V-347 Extrae características necesarias para la comparación durante el reconocimiento.
- V-348 Genera una representación matemática que permite la identificación sin convertir la huella física en registro permanente del flujo.
- V-349 Mantiene la representación empleada para la comparación dentro del entorno local del nodo.
- V-350 NO envía la representación biométrica a la infraestructura central.
- V-351 NO mantiene la captura original como elemento operativo del procesamiento posterior.
- V-352 Continúa el tratamiento mediante el resultado de la identificación y las referencias necesarias.
- V-353 NO requiere recibir la representación biométrica en la infraestructura central.
- V-354 Protege la representación biométrica local mediante cifrado.
- V-355 Impide que el acceso directo al almacenamiento constituya vía ordinaria para disponer de la información protegida.
- V-356 Delimita la función de la huella a la identificación del estudiante.
- V-357 NO utiliza la capacidad biométrica como fundamento para ampliar indiscriminadamente el tratamiento.
- V-358 Circunscribe la biometría a la función de custodia estudiantil.

### 8.3 Protección integral e interés superior de niños, niñas y adolescentes

- V-359 NO trata los datos del menor únicamente por utilidad administrativa.
- V-360 Integra la información dentro de una operación institucional orientada a custodia, permanencia, seguimiento y respuesta.
- V-361 Al registrar llegada, ausencia, salida autorizada, retorno o condición de seguimiento, NO convierte el acontecimiento en conclusión sobre el estudiante.
- V-362 Conserva y relaciona información necesaria para que la institución reconozca qué está ocurriendo.
- V-363 Pone el contexto a disposición del responsable que debe valorarlo.
- V-364 Permite a quienes tienen responsabilidad establecer qué condiciones resultan pertinentes.
- V-365 Permite a un establecimiento determinar frecuencia, alertas y seguimientos específicos.
- V-366 NO determina unilateralmente qué debe considerarse situación de riesgo.
- V-367 Ejecuta los criterios institucionales de forma consistente.
- V-368 Proporciona a los responsables el contexto necesario para ejercer su función.
- V-369 NO sustituye el juicio sobre la situación que el dato representa.
- V-370 NO permite concluir automáticamente circunstancia personal del estudiante.
- V-371 Utiliza la información para hacer visible una condición que puede requerir valoración.
- V-372 Deja la interpretación y actuación bajo responsabilidad institucional.

### 8.4 Proporcionalidad y minimización del tratamiento

- V-373 Implementa criterio de minimización en el uso de la información.
- V-374 NO incorpora capacidades de tratamiento como obligación de utilizar toda la información técnicamente obtenible.
- V-375 Permite a la institución definir previamente qué condiciones resultan pertinentes.
- V-376 Desarrolla el tratamiento conforme a los parámetros definidos.
- V-377 Permite que una condición no se convierta automáticamente en alerta únicamente porque el sistema pueda detectarla.
- V-378 Permite establecer criterios de riesgo, frecuencia, secuencia y aviso según pertinencia institucional.
- V-379 Permite configurar condiciones dentro de la actividad docente para comunicar situaciones al alcanzar un parámetro definido.
- V-380 Ejecuta el criterio sin convertirlo en regla universal para todas las instituciones.
- V-381 Confina la representación biométrica al punto donde se necesita.
- V-382 Hace que la infraestructura central reciba el resultado operacional y no la información biométrica completa.
- V-383 NO registra permanentemente cada desplazamiento del estudiante.
- V-384 Produce identificación solo en puntos funcionales con finalidad concreta (presencia académica, salida autorizada, retorno, presencia por bloque).
- V-385 Evita que la custodia se transforme en seguimiento indiscriminado.
- V-386 Mantiene continuidad solo sobre acontecimientos relevantes.
- V-387 NO determina que toda anomalía detectada tenga la misma consecuencia.
- V-388 Permite a la institución establecer qué situaciones requieren conocimiento, cuáles justifican actuación y qué nivel de respuesta resulta pertinente.

### 8.5 Seguridad, confidencialidad e integridad de la información

- V-389 Protege la información durante permanencia en medios de almacenamiento.
- V-390 Protege la información durante su transmisión.
- V-391 Implementa mecanismos para controlar quién puede intervenir sobre la información.
- V-392 Implementa mecanismos de autenticación y autorización.
- V-393 Condiciona las operaciones permitidas por autorizaciones y responsabilidades.
- V-394 Implementa protección del almacenamiento local.
- V-395 Implementa protección de las comunicaciones.
- V-396 Implementa mecanismos para preservar la integridad de los acontecimientos durante su tránsito.
- V-397 Considera la protección física de los puntos donde se procesa y conserva información.
- V-398 Restringe el acceso al núcleo de procesamiento y almacenamiento dentro de una estructura físicamente protegida.
- V-399 Establece diferentes condiciones que actúan sobre acceso, almacenamiento, transmisión, integridad y protección física.
- V-400 Reduce la exposición que produciría confiar la totalidad de la seguridad a una sola barrera.

### 8.6 Circulación controlada y comunicación institucional

- V-401 Vincula la información al contexto en el cual puede ser utilizada.
- V-402 Controla el acceso a la información mediante autenticación y autorización.
- V-403 Evita que la incorporación de un dato al sistema equivalga a su disponibilidad indiscriminada.
- V-404 Origina la comunicación dentro de un acontecimiento y conserva su relación con el proceso que la generó.
- V-405 Emite notificaciones, recibe respuestas y continúa el proceso sin convertir la comunicación en acontecimiento aislado.
- V-406 Permite a la institución establecer qué condiciones producen comunicaciones y bajo qué circunstancias.
- V-407 NO libera automáticamente información de forma indiscriminada.
- V-408 Ejecuta criterios previamente establecidos dentro de la operación institucional.
- V-409 Conserva la finalidad que dio origen a la comunicación cuando intervienen mecanismos externos.
- V-410 Mantiene la responsabilidad sobre la información al usar canal externo.
- V-411 NO convierte el canal externo en repositorio paralelo de datos institucionales.
- V-412 Mantiene continuidad contextual entre notificación, respuesta y actuación posterior.

### 8.7 Continuidad, conservación y trazabilidad

- V-413 Conserva localmente los acontecimientos mientras no pueden transmitirse.
- V-414 Mantiene el procesamiento pendiente hasta que la comunicación se restablece.
- V-415 NO reconstruye la información posteriormente a partir de la memoria de los actores.
- V-416 Mantiene la información asociada con el momento en que fue generada.
- V-417 Continúa el recorrido cuando las condiciones de comunicación vuelven a estar disponibles.
- V-418 Preserva la integridad temporal de la información.
- V-419 Mantiene un acontecimiento producido durante interrupción como el acontecimiento que ocurrió en ese momento.
- V-420 Complementa la continuidad con respaldo energético.
- V-421 Mantiene capacidad de identificación y registro durante la autonomía prevista.
- V-422 Al agotarse la autonomía, suspende el funcionamiento sin pérdida de acontecimientos almacenados previamente.
- V-423 Permite relacionar diferentes hechos cuando resulta pertinente para operación o seguimiento.
- V-424 Distingue entre conservación para continuidad y obligación de determinar reglas de conservación y disposición.
- V-425 NO conserva indefinidamente cualquier dato.
- V-426 Proporciona capacidad técnica para conservar información cuando la operación lo exige.
- V-427 Permite que los períodos de conservación sean definidos por el marco jurídico, institucional y documental aplicable.

### 8.8 Automatización, análisis y responsabilidad institucional

- V-428 Implementa procesos automatizados capaces de relacionar acontecimientos, reconocer condiciones y generar actuaciones conforme a parámetros.
- V-429 Subordina la automatización a la finalidad del tratamiento.
- V-430 Subordina la automatización a la responsabilidad institucional sobre las decisiones que afectan al estudiante.
- V-431 Realiza operaciones de captura, relación, verificación, comunicación y seguimiento sin intervención manual constante.
- V-432 NO sustituye la valoración profesional de la situación.
- V-433 Permite a la institución establecer los parámetros de avisos y actuaciones.
- V-434 Permite a los responsables definir criterios asociados con las condiciones que necesitan conocer.
- V-435 Proporciona capacidad adaptable sin imponer una única interpretación.
- V-436 Al reconocer una secuencia que supera una condición, produce una señal de atención o actuación previamente configurada.
- V-437 NO convierte la condición en conclusión definitiva sobre el estudiante.
- V-438 Mantiene la interpretación bajo responsabilidad del actor que deba valorarla.
- V-439 Facilita disponibilidad de contexto al relacionar acontecimientos que carecerían de significado por separado.
- V-440 Permite que la automatización disminuya la carga mecánica sin trasladar a la infraestructura la responsabilidad de interpretar y decidir.

### 8.9 Responsabilidad demostrada y materialización de las condiciones de protección

- V-441 Materializa condiciones de protección observables directamente en su funcionamiento.
- V-442 Permite determinar dónde se desarrolla la identificación biométrica.
- V-443 Permite determinar qué información permanece dentro del nodo.
- V-444 Permite determinar qué información continúa hacia la infraestructura central.
- V-445 Permite determinar bajo qué condiciones se accede a la información.
- V-446 Permite determinar qué mecanismos intervienen durante almacenamiento y transmisión.
- V-447 Refleja la finalidad en la configuración institucional.
- V-448 Refleja la minimización en la delimitación de la información tratada y transmitida.
- V-449 Refleja la protección reforzada de la biometría en su confinamiento local.
- V-450 Refleja la seguridad en mecanismos de protección del almacenamiento, comunicaciones y acceso.
- V-451 Refleja la continuidad en la persistencia local y la sincronización posterior.
- V-452 NO convierte por sí mismo la materialización técnica en cumplimiento integral de obligaciones jurídicas.
- V-453 Mantiene la responsabilidad institucional en la configuración de las capacidades.
- V-454 Ejecuta criterios institucionales sin apropiarse de la potestad que les da sentido.

### 8.10 Marcos complementarios y condiciones de aplicabilidad

- V-455 Permite incorporar servicios adicionales sin abandonar principios fundamentales (finalidad, protección, control de acceso, minimización, responsabilidad institucional).
- V-456 Puede utilizar referentes reconocidos de arquitectura, calidad y seguridad sin atribuirles condición de certificación no obtenida formalmente.
- V-457 Mantiene diferenciación entre referencia técnica y obligación jurídica.

### 8.11 Correspondencia integral del marco normativo con NEXO

- V-458 Correspondencia entre exigencia de finalidad y configuración de condiciones de uso de la información.
- V-459 Correspondencia entre protección reforzada de datos biométricos e identificación local, permanencia de representaciones en el nodo y transmisión de referencias.
- V-460 Correspondencia entre circulación restringida y control de acceso y condiciones de autorización.
- V-461 Correspondencia entre seguridad y protección del almacenamiento, comunicaciones y acceso.
- V-462 Correspondencia entre proporcionalidad y capacidad institucional de establecer qué condiciones reconocer, qué parámetros justifican alerta y qué situaciones requieren actuación.
- V-463 Correspondencia entre continuidad y conservación local de acontecimientos cuando la comunicación no está disponible.
- V-464 Correspondencia entre continuidad y continuación posterior del recorrido sin perder el momento original.
- V-465 Las decisiones tecnológicas contribuyen simultáneamente a diferentes exigencias jurídicas.
- V-466 La protección se encuentra distribuida dentro de la propia estructura de funcionamiento.

### 8.12 El interés superior del menor como principio integrador

- V-467 NO incorpora información al sistema para convertirla en acumulación permanente de datos.
- V-468 NO utiliza la biometría como mecanismo de vigilancia general.
- V-469 NO conserva acontecimientos para ampliar indefinidamente la información disponible.
- V-470 Mantiene continuidad necesaria para comprender una situación y actuar sobre ella.
- V-471 NO sustituye el juicio profesional por alertas automáticas.
- V-472 Permite al responsable conocer oportunamente una condición que puede requerir valoración.
- V-473 NO decide por anticipado qué es relevante para todas las comunidades educativas.
- V-474 Proporciona capacidades de identificación, relacionamiento, análisis y comunicación.
- V-475 Permite al criterio institucional determinar cuándo la capacidad adquiere pertinencia.

---

## CAPÍTULO 9 — OPERACIÓN, CONTINUIDAD Y GESTIÓN DE CONTINGENCIAS

### 9.1 Operación ordinaria y adaptación a la jornada

- V-476 Mantiene relación continua entre acontecimientos, información generada y condiciones académicas/operativas.
- V-477 Incorpora cada identificación, registro manual, autorización, modificación o acontecimiento al contexto correspondiente.
- V-478 Relaciona eventos con los que lo preceden o suceden.
- V-479 Trabaja con representación dinámica de condiciones de la jornada.
- V-480 Contempla modificaciones: entradas tardías, salidas anticipadas, extensiones de bloques, actividades especiales, cambios de aula y alteraciones temporales.
- V-481 Utiliza la configuración vigente como contexto para interpretar acontecimientos posteriores.
- V-482 Evita interpretar una modificación real como anomalía por diferir de la planificación inicial.
- V-483 Mantiene relación entre estructura prevista y realidad operativa.

### 9.2 Validación previa y prevención de interpretaciones incorrectas

- V-484 Implementa mecanismos de validación para reducir interpretaciones incorrectas.
- V-485 Relaciona información disponible con contexto académico y operativo vigente antes de producir consecuencias.
- V-486 Diferencia entre ausencia de registro causada por condición técnica y ausencia de registro correspondiente al comportamiento esperado del estudiante.
- V-487 Maneja concentración o secuencia de eventos que no coincide con condiciones previstas.
- V-488 Ante aparición simultánea de número inusual de inasistencias, identifica señal de condición externa a contextualizar.
- V-489 Informa la anomalía y mantiene pendiente la actuación derivada hasta que exista suficiente contexto.
- V-490 Evita que una condición operativa no prevista, modificación de jornada o inconsistencia de configuración se transforme en conclusiones automáticas sobre estudiantes.
- V-491 Utiliza referencias temporales para relacionar eventos producidos en distintos momentos.
- V-492 Determina secuencias dentro de la jornada.
- V-493 Detecta desviación temporal significativa de la hora de un nodo.
- V-494 Comunica la desviación temporal a la infraestructura central.
- V-495 Atiende mediante mecanismos automáticos las situaciones susceptibles de corrección automática.
- V-496 Mantiene identificada para atención técnica la condición que excede capacidades automáticas.
- V-497 Distingue entre ocurrencia del evento y confiabilidad del contexto temporal utilizado para procesarlo.

### 9.3 Continuidad ante pérdida de conectividad

- V-498 Mantiene funciones críticas locales ante pérdida temporal de conectividad.
- V-499 Continúa procesando identificaciones y conservando acontecimientos mientras haya capacidades locales.
- V-500 Mantiene eventos pendientes de sincronización.
- V-501 Envía los eventos cuando la comunicación vuelve a estar disponible.
- V-502 NO exige reconstrucción manual de acontecimientos ocurridos durante la desconexión.
- V-503 Conserva la referencia temporal de cada evento.
- V-504 Dimensiona la capacidad de almacenamiento local para flujos esperados y períodos de desconexión superiores a los habituales.
- V-505 Implementa procesos cíclicos de limpieza y disposición de información.
- V-506 Evita que la operación ordinaria dependa de acumulación indefinida de datos locales.
- V-507 Identifica condiciones anómalas que reduzcan significativamente la capacidad disponible.
- V-508 Comunica la condición a la infraestructura central antes de que alcance nivel que comprometa la continuidad.
- V-509 Prioriza conservar acontecimientos y evita pérdida silenciosa de información.
- V-510 Contempla procesos para enfrentar reinicios o interrupciones transitorias del nodo.
- V-511 Mantiene información previamente persistida disponible para completar la sincronización.

### 9.4 Continuidad ante interrupciones del suministro eléctrico

- V-512 Incorpora respaldo energético integrado en los nodos.
- V-513 Realiza automáticamente la transición hacia la fuente de respaldo ante interrupción.
- V-514 Mantiene funciones previstas durante el período de autonomía.
- V-515 Identifica el estado energético del nodo mediante mecanismos visuales de indicación.
- V-516 Permite distinguir operación normal, condición de respaldo y eventual indisponibilidad energética.
- V-517 Dimensiona la capacidad de autonomía para cubrir la jornada operativa.
- V-518 Al agotarse la autonomía, suspende el funcionamiento hasta recuperar energía.
- V-519 NO pierde los acontecimientos almacenados previamente al agotarse la autonomía.

### 9.5 Contingencia de un nodo y continuidad de los grupos afectados

- V-520 Identifica la indisponibilidad total de un nodo.
- V-521 Evita que la ausencia de registros por indisponibilidad técnica sea interpretada como inasistencia, evasión u otra condición.
- V-522 Mantiene continuidad mediante operación manual o utilización temporal de otro espacio equipado.
- V-523 Permite representar cambios de espacio, horario o actividad que alteren temporalmente la estructura ordinaria.
- V-524 Evita que una reubicación produzca interpretaciones incompatibles con el contexto real.
- V-525 Asocia la condición a los grupos y períodos que debían utilizar el nodo afectado (no permanentemente a un único grupo).
- V-526 Mantiene los registros manuales dentro de NEXO.
- V-527 Hace que los registros manuales continúen participando de las capacidades de análisis.
- V-528 NO desactiva el motor de análisis por ausencia temporal de identificación biométrica.
- V-529 Continúa relacionando información disponible con acontecimientos posteriores.
- V-530 Permite relacionar una inasistencia registrada manualmente con una identificación posterior del mismo estudiante en otro espacio o período.
- V-531 Puede generar condición que requiera atención (posible evasión o discrepancia de ubicación).
- V-532 NO reconstruye acontecimientos que nunca fueron registrados.
- V-533 NO inventa ni deduce como hecho cierto una condición no registrada.
- V-534 Permite recuperar información local al reparar o reemplazar el nodo.
- V-535 Incorpora la información recuperable al nuevo entorno operativo.

### 9.6 Operación manual y casos excepcionales de identificación

- V-536 Implementa operación manual como modalidad de continuidad.
- V-537 Contempla fallas de lectura, condiciones físicas que impiden identificación, estudiantes sin extremidad requerida y condiciones de no participación legalmente aplicables.
- V-538 Dispone de dos registros de huella por estudiante como redundancia.
- V-539 Permite registrar manualmente la condición correspondiente cuando la identificación biométrica no puede utilizarse.
- V-540 Suspende el mecanismo automático que interpreta ausencia de identificación como inasistencia o evasión para la condición específica mientras está pendiente el registro manual.
- V-541 NO desactiva el motor de análisis al usar operación manual.
- V-542 Mantiene la información registrada manualmente como parte del contexto institucional.
- V-543 Relaciona la información manual con acontecimientos posteriores en otros espacios o períodos.
- V-544 Mantiene separación entre lo que conoce y lo que no puede reconstruir.
- V-545 NO establece como hecho una situación inicial que nunca fue registrada.

### 9.7 Indisponibilidad temporal de la infraestructura central

- V-546 Mantiene operación crítica local ante indisponibilidad de la infraestructura central.
- V-547 Continúa funciones locales: identificación, generación de eventos, persistencia y procesos que no dependen de comunicación con el central.
- V-548 Mantiene pendientes las funciones que requieren procesamiento central, consulta integrada o servicios externos.
- V-549 Conserva acontecimientos generados durante el período localmente.
- V-550 Continúa el recorrido hacia la infraestructura central al restablecerse el servicio.
- V-551 NO exige reconstrucción manual de la jornada ocurrida durante la indisponibilidad.
- V-552 Continúa recepción, validación, persistencia, análisis e integración manteniendo referencias temporales.

### 9.8 Continuidad de las comunicaciones institucionales

- V-553 Mantiene notificaciones pendientes en cola hasta recuperar condiciones de transmisión.
- V-554 Separa el momento de detección del momento de completación de la comunicación.
- V-555 Utiliza mecanismos alternativos cuando el canal preferente no está disponible (SMS cuando la condición lo permita).
- V-556 Permite que respuestas recibidas por canales alternativos regresen al contexto institucional.
- V-557 Permite continuar el flujo correspondiente dentro de la plataforma.
- V-558 NO convierte la comunicación en base de datos paralela ni vía independiente.
- V-559 Mantiene continuidad contextual entre notificación, respuesta y actuación posterior.

### 9.9 Configuración institucional y control de condiciones operativas

- V-560 Permite configurar entradas tardías, salidas anticipadas, extensiones de bloques, actividades especiales, modificaciones de horario, cambios de espacio y reubicaciones temporales.
- V-561 Utiliza la configuración vigente como contexto para interpretar nuevos acontecimientos.
- V-562 Evita mantener automáticamente una condición anterior cuando ya no representa la organización efectiva.
- V-563 Permite configurar los criterios del motor de análisis.
- V-564 Permite establecer parámetros que determinan cuándo una frecuencia, secuencia o condición adquiere relevancia.
- V-565 NO convierte automáticamente toda diferencia respecto de la operación prevista en situación de riesgo.
- V-566 Ante anomalía explicable por modificación legítima, permite reinterpretar los acontecimientos.
- V-567 Ante concentración o secuencia sin correspondencia con la configuración vigente, informa la anomalía y mantiene pendiente la actuación.

### 9.10 Recuperación y retorno a la operación ordinaria

- V-568 Inicia retorno progresivo a operación ordinaria al desaparecer la contingencia.
- V-569 Continúa procesamiento o sincronización de eventos pendientes.
- V-570 Deja de aplicar condiciones temporales excepcionales cuando corresponde.
- V-571 Retoma funciones automáticas suspendidas durante la contingencia.
- V-572 Evita duplicidades en el retorno a operación normal.
- V-573 Evita pérdida de información en el retorno.
- V-574 Evita reinterpretaciones derivadas exclusivamente del período de interrupción.
- V-575 Mantiene acontecimientos generados durante la contingencia integrados en la continuidad operativa.
- V-576 Trata automáticamente las condiciones de infraestructura que puedan resolverse automáticamente.
- V-577 Mantiene identificadas para atención técnica las condiciones que exceden capacidades automáticas.

### 9.11 Resiliencia y continuidad de la operación institucional

- V-578 Implementa resiliencia basada en distribución entre procesamiento local e infraestructura central.
- V-579 Implementa persistencia de acontecimientos, respaldo energético, capacidad de almacenamiento, sincronización diferida, adaptación de configuración y mecanismos de contingencia.
- V-580 Reconoce sus propios estados.
- V-581 Conserva información disponible.
- V-582 Diferencia una ausencia de datos de un acontecimiento real.
- V-583 Mantiene activo su procesamiento cuando corresponde.
- V-584 Proporciona mecanismos para recuperar la operación cuando una situación excede las condiciones ordinarias.

---

## CAPÍTULO 10 — MODELO DE IMPLEMENTACIÓN Y ADOPCIÓN INSTITUCIONAL

### 10.1 Caracterización del entorno y definición de los puntos de operación

- V-585 (proceso de implementación) Soporta visita de caracterización para conocer condiciones de incorporación.
- V-586 Permite determinar puntos de operación según organización de espacios, flujo de estudiantes y forma de registro requerida.
- V-587 Contempla volumen libre aproximado de 25 × 25 × 25 cm en el entorno inmediato del punto de operación.
- V-588 (instalación) Requiere alimentación eléctrica disponible dentro del espacio de operación y próxima a la entrada.

### 10.2 Preparación de la información y enrolamiento de los estudiantes

- V-589 Soporta carga de datos de estudiantes.
- V-590 Realiza jornada de enrolamiento biométrico.
- V-591 Obtiene dos huellas por estudiante.
- V-592 Genera la representación utilizada para identificación dentro de la infraestructura local.
- V-593 Incorpora casos no completados por procedimiento biométrico ordinario mediante mecanismos para situaciones excepcionales.

### 10.3 Preparación y asignación de los nodos

- V-594 Identifica individualmente cada dispositivo.
- V-595 Carga la información necesaria para funcionamiento local según configuración de la institución.
- V-596 Permite cargar la información previamente en cada nodo antes de su instalación física.

### 10.4 Instalación e integración de la infraestructura

- V-597 (instalación) Incorpora físicamente los nodos en los puntos definidos.
- V-598 Realiza conexión de alimentación eléctrica e integración de medios de comunicación.
- V-599 Realiza comprobación inicial del estado operativo y condiciones necesarias.

### 10.5 Configuración institucional y capacitación

- V-600 Permite configurar estructura de horarios y jornadas.
- V-601 Permite configurar organización de grupos y espacios.
- V-602 Permite configurar condiciones necesarias para análisis de eventos.
- V-603 Permite a profesores establecer condiciones de información para seguimiento de asistencia, llegadas tardías, posibles evasiones u otras situaciones.
- V-604 Permite a directivos definir métricas, avisos y parámetros de análisis.
- V-605 Permite configurar el motor de análisis y riesgo.

### 10.6 Pruebas integrales y marcha blanca

- V-606 Soporta pruebas funcionales y de integración.
- V-607 Soporta pruebas de carga y estrés.
- V-608 Soporta evaluación de comportamiento ante volúmenes de operación previstos.
- V-609 Soporta evaluación ante condiciones de concurrencia superiores a las habituales.
- V-610 Soporta pruebas de continuidad (funcionamiento local, persistencia, recuperación de comunicación, sincronización posterior).
- V-611 Soporta pruebas de contingencia de nodos y mecanismos manuales.
- V-612 Permite identificar y corregir inconsistencias de configuración, integración o funcionamiento.
- V-613 Soporta marcha blanca en dinámica institucional real.
- V-614 Permite contrastar configuración establecida con condiciones efectivas de la jornada.
- V-615 Permite realizar modificaciones cuando aparezcan diferencias entre configuración inicial y operación real.

### 10.7 Puesta en operación y acompañamiento inicial

- V-616 Permite transición a operación ordinaria tras la marcha blanca.
- V-617 Permite acompañamiento durante período inicial de operación.

---

## CAPÍTULO 11 — ESCALABILIDAD Y EVOLUCIÓN DEL SISTEMA

### 11.1 Continuidad de la configuración institucional

- V-618 Permite ajustar horarios, jornadas, grupos, espacios, métricas, avisos y parámetros de análisis cuando resulte necesario.
- V-619 Permite representar cada período académico o modificación relevante dentro del sistema.
- V-620 Permite incorporar nuevos estudiantes mediante herramientas disponibles para actores responsables.
- V-621 Evita que la incorporación de nuevas personas requiera intervención sobre la estructura general.
- V-622 Permite incorporar un nuevo espacio que requiere identificación mediante ampliación de la infraestructura.
- V-623 Conserva la lógica de funcionamiento ya establecida al ampliar.

### 11.2 Mantenimiento y mejora continua

- V-624 Soporta procesos de revisión, mantenimiento y actualización.
- V-625 Permite optimizaciones de procesos, automatizaciones adicionales, ajustes en mecanismos de análisis, mejoras de interacción y nuevas capacidades.
- V-626 Permite incorporar mejoras sobre la base tecnológica existente.
- V-627 Evita exigir reconstrucción de configuraciones, procesos o estructuras que ya funcionan.

### 11.3 Administración y depuración de la información

- V-628 Implementa procesos periódicos de depuración y limpieza.
- V-629 Permite retirar registros cuya permanencia haya alcanzado los límites definidos para su conservación.
- V-630 Permite programar tareas de depuración de manera controlada.
- V-631 Mantiene disponible información correspondiente a necesidades operativas y de conservación.
- V-632 Permite mantener la infraestructura preparada para procesar nuevos eventos sin convertir el crecimiento histórico en carga innecesaria.

### 11.4 Evolución tecnológica y permanencia de la infraestructura

- V-633 Permite actualizar componentes de software, procesamiento, comunicación y demás elementos tecnológicos.
- V-634 Permite introducir cambios sobre base ya establecida conservando estructura general de la operación.
- V-635 Permite mantener actualizado el sistema mediante mejoras progresivas y técnicamente controladas.

### 11.5 Capacidad de ampliación y adaptación

- V-636 Permite ampliar progresivamente infraestructura y capacidades.
- V-637 Permite incorporación de nuevos puntos de operación, nuevos elementos y aumento de capacidades sobre la estructura existente.
- V-638 Conserva configuración y servicios en funcionamiento al ampliar.
- V-639 Permite crecimiento gradual sin alterar la totalidad de la infraestructura previamente implementada.

### 11.6 Evolución continua del sistema

- V-640 Mantiene dinámica continua de soporte, actualización, optimización y mejora.
- V-641 Conserva utilidad a medida que cambian necesidades institucionales y tecnológicas.
- V-642 Permite crecer y transformarse sin perder la estructura que articula la información, la automatización y la operación institucional.

---

## CAPÍTULO 12 — CONCLUSIONES

- V-643 Mantiene relación funcional entre aquello que sucede, la información que produce, el contexto necesario para comprenderlo y la capacidad institucional para actuar.
- V-644 Conserva continuidad de la información donde antes podía fragmentarse.
- V-645 Permite que un acontecimiento adquiera contexto, que varios acontecimientos relacionados revelen una condición y que la condición llegue oportunamente al actor responsable.
- V-646 Asume operaciones susceptibles de sistematización (registrar, organizar, relacionar, procesar y comunicar información).
- V-647 Permite al profesional recibir condiciones de información y concentrarse en interpretar y determinar la actuación.
- V-648 NO sustituye el criterio profesional.
- V-649 Mantiene separación estricta entre lo que NEXO conoce y aquello que no puede establecer por ausencia de información.
- V-650 Continúa relacionando acontecimientos registrados sin convertir una condición desconocida en hecho.
- V-651 Permite ajustes a necesidades institucionales y mantenimiento durante el ciclo de vida.
- V-652 Permite incorporar nuevas necesidades, perfeccionar procesos y desarrollar nuevas capacidades sin perder la estructura que articula la operación institucional.

---

## RESUMEN

- Total de verificaciones enumeradas en este documento: **652** (V-001 a V-652).
- El documento original declara 651; el conteo exacto sobre la lista entregada arroja 652 ítems atómicos.
- Organizadas por los 12 capítulos del documento.
- Resultados detallados por bloque de 50 en `ANALISIS_AUDITORIA.md`.
- Plan de correcciones priorizado por dependencias en `PLAN_CORRECCIONES.md`.





