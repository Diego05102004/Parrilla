<?php
/**
 * Scraper de la parrilla de canales de Thundernet.
 *
 * Descarga el HTML de la página oficial y extrae los canales agrupados por
 * PLAN (paquete). En la web los planes son las pestañas principales
 * (GO Básico, GO Movie, GO Baseball, GO Extra…) y, dentro del plan base,
 * los canales se subdividen por categoría (Nacionales, Deportes, etc.).
 *
 * Resultado (scrapeStructured):
 * [
 *   [
 *     'name'       => 'GO Básico',
 *     'count'      => 191,
 *     'categories' => [ ['name' => 'Nacionales', 'channels' => [ ... ]], ... ],
 *     'channels'   => [ ... todos los del plan, ordenados ... ],
 *   ],
 *   ...
 * ]
 *
 * Cada canal es ['name' => string, 'logo' => string].
 */

class ChannelScraper
{
    private string $sourceUrl;
    private int $timeout;
    private string $userAgent;
    /** @var array<int, string> Nombre de cada plan por posición (1-indexado). */
    private array $planNames;

    public function __construct(
        string $sourceUrl,
        int $timeout = 25,
        string $userAgent = 'ThundernetParrillaBot/1.0',
        array $planNames = []
    ) {
        $this->sourceUrl = $sourceUrl;
        $this->timeout   = $timeout;
        $this->userAgent = $userAgent;
        $this->planNames = $planNames;
    }

