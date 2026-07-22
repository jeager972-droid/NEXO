<!--
  WebApp build instructions / NEXO Institucional
  Responsabilidad: Guía rápida para compilar el cliente web con Tauri 2.0 en
  Windows, macOS, Linux, Android e iOS.
  Dependencias: npm install; Tauri CLI y SDK instalados.
-->
# Comandos de Compilación Nativa - NEXO (Tauri 2.0)

## Preparación
`npm install` 

## Compilación Escritorio (Se compila para el SO actual)
Windows (Genera .exe / .msi): `npm run tauri build` 
macOS (Genera .dmg / .app): `npm run tauri build` 
Linux (Genera .deb / .AppImage): `npm run tauri build` 

## Compilación Móvil
Android (Genera .apk / .aab): 
`npx tauri android init` 
`npx tauri android build` 

iOS (Requiere Xcode y macOS):
`npx tauri ios init` 
`npx tauri ios build` 
