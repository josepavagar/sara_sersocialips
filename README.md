# 🛠 HelpDesk – Sistema de Tickets de Soporte
## Instalación en XAMPP

### Requisitos
- XAMPP con Apache y MySQL activos
- PHP 7.4 o superior

---

### Pasos de instalación

1. **Copia la carpeta** `helpdesk` dentro de `C:\xampp\htdocs\`
   ```
   C:\xampp\htdocs\helpdesk\
   ```

2. **Inicia XAMPP** (Apache + MySQL)

3. **Ejecuta el instalador** abriendo en tu navegador:
   ```
   http://localhost/helpdesk/install.php
   ```
   Esto creará la base de datos `helpdesk` y todas las tablas.

4. **Accede al sistema:**
   ```
   http://localhost/helpdesk/
   ```

---

### Archivos del proyecto
```
helpdesk/
├── install.php       ← Ejecutar una sola vez para instalar
├── index.php         ← Panel principal con lista de tickets
├── nuevo_ticket.php  ← Formulario para crear tickets
├── ver_ticket.php    ← Detalle de ticket (comentarios, cambio estado)
├── reportes.php      ← Reportes con gráficas
├── db.php            ← Configuración de base de datos
├── helpers.php       ← Funciones compartidas
├── style.css         ← Estilos
└── README.md         ← Este archivo
```

---

### Configuración de base de datos
Si tu MySQL tiene contraseña, edita `db.php`:
```php
define('DB_USER', 'root');   // Usuario MySQL
define('DB_PASS', '');       // Contraseña (vacío por defecto en XAMPP)
```

---

### Funcionalidades

**Tickets:**
- Crear tickets con título, descripción, categoría y prioridad
- Ver listado con filtros por estado, prioridad, categoría y búsqueda
- Ver detalle del ticket con historial de actividad
- Cambiar estado: Abierto → En Progreso → Resuelto → Cerrado
- Cambiar prioridad: Baja / Media / Alta / Crítica
- Asignar agente responsable
- Añadir comentarios con historial completo

**Reportes:**
- Filtro por rango de fechas
- KPIs: total, abiertos, en progreso, resueltos, tiempo promedio de resolución
- Gráfica de dona por estado
- Gráfica de dona por prioridad
- Línea de tendencia (últimos 30 días)
- Barras por categoría
- Ranking de agentes
- Tabla detallada exportable / imprimible

---

### Categorías disponibles
Hardware · Software · Red · Acceso · Correo · Otro

### Prioridades
Baja · Media · Alta · Crítica

### Estados
Abierto · En Progreso · Resuelto · Cerrado
