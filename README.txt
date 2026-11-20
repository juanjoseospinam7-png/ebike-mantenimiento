====================================================================
E-BIKE MAINTENANCE SYSTEM (EMS) — SISTEMA INTEGRAL DE TALLER
====================================================================

## 1. REQUISITOS DEL SISTEMA
- XAMPP / WAMP / LAMP (o cualquier servidor con PHP 8.1+ y MySQL / MariaDB).
- Extensión PDO MySQL habilitada en php.ini.
- Navegador web moderno (Chrome, Edge, Firefox, Safari).

## 2. INSTRUCCIONES DE INSTALACIÓN EN XAMPP
1. Copia o descomprime la carpeta `ebike_mantenimiento` dentro de:
   `C:\xampp\htdocs\`
2. Inicia los módulos **Apache** y **MySQL** desde el Panel de Control de XAMPP.
3. Abre tu navegador web y entra a phpMyAdmin:
   `http://localhost/phpmyadmin/`
4. Base de datos:
   - **Instalación Limpia:** Crea la base de datos `ebike_taller` e importa el archivo:
     `database/ebike_completo.sql`
   - **Actualización de base existente:** Si ya tenías datos previos en `ebike_taller`, importa:
     `database/actualizacion.sql` (no destructivo, conserva todos los datos existentes).
5. Configuración de conexión (si tu MySQL root tiene contraseña):
   Edita `config/database.php` y ajusta `DB_PASS`. Por defecto en XAMPP viene sin contraseña (`''`).
6. Accede al sistema desde tu navegador en:
   `http://localhost/ebike_mantenimiento/`

---

## 3. CREDENCIALES INICIALES DE ACCESO

### A) PERFIL ADMINISTRADOR
- **Usuario:** `admin`
- **Contraseña:** `admin123`
- **Rol:** Administrador General
- **Acceso:** Dashboard con KPIs y gráficos, Contabilidad, Recepción de Reportes, Asignación de Mecánicos, Mantenimientos, Inventario, Flota de Bicicletas, Usuarios, Auditoría y Configuración.

### B) PERFIL MECÁNICO DE PRUEBAS
- **Usuario:** `carlos.gomez`
- **Contraseña:** `mecanico123`
- **Rol:** Mecánico Técnico
- **Acceso:** Panel del Mecánico, Órdenes Asignadas, Diagnóstico Técnico, Trabajos Realizados, Repuestos Utilizados, Mano de Obra y Actualización de los 9 Estados.
*(Nota: Si creas nuevos mecánicos desde cuentas.php, se vincularán automáticamente).*

### C) PERFIL CLIENTE DE PRUEBAS
- **Usuario:** `cliente.demo`
- **Contraseña:** `cliente123`
- **Rol:** Cliente Particular
- **Acceso:** Portal del Cliente, Registro de Bicicletas Propias, Formulario de Reporte de Fallas con Fotografías, Seguimiento en Vivo con Código (`REP-XXXX`), Consulta de Presupuestos y Diagnósticos.
*(Los clientes nuevos también pueden registrarse de forma autónoma desde `registro.php`).*

---

## 4. SISTEMA DE USUARIOS Y ROLES (RBAC)

1. **ADMINISTRADOR:**
   - Acceso total e irrestricto.
   - Creación de nuevos administradores (únicamente permitido por administradores autorizados).
   - Creación y administración de mecánicos y personal técnico.
   - Consulta, edición, activación/desactivación de cuentas y restablecimiento seguro de contraseñas.
   - Recepción y asignación de reportes de clientes a mecánicos específicos.
   - Módulo contable integral (ingresos, gastos, utilidad neta en COP y exportación Excel).
   - Generación de copias de seguridad de la base de datos (Backup SQL en 1 clic).

2. **MECÁNICO:**
   - Espacio de trabajo técnico enfocado en la reparación.
   - Bandeja de reportes asignados con notificaciones internas.
   - Modificación estricta únicamente de las órdenes autorizadas a su usuario.
   - Flujo de los 9 estados normativos:
     1. Recibido
     2. Pendiente de revisión
     3. En diagnóstico
     4. Esperando repuestos
     5. En reparación
     6. En pruebas
     7. Reparado
     8. Entregado
     9. Cancelado
   - Registro de diagnóstico, trabajos realizados, observaciones y mano de obra.
   - Selección múltiple de repuestos con descuento automático de inventario.
   - Historial individual de servicios finalizados.

3. **CLIENTE:**
   - Portal web independiente, limpio y seguro.
   - Registro público e inicio de sesión.
   - Consulta y administración de sus eBikes.
   - Formulario de reporte de fallas en 10 categorías técnicas con carga de fotografías.
   - Generación automática de número único de seguimiento (ej. `REP-2026-A1B2C3`).
   - Consulta en vivo del avance (`seguimiento.php`), presupuestos en COP, diagnósticos y fecha de entrega.
   - Aislamiento total: Bloqueo estricto a áreas administrativas, contabilidad o información de otros clientes.

---

## 5. MÓDULOS DEL SISTEMA

1. **Dashboard Administrativo (`index.php`):**
   - 6 tarjetas estadísticas con datos reales de MySQL: Total eBikes, reportes recibidos/pendientes, en reparación, reparadas, entregadas, ingresos del día/mes, gastos del mes y utilidad neta en COP.
   - Gráficos interactivos Chart.js de distribución por estados.
   - Bandejas de accesos directos y órdenes recientes.

