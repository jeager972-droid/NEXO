# Estrategia de Despliegue y Arquitectura MVP

## Contexto Actual (Fase MVP)
Actualmente, el proyecto NEXO se encuentra en una fase de MVP (Minimum Viable Product). Por motivos de presupuesto y la falta temporal de un dominio propio dedicado, la estructura de despliegue está diseñada de la siguiente manera:

1. **Backend y Landing Page (Juntos):** 
   Debido a las limitaciones actuales, el backend (API en PHP) y la Landing Page promocional operan de manera acoplada. Esto permite ahorrar costos en infraestructura manteniendo una demostración funcional de NEXO. 
   
2. **WebApp (Separada):**
   La aplicación principal (WebApp), que contiene toda la lógica de los distintos módulos institucionales, se mantiene separada de la Landing y del alojamiento del backend. Esto asegura que la lógica crítica del sistema (rutas protegidas, operaciones, etc.) no se vea comprometida y esté lista para escalar.

## Futuro del Proyecto
Una vez que se cuente con el capital para adquirir dominios dedicados y mejor infraestructura, la arquitectura evolucionará para tener:
- Un dominio exclusivo y optimizado para la **Landing Page** (sirviendo como portfolio/carta de presentación).
- Un subdominio/servidor dedicado para la **API del Backend** (garantizando máxima seguridad y rendimiento).
- Un dominio o despliegue independiente para la **WebApp**.

Hasta entonces, el repositorio operará bajo esta estructura transitoria para minimizar gastos mientras se valida el producto en su fase inicial.
