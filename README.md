# Mesa de Ayuda — Proyecto de Asignación

Proyecto de gestión de incidencias desarrollado como parte de una
asignación de la universidad (Organización y Archivos de Programación — Corte 3).

## Descripción

Sistema web que permite el registro y visualización de incidencias
(tickets de soporte) usando PHP + MySQL con PDO.

## Estructura del repositorio

- `index.php` - Punto de entrada (lógica + formulario + listado de incidencias)
- `db.php` - Conexión a la base de datos con PDO
- Tailwind CSS vía CDN - Estilos de la interfaz
- `schema.sql` - Script SQL para crear la base de datos y la tabla `incidencias`
- `.gitignore` - Ignora la carpeta `Planteamiento/`

## Requisitos

- PHP 7.4 o superior
- Servidor web local o `php -S localhost:8000`
- MySQL o MariaDB

## Instalación y uso

1. Crear la base de datos y la tabla ejecutando `schema.sql`:

   mysql -u usuario -p < schema.sql

2. Configurar las credenciales de la base de datos en `db.php`.

3. Iniciar el servidor PHP en la raíz del proyecto:

   php -S localhost:8000

4. Abrir en el navegador:

   http://localhost:8000

