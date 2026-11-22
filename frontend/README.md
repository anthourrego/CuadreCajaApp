# CuadreCaja - frontend

## Desarrollo local

1. Configura y ejecuta el backend Laravel en `http://127.0.0.1:8000` con `php artisan serve`. Crea un usuario con `php artisan app:create-admin` si aún no existe.
2. En esta carpeta, ejecuta `npm install` y `npm run dev` (`npm.cmd` en PowerShell si la política de ejecución bloquea `npm.ps1`).
3. Abre la dirección que muestra Vite e inicia sesión con el nombre de usuario y la contraseña del usuario creado.

Vite redirige las solicitudes `/api` al backend local; este proxy solo se aplica en desarrollo.
Si el backend local usa otro puerto, establece la variable de entorno `API_PROXY_TARGET` antes de iniciar Vite, por ejemplo `http://127.0.0.1:8001`.

## Estilos

El frontend incorpora Tailwind CSS mediante su plugin de Vite. La migración es gradual: la estructura y la cabecera del espacio de trabajo usan utilidades de Tailwind, y la pantalla de acceso, la navegación lateral, el menú de usuario, las ventanas de perfil y contraseña, los botones, campos, tarjetas, listados compartidos y las pantallas de inicio, cuadres, sucursales, cajas, cuentas, usuarios, roles, ubicaciones y tipos de gasto usan utilidades mediante `@apply`. Los demás estilos existentes siguen en sus archivos CSS.

Los colores de marca y los tokens compartidos de botones, campos y paneles se definen en `src/index.css`. Para utilizar estas utilidades con `@apply` desde otro archivo CSS, referencia ese archivo con `@reference "./index.css"`.

Para evitar cambios globales durante la migración, se importan el tema y las utilidades de Tailwind sin Preflight. Los estilos CSS existentes no están en capas y tienen prioridad sobre las utilidades en caso de conflicto; al migrar un componente, retira las reglas equivalentes y comprueba su apariencia en escritorio y móvil. La navegación móvil conserva el punto de corte existente de 760 px.

## Producción

Ejecuta `npm run build` en esta carpeta y publica el contenido de `dist` en la carpeta pública del sitio. La compilación utiliza `VITE_API_ORIGIN` de `.env.production` y envía las solicitudes a `https://apicaudrecaja.gomariwms.com/api`. La URL se incorpora al compilar: si se cambia, hay que volver a compilar. No incluyas secretos en variables `VITE_`, ya que son públicas.

La versión del producto sigue SemVer. El frontend toma su versión de `frontend/package.json`; la API usa `APP_VERSION` (por defecto `0.1.0`) en `backend/.env` y la entrega en `GET /api/version` a usuarios autenticados. Ambas se muestran en el pie de página; mantenlas alineadas al publicar y etiqueta el repositorio completo como `vX.Y.Z` (por ejemplo, `v0.1.0`).

Como el frontend y la API están en dominios distintos, el backend debe permitir mediante CORS el origen HTTPS donde se publique el frontend. Si se usa un proxy bajo el mismo origen, `VITE_API_ORIGIN` puede dejarse vacío para utilizar rutas relativas `/api`.

La sesión utiliza un token de Sanctum en `sessionStorage`: persiste al recargar la misma pestaña, pero no al cerrarla. Cerrar sesión desde la aplicación también revoca el token en el servidor. Mantén el sitio protegido frente a XSS y no utilices esta configuración como sustituto de una auditoría de seguridad.

Tras iniciar sesión se muestran las secciones disponibles según los permisos del usuario. En Configuración > Usuarios se pueden crear, editar, desactivar y reactivar cuentas y asignar un único rol delegable a cada usuario. Desactivar un usuario revoca sus sesiones; cambiar su contraseña también.

Cada módulo utiliza una ruta con fragmento, por ejemplo `#/cuadres` o `#/usuarios`, que se conserva al recargar y admite los botones Atrás y Adelante del navegador sin configuración adicional del servidor. Las rutas desconocidas o no disponibles muestran un aviso y la pantalla de inicio; el acceso sigue sujeto a los permisos del usuario. Los filtros se conservan en la pestaña durante la sesión, incluso al cambiar de módulo o recargar; al cerrar sesión se eliminan. Los formularios no se conservan al recargar.

La sección Cuentas está oculta temporalmente en el frontend mediante `accountsSectionEnabled` en `Workspace.tsx`; la API y sus permisos no cambian.

En el menú del usuario de la cabecera se puede editar el perfil, cambiar la contraseña o cerrar sesión. El nombre de usuario se define al crear la cuenta y no puede modificarse. Los formularios de perfil y contraseña se abren en ventanas modales que se pueden cancelar o cerrar con Escape. El cambio de contraseña exige la contraseña actual y cierra todas las sesiones.