    /**
     * Descarga la página fuente y devuelve su HTML.
     *
     * @throws RuntimeException si la descarga falla.
     */
    public function fetchHtml(): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión cURL de PHP no está disponible.');
        }

        $ch = curl_init($this->sourceUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '', // acepta gzip/deflate automáticamente
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
        ]);

        $html   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("Error al descargar la página fuente: {$error}");
        }
        if ($status >= 400 || $html === false || $html === '') {
            throw new RuntimeException("La página fuente respondió con estado HTTP {$status}.");
        }

        return (string) $html;
    }

    /**
     * Extrae los planes (paquetes) con sus canales a partir del HTML.
     *
     * @return array<int, array{name: string, count: int, categories: array, channels: array}>
     */
    public function parsePlans(string $html): array
    {
        $xpath = $this->buildXPath($html);

        // Los planes son las pestañas del nivel superior (una pestaña "e-n-tabs"
        // que no está anidada dentro de otra pestaña).
        $topTabs = $this->findTopLevelTabs($xpath);
        if ($topTabs === null) {
            // Sin estructura de pestañas: devolvemos un único plan con todo.
            $all = $this->extractChannels($xpath, $xpath->query('//body')->item(0));
            return $all ? [[
                'name'       => $this->planNames[1] ?? 'Todos los canales',
                'logo'       => '',
                'count'      => count($all),
                'categories' => [],
                'channels'   => $all,
            ]] : [];
        }

        $plans   = [];
        $buttons = $xpath->query("./div[contains(concat(' ', normalize-space(@class), ' '), ' e-n-tabs-heading ')]/button[contains(concat(' ', normalize-space(@class), ' '), ' e-n-tab-title ')]", $topTabs);

        $index = 0;
        foreach ($buttons as $button) {
            /** @var DOMElement $button */
            $index++;
            $panel = $this->panelFor($xpath, $button);
            if ($panel === null) {
                continue;
            }

            // Logo del plan: imagen "THUNDERGO*.svg" dentro del panel. De su
            // nombre de archivo derivamos el nombre del plan automáticamente,
            // de modo que un plan nuevo se detecta e incluye sin tocar el código.
            $logo = $this->planLogo($xpath, $panel);

            // Prioridad del nombre: override en config → derivado del logo →
            // texto del botón → "Plan N".
            $name = $this->planNames[$index]
                ?? ($this->planNameFromLogo($logo)
                    ?: ($this->cleanText($button->textContent) ?: 'Plan ' . $index));

            [$categories, $channels] = $this->extractPanel($xpath, $panel);

            $plans[] = [
                'name'       => $name,
                'logo'       => $logo,
                'count'      => count($channels),
                'categories' => $categories,
                'channels'   => $channels,
            ];
        }

        return $plans;
    }

    /**
     * Busca el logo del plan dentro de su panel (imagen "THUNDERGO*.svg").
     */
    private function planLogo(DOMXPath $xpath, DOMElement $panel): string
    {
        $img = $xpath->query(
            ".//img[contains(translate(@src, 'thundergo', 'THUNDERGO'), 'THUNDERGO')]",
            $panel
        )->item(0);
        return $img instanceof DOMElement ? trim($img->getAttribute('src')) : '';
    }

    /**
     * Deriva el nombre comercial del plan a partir del nombre de archivo del
     * logo. Ej.: "THUNDERGO-MOVIE.svg" -> "GO Movie"; "THUNDERGO.svg" -> "GO
     * Básico". Devuelve '' si no se puede derivar.
     */
    private function planNameFromLogo(string $logo): string
    {
        if ($logo === '') {
            return '';
        }
        $base = pathinfo(parse_url($logo, PHP_URL_PATH) ?? '', PATHINFO_FILENAME);
        $base = preg_replace('/-\d+x\d+$/', '', (string) $base); // quita sufijo de tamaño

        // Aísla la parte posterior a "THUNDERGO".
        if (!preg_match('/th\s*u\s*n\s*d\s*e\s*r\s*g\s*o/i', $base)) {
            // No parece un logo de plan; no derivamos nombre.
            return '';
        }
        $suffix = preg_replace('/^.*?thundergo/i', '', $base);
        $suffix = trim(str_replace(['-', '_'], ' ', $suffix));

        // Sin sufijo => plan base.
        $label = $suffix === '' ? 'Básico' : mb_convert_case(mb_strtolower($suffix), MB_CASE_TITLE, 'UTF-8');

        return 'GO ' . $label;
    }

    /**
     * Descarga y devuelve los planes estructurados.
     *
     * @return array<int, array{name: string, count: int, categories: array, channels: array}>
     */
    public function scrapeStructured(): array
    {
        return $this->parsePlans($this->fetchHtml());
    }

    /**
     * Lista plana de todos los canales únicos (ordenados). Útil como respaldo.
     *
     * @return array<int, array{name: string, logo: string}>
     */
    public function scrape(): array
    {
        $xpath = $this->buildXPath($this->fetchHtml());
        return $this->extractChannels($xpath, $xpath->query('//body')->item(0));
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function buildXPath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        return new DOMXPath($dom);
    }

    /**
     * Devuelve la primera pestaña "e-n-tabs" que no esté anidada dentro de otra.
     */
    private function findTopLevelTabs(DOMXPath $xpath): ?DOMElement
    {
        $tabs = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' e-n-tabs ')]");
        if ($tabs === false) {
            return null;
        }
        foreach ($tabs as $tab) {
            /** @var DOMElement $tab */
            $ancestor = $xpath->query("ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' e-n-tabs ')]", $tab);
            if ($ancestor === false || $ancestor->length === 0) {
                return $tab;
            }
        }
        return null;
    }

    /**
     * Panel de contenido asociado a un botón de pestaña (via aria-controls).
     */
    private function panelFor(DOMXPath $xpath, DOMElement $button): ?DOMElement
    {
        $id = $button->getAttribute('aria-controls');
        if ($id === '') {
            return null;
        }
        $panel = $xpath->query("//*[@id='" . addslashes($id) . "']")->item(0);
        return $panel instanceof DOMElement ? $panel : null;
    }

    /**
     * Extrae el contenido de un panel de plan: si tiene pestañas anidadas
     * (categorías) las devuelve; en cualquier caso devuelve la lista completa.
     *
     * @return array{0: array<int, array{name: string, channels: array}>, 1: array<int, array{name: string, logo: string}>}
     */
    private function extractPanel(DOMXPath $xpath, DOMElement $panel): array
    {
        $categories = [];

        $nested = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' e-n-tabs ')]", $panel)->item(0);
        if ($nested instanceof DOMElement) {
            $catButtons = $xpath->query("./div[contains(concat(' ', normalize-space(@class), ' '), ' e-n-tabs-heading ')]/button[contains(concat(' ', normalize-space(@class), ' '), ' e-n-tab-title ')]", $nested);
            foreach ($catButtons as $catButton) {
                /** @var DOMElement $catButton */
                $catPanel = $this->panelFor($xpath, $catButton);
                if ($catPanel === null) {
                    continue;
                }
                $catName = $this->cleanText($catButton->textContent) ?: 'Otros';
                $catChannels = $this->extractChannels($xpath, $catPanel);
                if ($catChannels) {
                    $categories[] = ['name' => $catName, 'channels' => $catChannels];
                }
            }
        }

        // Lista completa del plan (ordenada, sin duplicados).
        $channels = $this->extractChannels($xpath, $panel);

        return [$categories, $channels];
    }

    /**
     * Extrae y ordena los canales dentro de un nodo.
     *
     * @return array<int, array{name: string, logo: string}>
     */
    private function extractChannels(DOMXPath $xpath, ?DOMNode $context): array
    {
        if ($context === null) {
            return [];
        }

        $items = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' e-gallery-item ')]", $context);
        if ($items === false) {
            return [];
        }

        $channels = [];
        $seen = [];
        foreach ($items as $item) {
            /** @var DOMElement $item */
            $imageNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' e-gallery-image ')]", $item)->item(0);
            $titleNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' elementor-gallery-item__title ')]", $item)->item(0);

            $logo = $imageNode instanceof DOMElement ? trim($imageNode->getAttribute('data-thumbnail')) : '';
            $name = $titleNode !== null ? $this->cleanText($titleNode->textContent) : '';

            if ($name === '' && $logo === '') {
                continue;
            }
            if ($name === '') {
                $name = $this->nameFromLogo($logo);
            }

            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $channels[] = ['name' => $name, 'logo' => $logo];
        }

        usort($channels, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return $channels;
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function nameFromLogo(string $logo): string
    {
        $base = pathinfo(parse_url($logo, PHP_URL_PATH) ?? '', PATHINFO_FILENAME);
        $base = preg_replace('/-\d+x\d+$/', '', (string) $base);
        $base = str_replace(['-', '_'], ' ', (string) $base);
        return trim($base) !== '' ? ucwords(trim($base)) : 'Canal';
    }
}
