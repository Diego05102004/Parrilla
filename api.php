<?php
/**
 * Endpoint JSON con la grilla de canales agrupada por PLAN (paquete).
 *
 * Devuelve los planes (GO Básico, GO Movie, …) con sus canales (nombre + logo)
 * extraídos de la web oficial, usando un cache en disco para no descargar la
 * página en cada visita.
 *
 * Parámetros:
 *   ?refresh=1  -> ignora el cache y vuelve a extraer los datos.
 */

declare(strict_types=1);

require __DIR__ . '/scraper.php';

$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$cacheDir  = $config['cache_dir'];
$cacheFile = $cacheDir . '/channels.json';
$ttl       = (int) $config['cache_ttl'];
$refresh   = isset($_GET['refresh']) && $_GET['refresh'] !== '0';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

/**
 * Envía una respuesta JSON y termina.
 */
function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 1) Servir desde cache si está fresco y no se pidió refresh.
if (!$refresh && is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
    $cached = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($cached) && !empty($cached['plans'])) {
        $cached['cached']    = true;
        $cached['cache_age'] = time() - filemtime($cacheFile);
        respond($cached);
    }
}

// 2) Extraer datos frescos.
try {
    $scraper = new ChannelScraper(
        $config['source_url'],
        (int) $config['http_timeout'],
        (string) $config['user_agent'],
        (array) ($config['plan_names'] ?? [])
    );
    $plans = $scraper->scrapeStructured();

    if (empty($plans)) {
        throw new RuntimeException('No se encontraron canales en la página fuente (¿cambió su estructura?).');
    }

    $total = 0;
    foreach ($plans as $plan) {
        $total += (int) $plan['count'];
    }

    $payload = [
        'ok'           => true,
        'source'       => $config['source_url'],
        'plan_count'   => count($plans),
        'total'        => $total,
        'generated_at' => date('c'),
        'plans'        => $plans,
    ];

    // Guardar en cache.
    @file_put_contents(
        $cacheFile,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    $payload['cached'] = false;
    respond($payload);
} catch (Throwable $e) {
    // 3) Si falla la extracción pero existe un cache viejo, lo servimos igual.
    if (is_file($cacheFile)) {
        $stale = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($stale) && !empty($stale['plans'])) {
            $stale['ok']      = true;
            $stale['cached']  = true;
            $stale['stale']   = true;
            $stale['warning'] = 'No se pudo actualizar; mostrando última copia guardada.';
            respond($stale);
        }
    }

    respond([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], 502);
}
