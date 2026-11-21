<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Cuadre de Caja API

La API usa Laravel Sanctum para autenticación por token y Spatie Permission para roles y permisos configurables. Los roles iniciales son `admin`, `supervisor` y `cajero`.

### Puesta en marcha

1. Configura la conexión MySQL en `.env`.
2. Ejecuta las migraciones y carga los datos iniciales:

   ```bash
   php artisan migrate
   php artisan db:seed
   ```

`php artisan db:seed` crea el administrador inicial si todavía no existe: usuario `admin`, correo `admin@cuadrecaja.test` y rol `admin`. La contraseña se genera aleatoriamente y se muestra una sola vez en la salida del comando; guárdala al ejecutar el seeder. Repetirlo conserva la cuenta y su contraseña. `php artisan app:create-admin` sigue disponible para crear administradores con datos personalizados.

No hay registro público. Los usuarios autorizados pueden crear usuarios, configurar roles y asignar usuarios a cajas.

Las sucursales pueden desactivarse o reactivarse con `PUT /api/branches/{branch}` y `is_active`. Sus roles, cajas y cuentas se conservan y se pueden consultar; no se pueden crear ni trasladar cajas o cuentas a una sucursal inactiva. Se pueden editar las configuraciones existentes. Al implementar operaciones financieras o cuadres, también deberán rechazar nuevas operaciones en sucursales inactivas.

`DatabaseSeeder` crea inicialmente la sucursal **Sucursal principal** con código `001` y, al crear el administrador inicial, se la asigna como sucursal por defecto. Las sucursales se asignan directamente a usuarios; volver a ejecutar los seeders no modifica las sucursales ni las asignaciones existentes.

También crea en esa sucursal la **Caja principal**, con código `001`, sin asignar usuarios automáticamente. Si ya existe una caja con ese código en la sucursal principal, conserva sus datos y asignaciones; si no existe, la sucursal debe estar activa. En una base existente puede ejecutarse `php artisan db:seed --class=CashRegisterSeeder` para crearla sin recrear la base.

Cada usuario tiene exactamente un rol, guardado en `users.role_id` con clave foránea a `roles`. La API recibe `role_id` y `branch_ids` en `POST|PUT /api/users` y devuelve `role_id`, `role` y las sucursales asignadas; el campo anterior `roles` ya no se acepta. Las sucursales del usuario se guardan en la tabla pivote `branch_user`. Los roles solo definen permisos y la API rechaza `branch_ids` en `POST|PUT /api/roles`. Para usuarios que no sean administradores, las operaciones de sucursales, cuentas y edición o creación de cajas requieren el permiso correspondiente y una asignación directa en `branch_user`. Consultar cajas solo requiere la asignación a la sucursal, sin permiso adicional ni asignación individual a la caja. La sucursal por defecto debe estar activa y asignada al usuario. El rol `admin` siempre tiene acceso global y no puede modificarse ni eliminarse.

Los antiguos permisos `registers.view_all` y `registers.view_assigned` ya no se usan ni aparecen en la API de roles. Si estaban asignados, se conservan en la base hasta que se edite el rol, pero no afectan el acceso ni la delegación.

El catálogo global de tipos de gasto se inicializa con Fletes, Gasto Local, Devolución y Vale mediante `ExpenseTypeSeeder`. Repetir el seeder conserva los nombres y estados editados. Los usuarios autenticados pueden consultar los tipos activos con `GET /api/expense-types`; el permiso global `expenses.manage_types` permite incluir inactivos y crear, editar, desactivar o reactivar tipos. No se eliminan registros. El registro de gastos todavía no está implementado.

La gestión de roles y usuarios es delegable: un gestor solo puede asignar permisos que ya posee y sucursales a las que tiene acceso; solo puede modificar usuarios cuyos permisos y sucursales estén dentro de ese alcance. Solo un administrador puede asignar el rol `admin` o modificar un usuario administrador. No se pueden eliminar roles con usuarios asignados. Los nombres de `supervisor` y `cajero` son inmutables. Volver a ejecutar `RoleSeeder` no restaura los permisos personalizados de roles existentes.

La migración a `users.role_id` elimina `model_has_roles` tras copiar las asignaciones. Antes de ejecutarla, haz una copia de seguridad y detén las escrituras. La migración se detiene si hay usuarios sin rol, permisos directos de usuario o roles de otros tipos de modelo; deben reconciliarse manualmente. Si un usuario tiene varios roles, hay que indicar el rol a conservar y todos sus roles actuales en `ROLE_MIGRATION_CHOICES` como JSON, por ejemplo `{"123":{"role":"admin","expected":["admin","cajero"]}}`. Rechaza cambios inesperados y protege el último administrador activo. Los roles descartados no se conservan; la reversión requiere restauración o reconciliación manual. Spatie sigue gestionando los permisos de los roles; la relación de usuarios con roles ya no usa los métodos de asignación de Spatie.

