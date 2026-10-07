/**
 * screenshot_docs.mjs — Documentación visual UI/UX de NEXO.
 *
 * Captura cada pantalla/sección del frontend en móvil y PC usando el
 * Chromium del sistema vía playwright-core. Guarda en docs/ui/screenshots/
 *   <device>/<rol>-<seccion>.png  +  chatbot/ (casos de mensajes de Nodus).
 *
 * Uso:
 *   VITE dev server corriendo en 127.0.0.1:15173 contra el stack de pruebas
 *   (VITE_API_BASE_URL=http://localhost:18080 npx vite --port 15173)
 *   node test/e2e/screenshot_docs.mjs [baseUrl] [outDir]
 */
import { chromium } from 'playwright-core';
import { mkdirSync } from 'fs';
import { join } from 'path';

const BASE = process.argv[2] || 'http://127.0.0.1:15173/app';
const OUT = process.argv[3] || new URL('../../../docs/ui/screenshots/', import.meta.url).pathname;
const CHROMIUM = '/usr/local/bin/chromium';

const USERS = {
  rector: { email: 'rector@test.nexo', pass: 'test1234' },
  coordinador: { email: 'coord@test.nexo', pass: 'test1234' },
  docente: { email: 'teach@test.nexo', pass: 'test1234' },
  secretaria: { email: 'sec@test.nexo', pass: 'test1234' },
};

// Rutas por rol — solo las que el rol puede ver (ProtectedRoute filtra).
const ROUTES = {
  rector: [
    ['inicio', '/'], ['operaciones', '/operacion'], ['notificaciones', '/notificaciones'],
    ['chat', '/chat'], ['seguimientos', '/casos'], ['sensores', '/dispositivos'],
    ['perfil', '/perfil'],
  ],
  coordinador: [
    ['inicio', '/'], ['operaciones', '/operacion'], ['notificaciones', '/notificaciones'],
    ['chat', '/chat'], ['seguimientos', '/casos'], ['perfil', '/perfil'],
  ],
  docente: [
    ['inicio', '/'], ['operaciones', '/operacion'], ['notificaciones', '/notificaciones'],
    ['chat', '/chat'], ['perfil', '/perfil'],
  ],
  secretaria: [
    ['inicio', '/'], ['operaciones', '/operacion'], ['notificaciones', '/notificaciones'],
    ['chat', '/chat'], ['enrolamiento', '/enrolamiento'], ['perfil', '/perfil'],
  ],
};

// Deep-links de operaciones (formularios precargados) — coordinador.
const OP_FORMS = [
  ['op-seguimiento', '/operacion?cmd=Solicitar%20seguimiento'],
  ['op-citacion', '/operacion?cmd=Citar%20acudiente'],
  ['op-emergencia', '/operacion?cmd=Emergencia'],
  ['op-horario', '/operacion?cmd=Cambio%20de%20horario'],
  ['op-pedagogica', '/operacion?cmd=Salida%20pedag%C3%B3gica'],
];

// Casos de conversación del chatbot para la subcarpeta chatbot/.
const CHAT_CASES = [
  ['chat-inicio', null],
  ['chat-consulta', 'cuantas tardanzas hubo hoy'],
  ['chat-operacion', 'quiero citar al acudiente de tomás castaño'],
  ['chat-riesgo', 'que estudiantes estan en riesgo'],
];

const DEVICES = {
  movil: { width: 390, height: 844, isMobile: true, hasTouch: true, userAgent:
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' },
  pc: { width: 1440, height: 900 },
};

const COOKIE_CONSENT = JSON.stringify({
  version: '1.0', timestamp: new Date().toISOString(),
  categories: { necessary: true, preferences: true, analytics: true, marketing: false },
});

const shot = async (page, file) => {
  await page.waitForTimeout(900); // animaciones + fetch inicial
  await page.screenshot({ path: file, fullPage: false });
  console.log('  ✓', file.split('screenshots/')[1]);
};

const acceptLegal = async (page) => {
  // Términos (si aparece) → cookies (card compacta)
  for (let i = 0; i < 4; i++) {
    const terms = page.getByRole('button', { name: /aceptar y continuar/i });
    const cookies = page.getByRole('button', { name: /aceptar todas/i });
    if (await terms.count()) { await terms.first().click(); await page.waitForTimeout(700); continue; }
    if (await cookies.count()) { await cookies.first().click(); await page.waitForTimeout(700); continue; }
    break;
  }
};

const login = async (page, u) => {
  await page.addInitScript((c) => { try { localStorage.setItem('nexo_cookie_consent', c); } catch {} }, COOKIE_CONSENT);
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    if (!/\/login/.test(page.url())) break; // ya autenticado: /login redirige
    const email = page.locator('input[type="email"]').first();
    const pass = page.locator('input[type="password"]').first();
    await email.fill(u.email);
    await pass.fill(u.pass);
    await page.getByRole('button', { name: /entrar|ingresar|iniciar/i }).first().click();
    try {
      await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 12000 });
      break;
    } catch {
      if (attempt === 3) throw new Error(`login no redirigió para ${u.email} (rate limit?)`);
      await page.waitForTimeout(4000); // backoff por rate limit
    }
  }
  await page.waitForTimeout(1200);
  await acceptLegal(page);
};

const run = async () => {
  mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });

  for (const [devName, dev] of Object.entries(DEVICES)) {
    for (const [role, u] of Object.entries(USERS)) {
      const ctx = await browser.newContext({ viewport: { width: dev.width, height: dev.height }, ...dev });
      const page = await ctx.newPage();
      try {
        await login(page, u);
        for (const [name, path] of ROUTES[role]) {
          await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch(() => {});
          await acceptLegal(page);
          await shot(page, join(OUT, devName, `${role}-${name}.png`));
        }
        // Formularios de operación (solo coordinador, desktop y móvil)
        if (role === 'coordinador') {
          for (const [name, path] of OP_FORMS) {
            await page.goto(`${BASE}${path}`, { waitUntil: 'networkidle' }).catch(() => {});
            await page.waitForTimeout(600);
            await shot(page, join(OUT, devName, `${role}-${name}.png`));
          }
        }
      } catch (e) {
        console.log(`  ✗ ${devName}/${role}: ${e.message.slice(0, 120)}`);
      }
      await ctx.close();
    }
  }

  // ── Casos del chatbot (coordinador) ──
  for (const [devName, dev] of Object.entries(DEVICES)) {
    const ctx = await browser.newContext({ viewport: { width: dev.width, height: dev.height }, ...dev });
    const page = await ctx.newPage();
    try {
      await login(page, USERS.coordinador);
      await page.goto(`${BASE}/chat`, { waitUntil: 'networkidle' });
      for (const [name, msg] of CHAT_CASES) {
        if (msg) {
          const input = page.locator('input[placeholder*="Nodus"], form input').last();
          await input.fill(msg);
          await input.press('Enter');
          await page.waitForTimeout(3500);
        }
        await shot(page, join(OUT, 'chatbot', `${devName}-${name}.png`));
      }
    } catch (e) {
      console.log(`  ✗ chatbot/${devName}: ${e.message.slice(0, 120)}`);
    }
    await ctx.close();
  }

  await browser.close();
  console.log('Listo →', OUT);
};

run().catch((e) => { console.error(e); process.exit(1); });
