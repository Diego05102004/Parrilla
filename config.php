<?php
/**
 * Configuración de la herramienta de grilla de canales Thundernet.
 */

return [
    // Página oficial desde la que se extraen los canales.
    'source_url' => 'https://thundernet.com.ve/parrilla-de-canales-thundernet-tv-go/',

    // Directorio y tiempo de vida del cache (en segundos). Evita golpear
    // la web de la empresa en cada carga. Por defecto 6 horas.
    'cache_dir'  => __DIR__ . '/cache',
    'cache_ttl'  => 6 * 60 * 60,

    // Tiempo máximo de espera (segundos) al descargar la página fuente.
    'http_timeout' => 25,

    // User-Agent usado en la petición.
    'user_agent' => 'ThundernetParrillaBot/1.0 (+https://thundernet.com.ve)',

    // Nombres de los planes.
    //
    // Por defecto el sistema los detecta y nombra AUTOMÁTICAMENTE a partir del
    // logo de cada plan que trae la propia web (p. ej. "THUNDERGO-MOVIE.svg" ->
    // "GO Movie"). Así, si la empresa agrega un plan nuevo, aparece solo, sin
    // tocar el código.
    //
    // Este arreglo es solo un OVERRIDE opcional por si se quiere forzar un
    // nombre distinto al derivado. Se indexa por posición de la pestaña
    // (1 = primera). Déjalo vacío para usar la detección automática.
    'plan_names' => [
        // 1 => 'Nombre personalizado del primer plan',
    ],
];
