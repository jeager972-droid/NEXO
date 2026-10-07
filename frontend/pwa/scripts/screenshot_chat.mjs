import { chromium } from 'playwright-core';
import { mkdirSync } from 'fs';
import { join } from 'path';

const BASE = 'http://127.0.0.1:15173/app';
const OUT = new URL('../../../docs/ui/screenshots/chatbot/', import.meta.url).pathname;

const CASES = [
  ['chat-consulta', 'cuantas tardanzas hubo hoy'],
  ['chat-operacion', 'quiero citar al acudiente de tomás castaño'],
  ['chat-riesgo', 'que estudiantes estan en riesgo'],
];
const DEVICES = {
  movil: { width: 390, height: 844, isMobile: true, hasTouch: true },
  pc: { width: 1440, height: 900 },
};
const COOKIE = JSON.stringify({ version: '1.0', timestamp: new Date().toISOString(),
  categories: { necessary: true, preferences: true, analytics: true, marketing: false } });

const browser = await chromium.launch({ executablePath: '/usr/local/bin/chromium', args: ['--no-sandbox'] });
mkdirSync(OUT, { recursive: true });

for (const [devName, dev] of Object.entries(DEVICES)) {
  const ctx = await browser.newContext({ viewport: { width: dev.width, height: dev.height }, ...dev });
  const page = await ctx.newPage();
  await page.addInitScript((c) => { try { localStorage.setItem('nexo_cookie_consent', c); } catch {} }, COOKIE);
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
  await page.locator('input[type="email"]').first().fill('coord@test.nexo');
  await page.locator('input[type="password"]').first().fill('test1234');
  await page.getByRole('button', { name: /entrar|ingresar|iniciar/i }).first().click();
  await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 15000 });
  await page.waitForTimeout(1500);
  // términos → cookies si aparecen
  const t = page.getByRole('button', { name: /aceptar y continuar/i });
  if (await t.count()) { await t.first().click(); await page.waitForTimeout(800); }
  const c = page.getByRole('button', { name: /aceptar todas/i });
  if (await c.count()) { await c.first().click(); await page.waitForTimeout(800); }

  await page.goto(`${BASE}/chat`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
  for (const [name, msg] of CASES) {
    const input = page.locator('input[aria-label="Mensaje para Nodus"]');
    await input.fill(msg);
    await input.press('Enter');
    // espera a que la respuesta del backend llegue (loader desaparece)
    await page.waitForTimeout(6000);
    await page.screenshot({ path: join(OUT, `${devName}-${name}.png`) });
    console.log('  ✓', `${devName}-${name}.png`);
  }
  await ctx.close();
}
await browser.close();
console.log('listo');
