#!/usr/bin/env python3
"""
Servidor HTTP simple para NEXO Landing Page
Sirve frontend/dist/ como raíz y assets/ para los modelos 3D
"""

import http.server
import socketserver
import os
import sys
from pathlib import Path
from urllib.parse import unquote

PORT = 8000
BASE_DIR = Path(__file__).parent.resolve()
DIST_DIR = BASE_DIR / "frontend" / "dist"
ASSETS_DIR = BASE_DIR / "assets"

class NexoHTTPRequestHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(BASE_DIR), **kwargs)
    
    def translate_path(self, path):
        """
        Traduce las rutas:
        - / → frontend/dist/index.html
        - /assets/* → assets/*
        - /assets/index-*.js → frontend/dist/assets/index-*.js
        """
        # Decodificar URL
        path = unquote(path.split('?', 1)[0].split('#', 1)[0])
        
        # Normalizar
        if path.startswith('/'):
            path = path[1:]
        
        # Si es raíz, servir index.html
        if not path or path == '':
            return str(DIST_DIR / "index.html")
        
        # Traducir prefijo assets-3d/ a assets/ para buscar en la carpeta física real
        if path.startswith('assets-3d/'):
            path = 'assets/' + path[len('assets-3d/'):]
        
        # Si es /assets/models/, /assets/environments/, /assets/textures/, /assets/texturas-arena/ o /assets/logo/ → carpeta assets/
        if (path.startswith('assets/models/') or 
            path.startswith('assets/environments/') or 
            path.startswith('assets/textures/') or 
            path.startswith('assets/texturas-arena/') or
            path.startswith('assets/logo/')):
            return str(BASE_DIR / path)
        
        # Si es /assets/*.js o /assets/*.css → frontend/dist/assets/
        if path.startswith('assets/') and (path.endswith('.js') or path.endswith('.css')):
            return str(DIST_DIR / path)
        
        # Resto desde dist/
        full_path = DIST_DIR / path
        if full_path.exists():
            return str(full_path)
        
        # Fallback a index.html (SPA routing)
        return str(DIST_DIR / "index.html")
    
    def end_headers(self):
        # MIME types
        if self.path.endswith('.glb'):
            self.send_header('Content-Type', 'model/gltf-binary')
        elif self.path.endswith('.exr'):
            self.send_header('Content-Type', 'image/x-exr')
        elif self.path.endswith('.hdr'):
            self.send_header('Content-Type', 'image/vnd.radiance')
        
        # CORS
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Cache-Control', 'no-cache')
        
        super().end_headers()
    
    def log_message(self, format, *args):
        # Colorear logs
        status = args[1] if len(args) > 1 else '000'
        if status.startswith('2'):
            color = '\033[92m'  # Verde
        elif status.startswith('3'):
            color = '\033[93m'  # Amarillo
        elif status.startswith('4') or status.startswith('5'):
            color = '\033[91m'  # Rojo
        else:
            color = '\033[0m'
        
        sys.stderr.write(f"{color}[{self.log_date_time_string()}] {format % args}\033[0m\n")

if __name__ == '__main__':
    print("🚀 NEXO Landing Page Server")
    print("━" * 60)
    print(f"📍 Base:   {BASE_DIR}")
    print(f"📁 Dist:   {DIST_DIR}")
    print(f"🎨 Assets: {ASSETS_DIR}")
    print("━" * 60)
    print(f"🌐 http://localhost:{PORT}")
    print("━" * 60)
    print("Presiona Ctrl+C para detener\n")
    
    try:
        with socketserver.TCPServer(("0.0.0.0", PORT), NexoHTTPRequestHandler) as httpd:
            httpd.serve_forever()
    except KeyboardInterrupt:
        print("\n\n✅ Servidor detenido")
    except Exception as e:
        print(f"\n❌ Error: {e}")