2. **Recepción & Reportes de Bicicletas (`reportes_admin.php`):**
   - Control de solicitudes recibidas desde el portal del cliente.
   - Asignación de mecánico con notificación automática interna.
   - Filtros por estado, categoría técnica y buscador general.

3. **Mantenimientos de eBikes (`mantenimientos.php` y `api/mantenimiento.php`):**
   - Gestión integral de órdenes de servicio técnico.
   - Selector interactivo de múltiples repuestos con stock en tiempo real y cálculo automático de subtotales.
   - Registro de mano de obra, descuentos y totalización en COP.
   - Trazabilidad y cambio de los 9 estados normativos.
   - Generación de Ficha Técnica / Recibo imprimible (`imprimir_orden.php`).

4. **Contabilidad & Finanzas (`contabilidad.php` y `api/gasto.php`):**
   - Separación estricta entre Ingresos (mano de obra + repuestos + servicios) y Gastos (compras, herramientas, operativos).
   - Cálculo automático de Utilidad Neta (Ingresos - Gastos) sin duplicaciones.
   - Filtros temporales: Diario, Semanal, Mensual, Anual y Rango Personalizado.
   - Métodos de pago en COP (Efectivo, Transferencia, Nequi/Daviplata, Tarjetas).
   - Gráficos semestrales de tendencia financiera y desglose de ingresos.
   - Exportador a Excel / CSV con BOM UTF-8 (`api/exportar_contabilidad.php`).

5. **Inventario & Repuestos (`inventario.php` y `api/inventario.php`):**
   - Catálogo de piezas con código, categoría, proveedor, costo de adquisición y precio de venta.
   - Control de stock mínimo con alertas visuales de stock bajo y agotado.
   - Kardex de movimientos de inventario (entradas por compras y salidas automáticas por reparación).
   - Prohibición estricta de existencias negativas bajo transacciones PDO.

6. **Portal del Cliente (`cliente.php`, `mis_bicicletas.php`, `reportar.php`, `seguimiento.php`):**
   - Panel de control para el usuario final.
   - Solicitud de servicios con selección de eBike propia o registro inmediato.
   - Subida segura de fotografías con validación MIME.
   - Consulta pública/privada de seguimiento con línea de tiempo visual.

7. **Gestión de Usuarios & Personal (`cuentas.php` y `api/cuenta.php`):**
   - Listado de Administradores, Mecánicos y Clientes.
   - Creación segura de Administradores y Mecánicos vinculados a empleados.
   - Edición de información, conmutador de estado (Activar/Desactivar) y reseteo de claves.

8. **Auditoría & Trazabilidad (`auditoria.php`):**
   - Registro inmutable de transiciones de estado, quién realizó la acción, fecha, hora y detalles.

9. **Configuración del Taller & Copias de Seguridad (`configuracion.php` y `api/backup.php`):**
   - Personalización de datos de la empresa, NIT, teléfono, correo y garantía.
   - Generador descargable de Backup SQL completo en un solo clic.

---

## 6. SEGURIDAD APLICADA
- **Consultas Preparadas PDO:** En el 100% de consultas para eliminar vulnerabilidades de Inyección SQL.
- **Protección CSRF:** Tokens criptográficos obligatorios en todos los formularios POST.
- **Hashing Seguro de Contraseñas:** `password_hash()` con algoritmo BCRYPT y verificación con `password_verify()`.
- **Control de Acceso Basado en Roles (RBAC):** Validación en servidor en cada controlador y endpoint de API.
- **Subida Segura de Archivos:** Validación de tipos MIME reales (`finfo`), restricción a JPG, PNG y WEBP, nombres aleatorios seguros, límite de 5 MB y protección `.htaccess` en carpeta `assets/uploads/`.
- **Sanitización XSS:** Escape estricto con `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` en todas las salidas.

---

## 7. RESULTADO DE LAS 19 PRUEBAS OBLIGATORIAS
Ejecutadas satisfactoriamente mediante el script de pruebas automatizado:
1. ✅ Crear un administrador
2. ✅ Crear un mecánico
3. ✅ Registrar un cliente
4. ✅ Iniciar sesión con los tres perfiles
5. ✅ Crear una bicicleta
6. ✅ Crear un reporte como cliente
7. ✅ Comprobar que el administrador recibe el reporte
8. ✅ Asignar el reporte a un mecánico
9. ✅ Comprobar que aparece en el panel del mecánico
10. ✅ Cambiar el estado de la reparación (los 9 estados normativos)
11. ✅ Registrar diagnóstico
12. ✅ Agregar mano de obra
13. ✅ Utilizar repuestos del inventario (descuento automático y validación anti-negativos)
14. ✅ Registrar un pago
15. ✅ Comprobar los cálculos contables (Utilidad Neta en COP)
16. ✅ Consultar informes diarios, semanales y mensuales
17. ✅ Comprobar que los clientes no pueden acceder a información administrativa
18. ✅ Comprobar que los mecánicos no pueden crear administradores
19. ✅ Validaciones y seguridad (CSRF, contraseñas hash, stock positivo)

Resultado: **19 de 19 pruebas aprobadas exitosamente.**
