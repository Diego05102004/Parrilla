<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grilla de Canales · Thundernet TV GO</title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="app">
        <header class="toolbar no-capture">
            <div class="toolbar__title">
                <h1>Grilla de Canales · Thundernet TV GO</h1>
                <p id="status" class="status">Cargando canales…</p>
            </div>
            <div class="toolbar__actions">
                <input type="search" id="search" placeholder="Buscar canal…" autocomplete="off">
                <button id="refresh" type="button" title="Volver a leer la web oficial">Actualizar</button>
                <button id="download" type="button" class="primary" disabled>Descargar imagen</button>
            </div>
        </header>

        <!-- Lienzo que se convierte en imagen descargable -->
        <main id="poster" class="poster">
            <div class="poster__header">
                <img class="poster__logo" src="https://thundernet.com.ve/wp-content/uploads/2025/08/logotipotvgo.webp" alt="Thundernet TV GO">
                <div class="poster__heading">
                    <h2>Grilla de Canales</h2>
                    <p id="poster-subtitle">Más de 190 canales full HD</p>
                </div>
            </div>

            <div id="content" class="content" aria-live="polite"></div>

            <footer class="poster__footer">
                <span>thundernet.com.ve</span>
                <span id="poster-date"></span>
            </footer>
        </main>
    </div>

    <script src="assets/html2canvas.min.js"></script>
    <script src="assets/app.js"></script>
</body>
</html>
