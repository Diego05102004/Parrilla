<?php
/**
 * Proxy de imágenes de logos.
 *
 * Sirve los logos de los canales desde el mismo origen que la aplicación.
 * Esto es imprescindible para que html2canvas pueda exportar la grilla a una
 * imagen sin que el <canvas> quede "contaminado" por recursos de otro dominio.
 *
 * Por seguridad solo se permiten imágenes del dominio oficial de Thundernet.
 *
 * Uso: img.php?u=<url-del-logo-codificada>
 */

declare(strict_types=1);

$allowedHosts = ['thundernet.com.ve', 'www.thundernet.com.ve'];

$url = isset($_GET['u']) ? (string) $_GET['u'] : '';
$parts = parse_url($url);

if ($url === '' || empty($parts['host']) || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'], $allowedHosts, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'URL de imagen no permitida.';
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_ENCODING       => '',
    CURLOPT_USERAGENT      => 'ThundernetParrillaBot/1.0',
]);

$data     = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$mime     = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$errno    = curl_errno($ch);
curl_close($ch);

if ($errno !== 0 || $status >= 400 || $data === false) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo obtener la imagen.';
    exit;
}

if (!is_string($mime) || $mime === '' || stripos($mime, 'image/') !== 0) {
    $mime = 'image/webp';
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');
echo $data;
