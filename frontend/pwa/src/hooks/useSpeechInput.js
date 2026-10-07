/**
 * useSpeechInput / NEXO Institucional
 * Dictado por voz para el chat de Nodus (Web Speech API).
 *
 * El reconocimiento lo presta el navegador del usuario — Chrome envía el
 * audio a su propio servicio de voz, Safari/iOS al de Apple. NEXO nunca
 * recibe ni almacena audio: solo llega el texto ya transcrito. Por eso la
 * entrada de voz es opt-in por feature-detect: si la API no existe en el
 * dispositivo, `supported` es false y el caller simplemente no muestra el
 * botón — sin polifills ni degradación visible.
 */
import { useCallback, useEffect, useRef, useState } from 'react';

const getEngine = () =>
  typeof window !== 'undefined'
    ? window.SpeechRecognition || window.webkitSpeechRecognition || null
    : null;

/**
 * onResult(text, isFinal) recibe la transcripción acumulada de la sesión de
 * dictado. interimResults va en true para que el usuario vea el texto
 * formarse en el input en vivo; el envío siempre lo decide él.
 */
export const useSpeechInput = ({ onResult } = {}) => {
  const supported = getEngine() !== null;
  const [listening, setListening] = useState(false);
  const recRef = useRef(null);
  // onResult viaja por ref: el caller re-renderiza con cada letra dictada y
  // la sesión de reconocimiento no debe recrearse en cada render.
  const onResultRef = useRef(onResult);
  onResultRef.current = onResult;

  const stop = useCallback(() => {
    try { recRef.current?.stop(); } catch { /* ya detenido — onend limpia */ }
  }, []);

  const start = useCallback(() => {
    const Engine = getEngine();
    if (!Engine || recRef.current) return;
    const rec = new Engine();
    rec.lang = 'es-CO';
    rec.interimResults = true;
    rec.continuous = false;
    rec.onresult = (e) => {
      // results acumula toda la sesión (final + interim); se re-emite entera
      // para que el input siempre refleje la frase completa, no el fragmento.
      let transcript = '';
      let final = false;
      for (let i = 0; i < e.results.length; i += 1) {
        transcript += e.results[i][0]?.transcript ?? '';
        if (e.results[i].isFinal) final = true;
      }
      onResultRef.current?.(transcript.replace(/^\s+/, ''), final);
    };
    // onend también llega tras stop() y tras errores fatales — único punto
    // donde se libera la sesión para que no quede un micro zombie.
    rec.onend = () => { recRef.current = null; setListening(false); };
    rec.onerror = () => {
      try { rec.stop(); } catch { /* abort limpio si stop no aplica */ }
      recRef.current = null;
      setListening(false);
    };
    recRef.current = rec;
    try {
      rec.start();
      setListening(true);
    } catch {
      recRef.current = null;
      setListening(false);
    }
  }, []);

  const toggle = useCallback(() => {
    if (listening || recRef.current) stop();
    else start();
  }, [listening, start, stop]);

  // Si el componente se desmonta, el micrófono no queda escuchando en
  // segundo plano con el input ya fuera de pantalla.
  useEffect(() => () => stop(), [stop]);

  return { supported, listening, start, stop, toggle };
};
