#!/bin/bash
# Script de inicio para NEXO Landing Page

set -e

echo "🚀 NEXO Landing Page — Inicio"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Verificar que estamos en el directorio correcto
if [ ! -f "serve.py" ]; then
    echo "❌ ERROR: Ejecuta este script desde /backend/alojamiento/"
    exit 1
fi

# Verificar que el build existe
if [ ! -f "frontend/dist/index.html" ]; then
    echo "⚠️  Build no encontrado. Compilando..."
    cd frontend
    npm run build
    cd ..
fi

# Crear el enlace simbólico para assets-3d en dist/ si no existe
echo "🔗 Verificando enlace simbólico assets-3d..."
mkdir -p frontend/dist
if [ ! -L "frontend/dist/assets-3d" ] && [ ! -d "frontend/dist/assets-3d" ]; then
    cd frontend/dist
    ln -sf ../../assets assets-3d
    cd ../..
    echo "  ✅ Enlace simbólico assets-3d creado"
else
    echo "  ✅ Enlace simbólico assets-3d ya existe"
fi

# Verificar assets críticos
echo "🔍 Verificando assets..."
MISSING=0

check_file() {
    if [ ! -f "$1" ]; then
        echo "  ❌ Falta: $1"
        MISSING=1
    else
        echo "  ✅ $1"
    fi
}

check_file "assets/models/nodo.glb"
check_file "assets/models/planeta.glb"
check_file "assets/environments/studio_small_08_2k.exr"

if [ $MISSING -eq 1 ]; then
    echo ""
    echo "⚠️  Algunos assets faltan. La página puede verse negra."
    echo "   Continúa de todas formas? (y/n)"
    read -r response
    if [[ ! "$response" =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ Todo listo"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Iniciando servidor en http://localhost:8000"
echo ""
echo "Presiona Ctrl+C para detener"
echo ""

# Iniciar servidor
python3 serve.py
