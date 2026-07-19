# Grilla de Canales · Thundernet TV GO

Herramienta en **PHP + JavaScript** que automatiza la creación de la grilla de
canales de Thundernet. Extrae el contenido HTML de la página oficial, obtiene
los enlaces (logos) y nombres de cada canal, y muestra una grilla **siempre
actualizada y ordenada**, **dividida por plan/paquete** (GO Básico, GO Movie,
GO Baseball, GO Extra). Además permite **descargar una imagen** lista para
enviar a los clientes, con esa misma división por plan para que quede claro qué
canales incluye cada paquete.

Página fuente:
<https://thundernet.com.ve/parrilla-de-canales-thundernet-tv-go/>

## ¿Cómo funciona?

1. `scraper.php` descarga el HTML de la página oficial (cURL, soporta gzip) y
   con `DOMDocument`/`DOMXPath` recorre las **pestañas de planes** (las pestañas
   principales de Elementor `.e-n-tabs`). Cada pestaña principal es un **plan**;
   dentro del plan base los canales se subdividen por **categoría** (pestañas
   anidadas: Nacionales, Deportes, etc.). De cada elemento de la galería
   (`.e-gallery-item`) extrae el **nombre** del canal y la **URL del logo**
   (`data-thumbnail`).
2. `api.php` expone esos datos como JSON estructurado por plan (con sus
   categorías y canales ordenados alfabéticamente) y con un **cache en disco**
   (6 h por defecto) para no golpear la web en cada visita.
3. `index.php` + `assets/app.js` renderizan la grilla en el navegador, permiten
   **buscar** un canal y **actualizar** los datos bajo demanda.
4. `assets/app.js` genera **imágenes JPG descargables** (alta calidad, más
   livianas que PNG y aptas para enviar por WhatsApp) con
   [html2canvas](https://html2canvas.hertzen.com/):
   - **Descargar imagen**: la grilla completa con todos los planes.
   - **Descargar este plan** (en cada encabezado de plan): solo ese plan.
   - **Descargar** (en cada encabezado de categoría): solo esa categoría
     (p. ej. Deportes, Internacionales). Genera una imagen corta, ideal para
     enviar por WhatsApp.
   - Al exportar, la grilla entra en "modo exportación" (clase
     `poster--export`) que **agranda los logos y los nombres** para que los
     canales se vean claros en la imagen, sin afectar la vista compacta en
     pantalla.

   Los logos se sirven a través del proxy `img.php` (mismo origen) para que la
   exportación a imagen no falle por CORS.

## Estructura

```
thundernet-parrilla/
├── index.php               # Página principal (UI)
├── api.php                 # Endpoint JSON con cache (canales ordenados)
├── scraper.php             # Clase ChannelScraper (descarga + parseo)
├── img.php                 # Proxy de logos (mismo origen, solo dominio oficial)
├── config.php              # URL fuente, TTL de cache, timeouts
├── assets/
│   ├── app.js              # Render de la grilla + descarga de imagen
│   ├── styles.css          # Estilos
│   └── html2canvas.min.js  # Librería para exportar a imagen
└── cache/                  # Cache JSON (se genera solo)
```

## Requisitos

- PHP 7.4+ (probado en PHP 8.1) con las extensiones `curl`, `dom`, `mbstring`.
- Acceso a Internet desde el servidor (para leer la web oficial y los logos).

## Uso en local

```bash
php -S 127.0.0.1:8000
# Abrir http://127.0.0.1:8000/ en el navegador
```

## Despliegue

Copiar la carpeta al servidor web (Apache/Nginx con PHP). Asegurarse de que la
carpeta `cache/` tenga permisos de escritura para el usuario del servidor web.

## Configuración

Editar `config.php`:

- `source_url`: URL de la página oficial de la parrilla.
- `cache_ttl`: segundos de vigencia del cache (por defecto 6 horas).
- `http_timeout`: tiempo máximo de espera al descargar la página.
- `plan_names`: **override opcional** del nombre de cada plan (indexado por
  posición de la pestaña). Por defecto se deja vacío: el sistema detecta cada
  plan y deriva su **nombre y logo automáticamente** desde la imagen que trae la
  propia web (`THUNDERGO-MOVIE.svg` → `GO Movie`, `THUNDERGO.svg` → `GO Básico`).
  Así, **si la empresa agrega un plan nuevo, aparece solo, sin tocar el código.**
  Solo hay que usar este arreglo si se quiere forzar un nombre distinto al
  derivado.

## Notas

- Si la página oficial cambia su estructura HTML, ajustar los selectores en
  `scraper.php` (`e-n-tabs`, `e-n-tab-title`, `e-gallery-item`, `e-gallery-image`,
  `elementor-gallery-item__title`).
- Si la empresa **reordena, agrega o quita planes**, actualizar `plan_names` en
  `config.php` para que los nombres sigan coincidiendo con cada pestaña.
- Un canal puede aparecer en más de un plan (p. ej. incluido en varios
  paquetes); en ese caso se muestra en cada plan al que pertenece. La
  deduplicación se hace solo **dentro** de cada plan/categoría, no entre planes.
- Si la extracción falla temporalmente, `api.php` sirve la última copia guardada
  en cache para no dejar la grilla vacía.