En escritorio, el botón en el borde del menú permite contraerlo o expandirlo; contraído, las opciones muestran su nombre al pasar el cursor o enfocarlas con el teclado. En pantallas pequeñas, la navegación se abre como un panel lateral desde el botón de menú y se cierra al seleccionar una opción, al tocar fuera o con Escape.

En Configuración > Roles se gestionan los permisos agrupados por módulo, indicando si son globales o por sucursal. Cada módulo permite seleccionar todos los permisos que el gestor puede delegar; los demás aparecen deshabilitados. En Configuración > Usuarios se asignan las sucursales a cada usuario. Para consultar cajas basta tener asignada la sucursal; las demás acciones requieren además el permiso correspondiente. La administración puede delegarse únicamente dentro del alcance propio; el rol administrador está protegido y mantiene acceso global. Los roles con usuarios asignados no se pueden eliminar.

En Configuración > Sucursales pueden crearse, editarse, desactivarse o reactivarse sucursales según los permisos. Al desactivar una sucursal se conservan sus datos y asignaciones, pero no se pueden crear ni trasladar cajas o cuentas a ella.

En Configuración > Tipos de gasto, los usuarios con el permiso correspondiente administran un catálogo global, inicialmente compuesto por Fletes, Gasto Local, Devolución y Vale. Los tipos pueden editarse, desactivarse o reactivarse.

En Cuadres, los usuarios autorizados pueden registrar un cuadre por caja y fecha, con venta, efectivo, banco, QR y hasta 100 gastos. Al seleccionar caja y fecha se verifica si ya existe un cuadre; los campos se habilitan cuando la consulta confirma que está disponible. Si la consulta falla, se puede reintentar sin perder lo diligenciado. La comprobación se repite al cambiar caja o fecha y el servidor vuelve a validar al guardar. La tabla incluye una fila de captura: con tipo y valor obligatorios y detalle opcional, el botón Agregar incorpora el gasto y limpia la fila; se pueden agregar varios del mismo tipo. Si la fila pendiente está completa, se incluye automáticamente al guardar; si está incompleta, hay que completarla o limpiarla. Al registrar, la sucursal seleccionada en el listado se propone en el formulario y el selector de caja muestra solo las cajas activas de esa sucursal. Si el filtro es «Todas las sucursales» o no hay selección, se elige una sucursal en el formulario. La fecha inicial es la fecha actual de Colombia y puede cambiarse a una fecha anterior, pero no a una futura. Los importes se muestran con punto para los miles y coma para hasta dos decimales, por ejemplo `1.234,50`. El resultado se muestra como faltante, sobrante o cuadrado. El listado permite filtrar por sucursal y fecha; inicialmente utiliza la sucursal por defecto y la fecha actual de Colombia. Se puede cambiar o borrar la fecha para consultar otros cuadres. Sin sucursal por defecto, se debe elegir un filtro. Los permisos de consultar, registrar y editar son independientes y por sucursal. Para editar desde la interfaz también se necesita consultar. Los cambios simultáneos se rechazan para evitar sobrescribir información; cancela y vuelve a abrir el cuadre para cargar la versión actual.

En Configuración > Cajas se pueden consultar todas las cajas de las sucursales asignadas y, según los permisos del rol, crearlas, editarlas, desactivarlas o reactivarlas. En Configuración > Usuarios se puede asignar una sucursal por defecto entre las sucursales activas asignadas al usuario. Cajas la usa como filtro inicial; cambiar el filtro no cambia la preferencia ni los permisos. Si no hay una sucursal por defecto activa y asignada, se debe elegir un filtro antes de mostrar cajas. La creación requiere una sucursal activa a la que el usuario tenga acceso. El código de caja es único dentro de su sucursal, pero puede repetirse en otras. La edición desde esta pantalla conserva la sucursal; el acceso se determina por la asignación del usuario a la sucursal, no a cajas individuales.

En Configuración > Ubicaciones, los usuarios autorizados pueden crear, editar, desactivar y reactivar departamentos y municipios. Los códigos existentes no se pueden cambiar. Las ubicaciones desactivadas conservan las asociaciones existentes, pero no se pueden asignar a nuevas sucursales. El catálogo parte de DIVIPOLA con corte de 2024, admite ediciones manuales y no se actualiza automáticamente.

Las confirmaciones para cerrar sesión y para desactivar o reactivar usuarios y ubicaciones usan SweetAlert2.

Para validar: `npm run build` y `npm run lint`.