El acceso a sucursales se administra exclusivamente por usuario mediante `branch_user`; las asignaciones individuales a cajas siguen en `cash_register_user` y no sustituyen la membresía de sucursal. Las sucursales creadas por la API se asignan automáticamente al usuario que las crea. En este proyecto aún no desplegado, el cambio de alcance requiere recrear la base de desarrollo con `php artisan migrate:fresh --seed`.

Un administrador puede desactivar o reactivar usuarios con `PUT /api/users/{user}` y `is_active`. Desactivar revoca sus tokens y bloquea el acceso; cambiar la contraseña también revoca los tokens. Una cuenta no puede desactivarse a sí misma y debe mantenerse al menos un administrador activo.

Todo usuario autenticado puede actualizar su nombre y correo mediante `PUT /api/user/profile`. Para cambiar su contraseña con `PUT /api/user/password` debe proporcionar la contraseña actual y confirmar una nueva de al menos 12 caracteres; el cambio revoca todas sus sesiones y requiere iniciar sesión nuevamente.

El inicio de sesión usa `username` y `password`; el correo se conserva como dato de contacto. El usuario es único, tiene entre 3 y 64 caracteres y admite letras ASCII, números, puntos, guiones y guiones bajos. Se guarda en minúsculas y no distingue mayúsculas al iniciar sesión. Se define al crear la cuenta y no puede modificarse después; `PUT /api/user/profile` y `PUT /api/users/{user}` rechazan el campo `username`. La migración asigna `usuario_<id>` a las cuentas existentes.

### Ubicación de sucursales

El catálogo DIVIPOLA está guardado localmente y se carga con `DivipolaSeeder`, que puede ejecutarse varias veces sin borrar registros. Incluye municipios, la isla y áreas no municipalizadas del archivo fuente. Los códigos se guardan como texto para conservar ceros iniciales. La ubicación de una sucursal es opcional: se envía `municipality_code` (cinco dígitos) al crearla o actualizarla. El departamento se obtiene del municipio, evitando combinaciones contradictorias.

El catálogo parte del corte DANE indicado abajo, pero puede ser editado por administradores y no se actualiza automáticamente. Al repetir el seeder se conservan nombres, tipos y estados editados. Los códigos existentes y el departamento de un municipio son inmutables. Los administradores pueden crear, editar, desactivar y reactivar ubicaciones; desactivar un departamento no cambia el estado individual de sus municipios. Las sucursales conservan su ubicación histórica, pero no se pueden hacer nuevas asignaciones a municipios o departamentos inactivos. La lectura normal solo incluye ubicaciones activas; los administradores pueden consultar todas con `?include_inactive=1`.

Fuente: Departamento Administrativo Nacional de Estadística (DANE), [DIVIPOLA - Códigos municipios](https://www.datos.gov.co/d/gdxc-w37w), corte 30 de diciembre de 2024, descargado el 15 de noviembre de 2026. Datos publicados bajo [Creative Commons Atribución-CompartirIgual 4.0 Internacional](https://creativecommons.org/licenses/by-sa/4.0/). El archivo incluido es una copia del catálogo fuente; su corte no se actualiza automáticamente y debe revisarse antes de sustituirlo.

### API inicial

- `POST /api/login`, `POST /api/logout`, `GET /api/user`
- `GET /api/departments`, `GET /api/departments/{code}/municipalities`
- `POST /api/departments`, `PUT /api/departments/{code}`, `POST /api/municipalities`, `PUT /api/municipalities/{code}` (`locations.manage`)
- `GET|POST /api/branches`, `GET|PUT|PATCH /api/branches/{branch}`
- `GET|POST /api/accounts`, `GET|PUT|PATCH /api/accounts/{account}`
- `GET|POST /api/registers`, `GET|PUT|PATCH /api/registers/{cashRegister}`
- `GET|POST /api/users`, `PUT|PATCH /api/users/{user}` (`users.manage`)
- `GET /api/roles`, `GET /api/roles/options`, `POST /api/roles`, `PUT|PATCH|DELETE /api/roles/{role}` (`roles.manage`; el listado también está disponible con `users.manage`)

Las operaciones requieren el token `Bearer` recibido al iniciar sesión. La API valida cada permiso y su alcance; ocultar opciones en la interfaz no sustituye esa validación.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
