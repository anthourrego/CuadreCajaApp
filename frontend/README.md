# CuadreCaja - frontend

## Desarrollo local

1. Configura y ejecuta el backend Laravel en `http://127.0.0.1:8000` con `php artisan serve`. Crea un usuario con `php artisan app:create-admin` si aún no existe.
2. En esta carpeta, ejecuta `npm install` y `npm run dev` (`npm.cmd` en PowerShell si la política de ejecución bloquea `npm.ps1`).
3. Abre la dirección que muestra Vite e inicia sesión con el nombre de usuario y la contraseña del usuario creado.

Vite redirige las solicitudes `/api` al backend local. Para publicar la aplicación, configura el servidor web o proxy inverso para que `/api` llegue a Laravel bajo el mismo origen y utiliza HTTPS; el proxy de Vite solo se aplica en desarrollo.
Si el backend local usa otro puerto, establece la variable de entorno `API_PROXY_TARGET` antes de iniciar Vite, por ejemplo `http://127.0.0.1:8001`.

La sesión utiliza un token de Sanctum en `sessionStorage`: persiste al recargar la misma pestaña, pero no al cerrarla. Cerrar sesión desde la aplicación también revoca el token en el servidor. Mantén el sitio protegido frente a XSS y no utilices esta configuración como sustituto de una auditoría de seguridad.

Tras iniciar sesión se muestran las secciones disponibles según los permisos del usuario. En Configuración > Usuarios se pueden crear, editar, desactivar y reactivar cuentas y asignar un único rol delegable a cada usuario. Desactivar un usuario revoca sus sesiones; cambiar su contraseña también. El registro de ventas, gastos y cuadres aún no está implementado.

En pantallas pequeñas, la navegación se abre como un panel lateral desde el botón de menú y se cierra al seleccionar una opción, al tocar fuera o con Escape.

En Configuración > Roles se gestionan los permisos y las sucursales de cada rol. La administración puede delegarse únicamente dentro del alcance propio; el rol administrador está protegido y mantiene acceso global. Los permisos de cajas distinguen entre todas y las asignadas individualmente. Los roles con usuarios o sucursales asignados no se pueden eliminar.

En Configuración > Sucursales pueden crearse, editarse, desactivarse o reactivarse sucursales según los permisos; el acceso por rol se configura en Roles, no en la sucursal. Al desactivar una sucursal se conservan sus datos y asignaciones, pero no se pueden crear ni trasladar cajas o cuentas a ella.

En Cajas se pueden consultar y filtrar las cajas por sucursal y, según los permisos del rol en cada sucursal, crearlas, editarlas, desactivarlas o reactivarlas. En Configuración > Usuarios se puede elegir una sucursal por defecto entre las sucursales activas del rol. Cajas la usa como filtro inicial; cambiar el filtro no cambia la preferencia ni los permisos. Si la sucursal ya no está activa o autorizada, se muestran todas las cajas disponibles. La creación requiere una sucursal activa autorizada. El código de caja es único dentro de su sucursal, pero puede repetirse en otras. La edición desde esta pantalla conserva la sucursal y las asignaciones de usuarios existentes; no incluye asignación de responsables.

En Configuración > Ubicaciones, los usuarios autorizados pueden crear, editar, desactivar y reactivar departamentos y municipios. Los códigos existentes no se pueden cambiar. Las ubicaciones desactivadas conservan las asociaciones existentes, pero no se pueden asignar a nuevas sucursales. El catálogo parte de DIVIPOLA con corte de 2024, admite ediciones manuales y no se actualiza automáticamente.

Las confirmaciones para desactivar o reactivar usuarios y ubicaciones usan SweetAlert2.

Para validar: `npm run build` y `npm run lint`.
