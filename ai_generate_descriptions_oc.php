<?php
/**
 * ai_generate_descriptions_oc.php
 *
 * Версия скрипта массовой генерации описаний товаров для OcStore3 / OpenCart 3.x.
 * По архитектуре и набору команд полностью аналогична версии для Битрикс —
 * отличается только слоем доступа к данным (прямые SQL-запросы к таблицам
 * OpenCart вместо API Битрикса).
 *
 * ВАЖНО — требует проверки под конкретную установку:
 *   - LANGUAGE_ID: обычно 1, но нужно свериться (SELECT * FROM oc_language)
 *   - DB_PREFIX: обычно "oc_", но могло быть изменено при установке
 *   - Схема oc_seo_url: в разных версиях/форках разная (см. функцию getProductUrl)
 *
 * Запуск (идентично Bitrix-версии):
 *   php ai_generate_descriptions_oc.php review <CATEGORY_ID> [LIMIT]
 *   php ai_generate_descriptions_oc.php apply  <CATEGORY_ID>
 *   php ai_generate_descriptions_oc.php preview <CATEGORY_ID> <PRODUCT_ID>
 *   php ai_generate_descriptions_oc.php dumpprompt <CATEGORY_ID> <PRODUCT_ID>
 *   php ai_generate_descriptions_oc.php count <CATEGORY_ID>
 *   php ai_generate_descriptions_oc.php listapplied <CATEGORY_ID>
 *   php ai_generate_descriptions_oc.php listprompts
 *   php ai_generate_descriptions_oc.php bindprompt <CATEGORY_ID> <FILENAME>
 *   php ai_generate_descriptions_oc.php unbindprompt <CATEGORY_ID>
 *   php ai_generate_descriptions_oc.php listmapping
 *   php ai_generate_descriptions_oc.php indexnow <CATEGORY_ID>
 *
 *   Модуль OCFilter (SEO-страницы фильтров категорий):
 *   php ai_generate_descriptions_oc.php genocfilter <BATCH_ID> [LIMIT]
 *   php ai_generate_descriptions_oc.php applyocfilter <BATCH_ID>
 *   php ai_generate_descriptions_oc.php countocfilter <BATCH_ID>
 *   php ai_generate_descriptions_oc.php listappliedocfilter <BATCH_ID>
 *   php ai_generate_descriptions_oc.php indexnowocfilter <BATCH_ID>
 */

// ==================== КОНФИГУРАЦИЯ ====================

// Путь к корневому config.php интернет-магазина (там же, где index.php сайта).
// В нём объявлены константы DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PREFIX.
define("OC_CONFIG_PATH", "/var/www/fe-rus/data/www/fe-rus.ru/config.php");

if (!file_exists(OC_CONFIG_PATH)) {
    die("Не найден config.php OpenCart по пути: " . OC_CONFIG_PATH . "\nПропиши правильный путь в константе OC_CONFIG_PATH.\n");
}
require_once(OC_CONFIG_PATH);

define("TBL_PREFIX", defined("DB_PREFIX") ? DB_PREFIX : "oc_");
define("LANGUAGE_ID", 1); 
define("STORE_ID", 0);   

function getPdo()
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

function reconnectDatabase()
{
    // В отличие от Bitrix-версии, здесь просто пересоздаём PDO-соединение.
    global $__pdo_reset;
    $__pdo_reset = true;
}

// Обёртка над getPdo(), которая учитывает флаг пересоздания соединения
// (используется после обрыва MySQL-соединения на длинных прогонах).
function db()
{
    global $__pdo_reset;
    static $pdo = null;
    if ($pdo === null || !empty($__pdo_reset)) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8mb4",
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $__pdo_reset = false;
    }
    return $pdo;
}

// Папка с текстовыми файлами промптов — так же, как в Bitrix-версии.
define("PROMPTS_DIR", __DIR__ . "/prompts");

// Отдельная папка промптов для SEO-страниц OCFilter — не смешиваем с
// промптами товаров, т.к. это принципиально другой тип текста (не карточка
// товара, а страница фильтра категории).
define("OCFILTER_PROMPTS_DIR", __DIR__ . "/prompts/ocfilter");

$SECTION_PROMPT_FILES = loadSectionPromptFiles();

$DEFAULT_SECTION_ID = null;

// Ключ и модель — ТОЛЬКО из переменной окружения (передаётся GUI-приложением).
define("ROUTER_CHEAP_API_KEY", getenv("ROUTER_CHEAP_API_KEY") ?: "");
define("ROUTER_CHEAP_MODEL", getenv("ROUTER_CHEAP_MODEL") ?: "claude-sonnet-5");

// IndexNow
define("INDEXNOW_KEY", "f04e68d0e38cc17ebafe6ea66377e523");
define("SITE_HOST", "fe-rus.ru"); // <-- ЗАПОЛНИ, например "fe-rus.ru"
define("SITE_PROTOCOL", "https");

// Фиксированный коммерческий блок, который приклеивается кодом (не ИИ)
// в конец КАЖДОГО сгенерированного описания. Плейсхолдеры %CITY%/%PHONE%/
// %EMAIL% — уже поддерживаются самим сайтом (см. product.php controller,
// $data['description'] проходит через тот же str_replace, что и auto_description),
// поэтому подставлять реальные значения самим не нужно — сайт сделает это
// сам при отображении, в зависимости от домена/города.
define("COMMERCIAL_FOOTER_HTML", <<<'FOOTER'
<div style="margin-top: 20px;">
<p>Налаживание надежных партнерских отношений, развитие логистической цепи, улучшение технологических процессов производства на промышленных объектах компании – это то, чему постоянно уделяется большое внимание.</p>

<p>ООО Ферус, г. %CITY%, предлагает Вам приобрести по выгодной цене. Реализация продукции оптом и в розницу, с складов компании. Условия доставки и другую информацию, касательно покупки Вы можете уточнить у менеджеров компании по телефону или электронной почте:</p>

<p><strong><a href="tel:%PHONE%">%PHONE%</a></strong></p>

<p><strong><a href="mailto:%EMAIL%">%EMAIL%</a></strong></p>
</div>
FOOTER
);
// Коммерческий хвост для <title>/meta_title страниц OCFilter — приклеивается
// кодом (не ИИ) после сгенерированной части "Категория с характеристикой",
// гарантированно с правильными плейсхолдерами (сайт сам подставит нужный
// город/контакты поддомена при отображении, см. product.php controller —
// %CITYS%/%TELEPHONE%/%EMAIL% там реально обрабатываются str_replace).
// %CITYS% — город в предложном падеже ("в Екатеринбурге"), не путать с
// %CITY% (именительный падеж, "Екатеринбург").
define("OCFILTER_TITLE_SUFFIX", " купить в %CITYS% - ООО \"Ферус\"");

// Контактный блок для description SEO-страниц OCFilter — приклеивается
// кодом (не ИИ) по тем же причинам, что и для title: гарантированная
// точность плейсхолдеров %CITYS%/%TELEPHONE%/%EMAIL%, и телефон/email
// кликабельны (tel:/mailto:), как в примере с обычных страниц каталога.
define("OCFILTER_DESCRIPTION_FOOTER_HTML", <<<'OCFOOTER'
<p>Купить продукцию в %CITYS% можно оптом и в розницу. Уточнить наличие, стоимость и условия доставки Вы можете, позвонив по телефону или написав на электронную почту в отдел продаж:</p>

<p><strong><a href="tel:%TELEPHONE%">%TELEPHONE%</a></strong></p>

<p><strong><a href="mailto:%EMAIL%">%EMAIL%</a></strong></p>

<p><strong>ООО "Ферус", г. %CITY%</strong></p>
OCFOOTER
);

define("SITE_DOCUMENT_ROOT", dirname(OC_CONFIG_PATH)); // для записи файла-подтверждения IndexNow

// ==================== ПРИВЯЗКИ ПРОМПТОВ ====================

function getMappingFile()
{
    return PROMPTS_DIR . "/section_mapping.json";
}

function loadSectionPromptFiles()
{
    $file = getMappingFile();
    if (!file_exists($file)) {
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveSectionPromptFiles($mapping)
{
    if (!is_dir(PROMPTS_DIR)) {
        if (!@mkdir(PROMPTS_DIR, 0755, true)) {
            throw new \RuntimeException("Не удалось создать папку " . PROMPTS_DIR . " (проверь права доступа)");
        }
    }
    $result = @file_put_contents(getMappingFile(), json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($result === false) {
        throw new \RuntimeException("Не удалось записать " . getMappingFile() . " (проверь права доступа на папку prompts/)");
    }
}

function getPromptFilePath($sectionId, $sectionPromptFiles)
{
    if (isset($sectionPromptFiles[$sectionId])) {
        $path = PROMPTS_DIR . "/" . $sectionPromptFiles[$sectionId];
        if (file_exists($path)) {
            return $path;
        }
    }
    return PROMPTS_DIR . "/default.txt";
}

// ==================== СБОР ДАННЫХ О ТОВАРЕ ====================

function getElementIdsBySection($categoryId)
{
    $sql = "SELECT p.product_id
            FROM " . TBL_PREFIX . "product_to_category ptc
            INNER JOIN " . TBL_PREFIX . "product p ON p.product_id = ptc.product_id
            WHERE ptc.category_id = :cat AND p.status = 1";
    $stmt = db()->prepare($sql);
    $stmt->execute(["cat" => $categoryId]);
    $ids = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ids[] = (int)$row["product_id"];
    }
    return $ids;
}

function getProductContext($productId)
{
    // Название товара + текущее описание
    $sql = "SELECT name, description
            FROM " . TBL_PREFIX . "product_description
            WHERE product_id = :id AND language_id = :lang";
    $stmt = db()->prepare($sql);
    $stmt->execute(["id" => $productId, "lang" => LANGUAGE_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    // Характеристики через атрибуты
    $sqlAttr = "SELECT ad.name AS attr_name, pa.text AS attr_value
                FROM " . TBL_PREFIX . "product_attribute pa
                INNER JOIN " . TBL_PREFIX . "attribute_description ad
                    ON ad.attribute_id = pa.attribute_id AND ad.language_id = :lang
                WHERE pa.product_id = :id AND pa.language_id = :lang";
    $stmtAttr = db()->prepare($sqlAttr);
    $stmtAttr->execute(["id" => $productId, "lang" => LANGUAGE_ID]);

    $propList = [];
    while ($attrRow = $stmtAttr->fetch(PDO::FETCH_ASSOC)) {
        if (trim($attrRow["attr_value"]) === "") {
            continue;
        }
        $propList[$attrRow["attr_name"]] = $attrRow["attr_value"];
    }

    // Название первой связанной категории — для контекста (аналог IBLOCK_SECTION_ID)
    $sqlCat = "SELECT cd.name
               FROM " . TBL_PREFIX . "product_to_category ptc
               INNER JOIN " . TBL_PREFIX . "category_description cd
                   ON cd.category_id = ptc.category_id AND cd.language_id = :lang
               WHERE ptc.product_id = :id
               LIMIT 1";
    $stmtCat = db()->prepare($sqlCat);
    $stmtCat->execute(["id" => $productId, "lang" => LANGUAGE_ID]);
    $catRow = $stmtCat->fetch(PDO::FETCH_ASSOC);
    $sectionName = $catRow ? $catRow["name"] : "";

    return [
        "id" => $productId,
        "name" => $row["name"],
        "section" => $sectionName,
        "properties" => $propList,
        "existing_text" => trim(strip_tags($row["description"] ?? "")),
        "existing_text_html" => $row["description"] ?? "",
    ];
}

// ==================== ПРОМПТ ====================

function renderRewriteBlock($product)
{
    if (empty($product["existing_text"])) {
        return "";
    }
    $path = PROMPTS_DIR . "/rewrite_block.txt";
    if (!file_exists($path)) {
        return "";
    }
    $template = file_get_contents($path);
    return str_replace("{{EXISTING_TEXT}}", $product["existing_text"], $template) . "\n";
}

function buildPrompt($product, $sectionId, $extraInstructions, $sectionPromptFiles)
{
    $propsText = "";
    foreach ($product["properties"] as $name => $value) {
        $propsText .= "- {$name}: {$value}\n";
    }

    $promptFile = getPromptFilePath($sectionId, $sectionPromptFiles);

    if (!file_exists($promptFile)) {
        return "ОШИБКА КОНФИГУРАЦИИ: файл промпта не найден: {$promptFile}";
    }

    $template = file_get_contents($promptFile);

    $extraBlock = "";
    if (!empty($extraInstructions)) {
        $extraBlock = "\nОсобенности именно этой категории товара (обязательно учти):\n{$extraInstructions}\n";
    }

    $rewriteBlock = renderRewriteBlock($product);

    $replacements = [
        "{{NAME}}" => $product["name"],
        "{{SECTION}}" => $product["section"],
        "{{PROPS}}" => $propsText,
        "{{EXTRA_BLOCK}}" => $extraBlock,
        "{{REWRITE_BLOCK}}" => $rewriteBlock,
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

// ==================== ВЫЗОВ CLAUDE API (идентично Bitrix-версии) ====================

function callClaudeAPI($prompt)
{
    $maxAttempts = 3;
    $lastError = "";

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        if ($attempt > 1) {
            fwrite(STDERR, "[retry] Попытка {$attempt}/{$maxAttempts}...\n");
            sleep(3);
        }

        $result = callClaudeAPIAttempt($prompt);

        if (!isset($result["error"])) {
            return $result;
        }

        $lastError = $result["error"];
    }

    return ["error" => "После {$maxAttempts} попыток: " . $lastError];
}

function callClaudeAPIAttempt($prompt)
{
    $payload = json_encode([
        "model" => ROUTER_CHEAP_MODEL,
        "max_tokens" => 2000,
        "stream" => true,
        "messages" => [
            ["role" => "user", "content" => $prompt],
        ],
    ], JSON_UNESCAPED_UNICODE);

    $accumulatedText = "";
    $sseBuffer = "";
    $streamError = "";

    $ch = curl_init("https://direct.router-cheap.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "x-api-key: " . ROUTER_CHEAP_API_KEY,
            "anthropic-version: 2023-06-01",
            "Accept: text/event-stream",
        ],
        CURLOPT_USERAGENT => "curl/7.88.1",
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_WRITEFUNCTION => function ($curlHandle, $chunk) use (&$accumulatedText, &$streamError, &$sseBuffer) {
            $sseBuffer .= $chunk;
            $lastNewlinePos = strrpos($sseBuffer, "\n");
            if ($lastNewlinePos === false) {
                return strlen($chunk);
            }
            $completeLines = substr($sseBuffer, 0, $lastNewlinePos);
            $sseBuffer = substr($sseBuffer, $lastNewlinePos + 1);

            foreach (explode("\n", $completeLines) as $line) {
                $line = trim($line);
                if (strpos($line, "data:") !== 0) {
                    continue;
                }
                $jsonStr = trim(substr($line, 5));
                if ($jsonStr === "" || $jsonStr === "[DONE]") {
                    continue;
                }
                $event = json_decode($jsonStr, true);
                if (!is_array($event)) {
                    continue;
                }
                if (($event["type"] ?? "") === "content_block_delta"
                    && ($event["delta"]["type"] ?? "") === "text_delta") {
                    $accumulatedText .= $event["delta"]["text"];
                }
                if (($event["type"] ?? "") === "error") {
                    $streamError = $event["error"]["message"] ?? "unknown stream error";
                }
            }
            return strlen($chunk);
        },
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ["error" => "cURL error: " . $curlError];
    }
    if ($streamError) {
        return ["error" => "API stream error: " . $streamError];
    }
    if ($httpCode !== 200) {
        return ["error" => "API error ({$httpCode}), накоплено символов: " . strlen($accumulatedText)];
    }
    if (trim($accumulatedText) === "") {
        return ["error" => "Пустой ответ от API (стрим завершился без текста)"];
    }

    return ["text" => cleanGeneratedText($accumulatedText)];
}

function cleanGeneratedText($text)
{
    $text = trim($text);
    $text = preg_replace('/^```(?:html)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $text = trim($text);

    if ($text !== "" && $text[0] !== "<") {
        if (preg_match('/<(p|h2|h3|ul)\b/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, $m[0][1]);
        }
    }

    if (preg_match('/^<p>[^<]{1,20}(<h[23]\b)/iu', $text, $m2, PREG_OFFSET_CAPTURE)) {
        $text = substr($text, $m2[1][1]);
    }

    return trim($text);
}

// ==================== IndexNow ====================

function ensureIndexNowKeyFile()
{
    $keyFilePath = SITE_DOCUMENT_ROOT . "/" . INDEXNOW_KEY . ".txt";
    if (!file_exists($keyFilePath)) {
        file_put_contents($keyFilePath, INDEXNOW_KEY);
    }
    return $keyFilePath;
}

function getProductUrl($productId)
{
    // ВАЖНО: схема oc_seo_url различается между версиями/форками.
    // Пробуем современный формат OC 3.0.3.x+ (key/value/keyword):
    try {
        $sql = "SELECT keyword FROM " . TBL_PREFIX . "seo_url
                WHERE `key` = 'product_id' AND `value` = :id
                AND store_id = :store AND language_id = :lang LIMIT 1";
        $stmt = db()->prepare($sql);
        $stmt->execute(["id" => $productId, "store" => STORE_ID, "lang" => LANGUAGE_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return SITE_PROTOCOL . "://" . SITE_HOST . "/" . $row["keyword"];
        }
    } catch (\Throwable $e) {
        // возможно, таких колонок нет — пробуем старый формат ниже
    }

    // Старый формат OC2.x / некоторых сборок OcStore (query='product_id=X'):
    try {
        $sql = "SELECT keyword FROM " . TBL_PREFIX . "seo_url
                WHERE query = :query AND store_id = :store LIMIT 1";
        $stmt = db()->prepare($sql);
        $stmt->execute(["query" => "product_id=" . $productId, "store" => STORE_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return SITE_PROTOCOL . "://" . SITE_HOST . "/" . $row["keyword"];
        }
    } catch (\Throwable $e) {
        // не подошло — вернём null
    }

    return null;
}

function submitIndexNow($urls)
{
    if (empty($urls)) {
        return ["error" => "Список URL пуст — нечего отправлять."];
    }

    $batches = array_chunk($urls, 1000);
    $results = [];

    foreach ($batches as $batch) {
        $payload = json_encode([
            "host" => SITE_HOST,
            "key" => INDEXNOW_KEY,
            "keyLocation" => SITE_PROTOCOL . "://" . SITE_HOST . "/" . INDEXNOW_KEY . ".txt",
            "urlList" => $batch,
        ]);

        $ch = curl_init("https://api.indexnow.org/indexnow");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json; charset=utf-8"],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $results[] = [
            "batch_size" => count($batch),
            "http_code" => $httpCode,
            "error" => $error ?: null,
            "response" => $response,
        ];
    }

    return ["results" => $results];
}

// ==================== РЕЖИМ: REVIEW ====================

function runReview($sectionId, $extraInstructions, $limit = 0)
{
    if (empty(ROUTER_CHEAP_API_KEY)) {
        echo "Ошибка: не задан ROUTER_CHEAP_API_KEY (переменная окружения).\n";
        exit(1);
    }
    if (empty($sectionId)) {
        echo "Ошибка: не указана категория. Запусти как: php ai_generate_descriptions_oc.php review <CATEGORY_ID> [LIMIT]\n";
        exit(1);
    }

    $elementIds = getElementIdsBySection($sectionId);

    if (empty($elementIds)) {
        echo "В категории {$sectionId} нет активных товаров.\n";
        exit(0);
    }

    $totalInSection = count($elementIds);

    if ($limit > 0 && $limit < count($elementIds)) {
        $elementIds = array_slice($elementIds, 0, $limit);
    }

    echo "Всего товаров в категории: {$totalInSection}\n";
    echo "Будет обработано в этом запуске: " . count($elementIds) . "\n";

    $usedPromptFile = basename(getPromptFilePath($sectionId, $GLOBALS['SECTION_PROMPT_FILES']));
    echo "Используется файл промпта: prompts/{$usedPromptFile}\n";
    if (!empty($extraInstructions)) {
        echo "(плюс доп. инструкции для категории {$sectionId})\n";
    }
    echo "\n";

    $reportFile = getReportFile($sectionId);

    $report = [];
    $doneIds = [];
    if (file_exists($reportFile)) {
        $existing = json_decode(file_get_contents($reportFile), true);
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (in_array($item["status"] ?? "", ["approved", "applied"], true)) {
                    $report[$item["id"]] = $item;
                    $doneIds[$item["id"]] = true;
                }
            }
            if (!empty($doneIds)) {
                echo "Найден предыдущий отчёт: " . count($doneIds) . " товаров уже успешно сгенерированы, пропускаю их.\n\n";
            }
        }
    }

    $saveReport = function () use (&$report, $reportFile) {
        file_put_contents($reportFile, json_encode(array_values($report), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    };

    foreach ($elementIds as $id) {
        if (isset($doneIds[$id])) {
            continue;
        }

        echo "Обрабатываю товар ID {$id}... ";

        try {
            $product = getProductContext($id);
        } catch (\Throwable $e) {
            echo "обрыв БД (" . $e->getMessage() . "), переподключаюсь... ";
            reconnectDatabase();
            try {
                $product = getProductContext($id);
            } catch (\Throwable $e2) {
                echo "ОШИБКА БД после переподключения: {$e2->getMessage()}\n";
                $report[$id] = [
                    "id" => $id,
                    "name" => "",
                    "status" => "error",
                    "error" => "DB error: " . $e2->getMessage(),
                ];
                $saveReport();
                continue;
            }
        }

        if (!$product) {
            echo "не найден, пропуск.\n";
            continue;
        }

        $prompt = buildPrompt($product, $sectionId, $extraInstructions, $GLOBALS['SECTION_PROMPT_FILES']);
        $result = callClaudeAPI($prompt);

        if (isset($result["error"])) {
            echo "ОШИБКА: {$result['error']}\n";
            $report[$id] = [
                "id" => $id,
                "name" => $product["name"],
                "status" => "error",
                "error" => $result["error"],
            ];
            $saveReport();
            continue;
        }

        echo "готово (" . mb_strlen($result["text"]) . " симв.)\n";

        // Приклеиваем фиксированный коммерческий блок кодом (не ИИ) — так
        // гарантированно сохраняются правильные плейсхолдеры %CITY%/%PHONE%/%EMAIL%,
        // которые сайт сам заменит на нужные значения при отображении.
        $finalText = trim($result["text"]) . "\n\n" . COMMERCIAL_FOOTER_HTML;

        $report[$id] = [
            "id" => $id,
            "name" => $product["name"],
            "status" => "approved",
            "detail_text" => $finalText,
        ];
        $saveReport();

        usleep(500000);
    }

    echo "\nГотово. Отчёт сохранён: " . $reportFile . "\n";
    echo "Затем запусти: php ai_generate_descriptions_oc.php apply {$sectionId}\n";
}

function getReportFile($sectionId)
{
    return __DIR__ . "/ai_desc_report_section_{$sectionId}.json";
}

// ==================== OCFILTER: SEO-СТРАНИЦЫ ФИЛЬТРОВ ====================
//
// Модуль OCFilter — отдельная от товаров сущность: "страница фильтра"
// (например "Арматура сталь 09Г2С") привязана к категории и к набору
// параметров фильтра (params), и имеет собственный SEO-адрес (keyword).
//
// ВАЖНО: этот блок написан по схеме таблиц, снятой через phpMyAdmin
// (oc_ocfilter_page / oc_ocfilter_page_description / oc_ocfilter_option /
// oc_ocfilter_option_value), но НЕ протестирован на реальной базе — перед
// массовым использованием обязательно проверь работу на 1-2 страницах
// вручную (создать через genocfilter+applyocfilter, открыть страницу на
// сайте, сверить с тем, что видно в админке Каталог → OCFilter → Страницы).
//
// Формат входного файла (одна страница на строку, поля через ';'):
//   KEYWORD;CATEGORY;PARAMS
//   KEYWORD  — желаемый SEO-адрес страницы, напр. armatura-stal-09g2s
//   CATEGORY — ID категории (число) ИЛИ её keyword/слаг в URL
//   PARAMS   — путь параметров фильтра, напр. marka/09g2s
//              (для нескольких фильтров через /: marka/m1/tip/shmm)
//   Пустые строки и строки, начинающиеся с #, пропускаются.

function getOcFilterInputFile($batchId)
{
    return __DIR__ . "/ocfilter_urls_batch_{$batchId}.txt";
}

function getOcFilterReportFile($batchId)
{
    return __DIR__ . "/ai_ocfilter_report_batch_{$batchId}.json";
}

function getOcFilterPromptFilePath($batchId, $sectionPromptFiles)
{
    $key = "ocfilter_" . $batchId;
    if (isset($sectionPromptFiles[$key])) {
        $path = OCFILTER_PROMPTS_DIR . "/" . $sectionPromptFiles[$key];
        if (file_exists($path)) {
            return $path;
        }
    }
    return OCFILTER_PROMPTS_DIR . "/default.txt";
}

function ensureOcFilterDefaultPrompt()
{
    if (!is_dir(OCFILTER_PROMPTS_DIR)) {
        @mkdir(OCFILTER_PROMPTS_DIR, 0755, true);
    }
    $default = OCFILTER_PROMPTS_DIR . "/default.txt";
    if (!file_exists($default)) {
        $starter = "Ты — SEO-копирайтер металлоторговой компании.\n\n"
            . "Напиши уникальный SEO-текст для страницы фильтра каталога интернет-магазина.\n\n"
            . "Категория: {{CATEGORY_NAME}}\n"
            . "Параметры фильтра (технические, необязательно понятные без контекста): {{PARAMS}}\n"
            . "Желаемый общий смысл страницы (заголовок H1 подбери сам, по-русски, коротко и по-товарному): {{KEYWORD}}\n\n"
            . "Требования к тексту:\n"
            . "1. Объём 150-300 слов, HTML-теги <p>/<h2> (без markdown, без <h1> — заголовок H1 сайт возьмёт из отдельного поля).\n"
            . "2. Не выдумывай характеристики и ГОСТы, которых нет в параметрах фильтра или в названии категории.\n"
            . "3. Не пиши контактный блок/призыв к действию в конце — сайт добавит его сам кодом.\n"
            . "4. Без рекламных клише, пиши по-деловому.\n";
        @file_put_contents($default, $starter);
    }
}

// Фиксированный блок-приписка, добавляется кодом к ЛЮБОМУ промпту OCFilter
// (в т.ч. к кастомным, привязанным через редактор) — просит модель после
// основного текста страницы вернуть ещё TITLE/H1/META_DESCRIPTION, чтобы
// name/title/meta_title/meta_description на сайте не собирались программно
// из сырых "Категория - параметр" (что даёт неуникальные шаблонные заголовки),
// а были действительно написаны ИИ и отличались от других страниц категории.
function ocFilterMetaInstructionBlock($categoryName, $params)
{
    return "\n\n---\n"
        . "После основного текста страницы ОБЯЗАТЕЛЬНО выведи ещё три блока, каждый на отдельной строке, СТРОГО в этом формате (без кавычек, без markdown):\n\n"
        . "[TITLE]заголовок для <title> страницы, до 55 символов[/TITLE]\n"
        . "[H1]заголовок H1 страницы, до 60 символов[/H1]\n"
        . "[META_DESCRIPTION]SEO-описание для meta description, 200-300 символов[/META_DESCRIPTION]\n\n"
        . "Требования к TITLE/H1/META_DESCRIPTION:\n"
        . "- Каждый из трёх текстов уникален и НЕ повторяет дословно (и почти дословно) формулировки, которые естественно возникли бы для других страниц категории «{$categoryName}» с другим значением параметра фильтра.\n"
        . "- Обязательно включает название категории «{$categoryName}» (в подходящей словоформе) и конкретное значение параметра фильтра ({$params}).\n"
        . "- TITLE - это ТОЛЬКО категория с характеристикой (например «Лист стальной гладкий марки 09Г2С»), БЕЗ названия компании и БЕЗ коммерческих фраз («купить», «в наличии» и т.п.) - коммерческую часть и название компании к TITLE добавит код автоматически после твоего ответа, дублировать их не нужно.\n"
        . "- H1 не обязан быть одинаковым с TITLE - допустима разная формулировка одного смысла. H1 - БЕЗ упоминания города (« в Москве», « в %CITYS%» и т.п.) - код добавит город к H1 сам после твоего ответа. H1 - ТОЛЬКО название категории/товара + характеристика (например «Лист стальной гладкий марки Р6М5»), БЕЗ дефисов/тире внутри H1 и БЕЗ слов «каталог», «страница», «раздел» - никаких служебных приписок, город (код добавит сам) и так уже сделает заголовок полным.\n"
        . "- Используй обычный дефис «-», НИКОГДА длинное тире «—» или среднее «–».\n"
        . "- Не придумывай характеристики, свойства марки, применение, ГОСТы - те же ограничения, что и для основного текста. Пиши так, будто не знаешь, что означает обозначение марки - только называй её, без ярлыков вроде «конструкционная», «инструментальная», «нержавеющая», «легированная», без глаголов «применяется», «используется», «отличается», «предназначена» рядом с названием марки.\n"
        . "- В H1 и META_DESCRIPTION ЗАПРЕЩЕНЫ слова и фразы про наличие/сроки/цену/призыв к действию: «в наличии», «на складе», «купить», «заказать», «доставка», «цена», «недорого», «скидка».\n"
        . "- META_DESCRIPTION - самостоятельный содержательный текст (не обрезок основного текста), 2-3 законченных предложения. Можешь естественно упомянуть город и/или контакты через плейсхолдеры %CITY% (именительный падеж), %CITYS% (предложный падеж), %TELEPHONE%, %EMAIL% - но ТОЛЬКО этими четырьмя словами В ТОЧНОСТИ, с символами % с обеих сторон, без изменений и без своих плейсхолдеров. %CITYS% УЖЕ в предложном падеже (после подстановки получится, например, «Москве») - перед ним ВСЕГДА ставь предлог «в» вплотную: пиши «в %CITYS%», никогда не пиши голое «%CITYS%» без «в» перед ним (иначе получится грамматическая ошибка вида «...по телефону Москве...»). Использовать плейсхолдеры не обязательно - только если естественно вписывается.\n";
}

function buildOcFilterPrompt($categoryName, $params, $keyword, $batchId, $sectionPromptFiles)
{
    ensureOcFilterDefaultPrompt();
    $promptFile = getOcFilterPromptFilePath($batchId, $sectionPromptFiles);
    if (!file_exists($promptFile)) {
        return "ОШИБКА КОНФИГУРАЦИИ: файл промпта не найден: {$promptFile}";
    }
    $template = file_get_contents($promptFile);
    $template .= ocFilterMetaInstructionBlock($categoryName, $params);
    $replacements = [
        "{{CATEGORY_NAME}}" => $categoryName,
        "{{PARAMS}}" => $params,
        "{{KEYWORD}}" => $keyword,
    ];
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

// Короткий повторный запрос — только TITLE/H1/META_DESCRIPTION, без основного
// текста страницы. Используется, если с первого раза модель не вернула
// валидные блоки (перед откатом на программный шаблон).
function buildOcFilterMetaRetryPrompt($categoryName, $params)
{
    return "Категория страницы фильтра каталога металлопроката: «{$categoryName}».\n"
        . "Параметр страницы: {$params}.\n\n"
        . "Выведи ТОЛЬКО три блока (без основного текста, без пояснений):\n\n"
        . "[TITLE]заголовок для <title> страницы, до 55 символов - ТОЛЬКО категория с характеристикой, БЕЗ названия компании и коммерческих фраз (их добавит код)[/TITLE]\n"
        . "[H1]заголовок H1 страницы, до 60 символов, БЕЗ упоминания города (код добавит его сам), ТОЛЬКО название+характеристика, БЕЗ дефисов внутри и БЕЗ слов «каталог»/«страница»/«раздел»[/H1]\n"
        . "[META_DESCRIPTION]SEO-описание для meta description, 200-300 символов, 2-3 предложения[/META_DESCRIPTION]\n\n"
        . "Требования:\n"
        . "- Включи название категории и значение параметра.\n"
        . "- Обычный дефис «-» в TITLE/META_DESCRIPTION можно, но НЕ используй «—»/«–» нигде и НЕ используй дефис вообще в H1.\n"
        . "- Пиши так, будто не знаешь, что означает обозначение марки - только называй её, без ярлыков («конструкционная», «инструментальная», «нержавеющая» и т.п.) и без глаголов «применяется/используется/отличается» рядом с маркой.\n"
        . "- В H1 и META_DESCRIPTION запрещены «в наличии», «на складе», «купить», «заказать», «доставка», «цена», «недорого», «скидка».\n"
        . "- В META_DESCRIPTION можно упомянуть %CITY%/%CITYS%/%TELEPHONE%/%EMAIL% - только этими словами В ТОЧНОСТИ, если естественно вписывается. %CITYS% - это город уже в предложном падеже (после подстановки получится, например, «Москве»), поэтому ПЕРЕД %CITYS% ВСЕГДА ставь предлог «в» вплотную: «в %CITYS%» (например «консультации в %CITYS% по телефону...», а НЕ «консультации %CITYS% по телефону...» - без «в» перед %CITYS% получится грамматическая ошибка).\n";
}

// Извлекает TITLE/H1/META_DESCRIPTION из ответа модели и возвращает
// [поля_или_null, текст_без_этих_блоков]. null означает "невалидно" -
// вызывающий код должен либо сделать повторный запрос, либо откатиться
// на программный шаблон.
function extractOcFilterMetaFields($text)
{
    $fields = [];
    $clean = $text;
    $patterns = [
        "title" => '/\[TITLE\](.*?)\[\/TITLE\]/su',
        "h1" => '/\[H1\](.*?)\[\/H1\]/su',
        "meta_description" => '/\[META_DESCRIPTION\](.*?)\[\/META_DESCRIPTION\]/su',
    ];
    foreach ($patterns as $key => $pattern) {
        if (!preg_match($pattern, $text, $m)) {
            return [null, $text];
        }
        $value = trim(strip_tags($m[1]));
        $value = str_replace(["—", "–", "―"], "-", $value);
        if ($value === "") {
            return [null, $text];
        }
        $fields[$key] = $value;
        $clean = preg_replace($pattern, "", $clean);
    }

    if (mb_strlen($fields["title"]) > 250 || mb_strlen($fields["h1"]) > 250) {
        return [null, $text];
    }
    if (mb_strlen($fields["meta_description"]) > 500) {
        return [null, $text];
    }

    // Плейсхолдеры %CITY%/%CITYS%/%TELEPHONE%/%EMAIL% модель может вписать в
    // META_DESCRIPTION — но только этими четырьмя словами В ТОЧНОСТИ. Если
    // модель написала что-то похожее, но кривое (%ГОРОД%, %City%,
    // %CITYS %, и т.п.) - оно улетит на сайт нераскрытым текстом, поэтому
    // считаем такой ответ невалидным (уйдёт в ретрай/откат на шаблон).
    $allowedMacros = ["%CITY%", "%CITYS%", "%TELEPHONE%", "%EMAIL%"];
    if (preg_match_all('/%[A-Za-zА-Яа-яЁё_]+%/u', $fields["meta_description"], $macroMatches)) {
        foreach ($macroMatches[0] as $macro) {
            if (!in_array($macro, $allowedMacros, true)) {
                return [null, $text];
            }
        }
    }

    $clean = preg_replace('/\n{3,}/', "\n\n", trim($clean));
    return [$fields, $clean];
}

// Аккуратно обрезает текст под мета-описание: сначала пробует уложиться в
// лимит целыми предложениями (по точке), иначе обрезает по последнему
// пробелу перед лимитом — чтобы не рвать слово посередине, как делал
// голый mb_substr($text, 0, 250).
function ocFilterBuildMetaDescription($html, $limit = 250)
{
    $text = trim(strip_tags($html));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    // Пробуем взять целые предложения, пока укладываемся в лимит.
    preg_match_all('/[^.!?]+[.!?]+/u', $text, $matches);
    if (!empty($matches[0])) {
        $result = "";
        foreach ($matches[0] as $sentence) {
            $candidate = $result . $sentence;
            if (mb_strlen(trim($candidate)) > $limit) {
                break;
            }
            $result = $candidate;
        }
        $result = trim($result);
        if ($result !== "") {
            return $result;
        }
    }

    // Ни одно предложение целиком не влезло — обрезаем по последнему пробелу.
    $cut = mb_substr($text, 0, $limit);
    $lastSpace = mb_strrpos($cut, " ");
    if ($lastSpace !== false) {
        $cut = mb_substr($cut, 0, $lastSpace);
    }
    return rtrim($cut, " ,;:-") . "...";
}

// Разбор одной строки входного файла. Поддерживает два формата:
//   1) Готовый URL страницы:  https://fe-rus.ru/list-stalnoj-gladkij/marka/r6m5/
//      Тогда KEYWORD/CATEGORY/PARAMS выводятся автоматически из пути:
//        - CATEGORY = первый сегмент пути (слаг категории)
//        - PARAMS   = остаток пути (тип/значение фильтра)
//        - KEYWORD  = весь путь целиком (это и есть итоговый адрес страницы)
//   2) Явный формат:  KEYWORD;CATEGORY;PARAMS
function parseOcFilterInputLine($line)
{
    $line = trim($line);
    if ($line === "" || $line[0] === "#") {
        return null;
    }

    // Формат 1: вставлена прямая ссылка на страницу.
    if (preg_match('~^https?://~i', $line)) {
        $path = parse_url($line, PHP_URL_PATH);
        $path = trim((string)$path, "/");
        if ($path === "") {
            return null;
        }
        $segments = explode("/", $path);
        if (count($segments) < 2) {
            // нет хотя бы "категория/параметр" — недостаточно данных
            return null;
        }
        $categorySlug = $segments[0];
        $params = implode("/", array_slice($segments, 1));
        // ВАЖНО (уточнено по реальной админке OCFilter, поле "SEO псевдоним"):
        // keyword - это ТОЛЬКО значение фильтра, последний сегмент URL
        // (например "s355-5"), БЕЗ типа фильтра и БЕЗ слага категории. Сегмент
        // типа фильтра ("marka/") сайт достраивает сам из поля "Параметры
        // фильтра" (params), а слаг категории - из самой категории. Если
        // положить в keyword params целиком ("marka/s355-5") или весь путь -
        // получится задвоение на сайте.
        $keyword = end($segments);
        return [
            "keyword" => $keyword,
            "category_input" => $categorySlug,
            "params" => $params,
        ];
    }

    // Формат 2: явное перечисление через точку с запятой.
    $parts = array_map("trim", explode(";", $line));
    if (count($parts) < 3) {
        return null;
    }
    return [
        "keyword" => $parts[0],
        "category_input" => $parts[1],
        "params" => $parts[2],
    ];
}

// Находит category_id по числовому ID или по keyword в oc_seo_url; заодно
// отдаёт человекочитаемое название категории (для промпта и для полей
// meta/H1 SEO-страницы).
function resolveOcFilterCategory($categoryInput)
{
    $categoryInput = trim((string)$categoryInput);
    $categoryId = null;

    if (ctype_digit($categoryInput)) {
        $categoryId = (int)$categoryInput;
    } else {
        try {
            $sql = "SELECT `value` FROM " . TBL_PREFIX . "seo_url
                    WHERE `key` = 'category_id' AND keyword = :kw
                    AND store_id = :store AND language_id = :lang LIMIT 1";
            $stmt = db()->prepare($sql);
            $stmt->execute(["kw" => $categoryInput, "store" => STORE_ID, "lang" => LANGUAGE_ID]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $categoryId = (int)$row["value"];
            }
        } catch (\Throwable $e) {
            // возможно, старая схема seo_url — пробуем ниже через query=
        }
        if ($categoryId === null) {
            try {
                $sql = "SELECT query FROM " . TBL_PREFIX . "seo_url
                        WHERE keyword = :kw AND store_id = :store LIMIT 1";
                $stmt = db()->prepare($sql);
                $stmt->execute(["kw" => $categoryInput, "store" => STORE_ID]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && preg_match('/category_id=(\d+)/', $row["query"], $m)) {
                    $categoryId = (int)$m[1];
                }
            } catch (\Throwable $e) {
                // не подошло
            }
        }
    }

    if ($categoryId === null) {
        return null;
    }

    $sql = "SELECT name FROM " . TBL_PREFIX . "category_description
            WHERE category_id = :id AND language_id = :lang LIMIT 1";
    $stmt = db()->prepare($sql);
    $stmt->execute(["id" => $categoryId, "lang" => LANGUAGE_ID]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $categoryName = $row ? $row["name"] : "Категория {$categoryId}";

    return ["category_id" => $categoryId, "category_name" => $categoryName];
}

function runGenOcFilter($batchId, $limit = 0)
{
    if (empty(ROUTER_CHEAP_API_KEY)) {
        echo "Ошибка: не задан ROUTER_CHEAP_API_KEY (переменная окружения).\n";
        exit(1);
    }

    $inputFile = getOcFilterInputFile($batchId);
    if (!file_exists($inputFile)) {
        echo "Не найден файл со списком страниц: {$inputFile}\n";
        echo "GUI должен загрузить его на сервер перед запуском genocfilter.\n";
        exit(1);
    }

    $lines = file($inputFile, FILE_IGNORE_NEW_LINES);
    $items = [];
    foreach ($lines as $line) {
        $parsed = parseOcFilterInputLine($line);
        if ($parsed) {
            $items[] = $parsed;
        }
    }

    if (empty($items)) {
        echo "Файл {$inputFile} не содержит ни одной корректной строки (формат: KEYWORD;CATEGORY;PARAMS).\n";
        exit(0);
    }

    $totalInBatch = count($items);
    if ($limit > 0 && $limit < count($items)) {
        $items = array_slice($items, 0, $limit);
    }

    echo "Всего страниц в списке: {$totalInBatch}\n";
    echo "Будет обработано в этом запуске: " . count($items) . "\n\n";

    $reportFile = getOcFilterReportFile($batchId);
    $report = [];
    $doneKeywords = [];
    if (file_exists($reportFile)) {
        $existing = json_decode(file_get_contents($reportFile), true);
        if (is_array($existing)) {
            foreach ($existing as $item) {
                if (in_array($item["status"] ?? "", ["generated", "applied"], true)) {
                    $report[$item["keyword"]] = $item;
                    $doneKeywords[$item["keyword"]] = true;
                }
            }
            if (!empty($doneKeywords)) {
                echo "Найден предыдущий отчёт: " . count($doneKeywords) . " страниц уже сгенерированы, пропускаю их.\n\n";
            }
        }
    }

    $saveReport = function () use (&$report, $reportFile) {
        file_put_contents($reportFile, json_encode(array_values($report), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    };

    global $SECTION_PROMPT_FILES;

    foreach ($items as $item) {
        $keyword = $item["keyword"];
        if (isset($doneKeywords[$keyword])) {
            continue;
        }

        echo "Обрабатываю страницу '{$keyword}'... ";

        $category = resolveOcFilterCategory($item["category_input"]);
        if (!$category) {
            echo "не удалось определить категорию '{$item['category_input']}', пропуск.\n";
            $report[$keyword] = [
                "keyword" => $keyword,
                "category_input" => $item["category_input"],
                "params" => $item["params"],
                "status" => "error",
                "error" => "Категория не найдена",
            ];
            $saveReport();
            continue;
        }

        $prompt = buildOcFilterPrompt($category["category_name"], $item["params"], $keyword, $batchId, $SECTION_PROMPT_FILES);
        $result = callClaudeAPI($prompt);

        if (isset($result["error"])) {
            echo "ОШИБКА: {$result['error']}\n";
            $report[$keyword] = [
                "keyword" => $keyword,
                "category_id" => $category["category_id"],
                "category_name" => $category["category_name"],
                "params" => $item["params"],
                "status" => "error",
                "error" => $result["error"],
            ];
            $saveReport();
            continue;
        }

        echo "готово (" . mb_strlen($result["text"]) . " симв.)\n";

        // Пытаемся вытащить уникальные TITLE/H1/META_DESCRIPTION, которые
        // модель должна была дописать в конец ответа (см.
        // ocFilterMetaInstructionBlock). Если не получилось - одна короткая
        // повторная попытка, и только потом откат на программный шаблон
        // "Категория - параметр" (гарантированно уникальный по параметру,
        // но не такой качественный с точки зрения SEO-формулировки).
        list($metaFields, $bodyText) = extractOcFilterMetaFields($result["text"]);
        $metaSource = "ai";

        if ($metaFields === null) {
            echo "  [meta] модель не вернула валидные TITLE/H1/META_DESCRIPTION с первого раза - пробую коротким повторным запросом...\n";
            $retryPrompt = buildOcFilterMetaRetryPrompt($category["category_name"], $item["params"]);
            $retryResult = callClaudeAPI($retryPrompt);
            if (!isset($retryResult["error"])) {
                list($retryFields, ) = extractOcFilterMetaFields($retryResult["text"]);
                if ($retryFields !== null) {
                    $metaFields = $retryFields;
                    $metaSource = "ai_retry";
                    echo "  [meta] повторный запрос успешен.\n";
                } else {
                    echo "  [meta] повторный запрос тоже не дал валидных полей.\n";
                }
            } else {
                echo "  [meta] ошибка повторного запроса: {$retryResult['error']}\n";
            }
        }

        // Дефис вместо длинного тире - см. правило "без длинных тире в метатегах".
        $fallbackName = trim($category["category_name"] . " - " . str_replace("/", " ", $item["params"]));

        if ($metaFields !== null) {
            $pageTitle = $metaFields["title"];
            $pageH1 = $metaFields["h1"];
            $pageMetaDescription = $metaFields["meta_description"];
        } else {
            echo "  [meta] использую программный шаблон вместо уникальных TITLE/H1/META_DESCRIPTION.\n";
            $metaSource = "fallback";
            $pageTitle = $fallbackName;
            $pageH1 = $fallbackName;
            $pageMetaDescription = ocFilterBuildMetaDescription($bodyText, 300);
        }

        // ВАЖНО: поле "title" в этой БД подписано в админке как
        // "Название (H1)" - т.е. именно title рендерится как <h1> на сайте.
        // Поле "name" (и "button") - это отдельное "название SEO-страницы",
        // которое показывается в списках админки (и как текст ссылки/пункта
        // меню фильтра на сайте) - в него НИЧЕГО, кроме названия страницы
        // с характеристикой (значением параметра), добавлять нельзя: ни
        // города, ни каких-либо хвостов.
        $pageNameClean = rtrim($pageH1, " .");

        // %CITYS% (город в предложном падеже) добавляем в H1 кодом (не ИИ) -
        // гарантированно правильный плейсхолдер на каждой странице.
        // Это только для title (реального <h1> на сайте), НЕ для name/button.
        $pageH1 = $pageNameClean . " в %CITYS%";

        // Коммерческий хвост "купить в %CITYS% - ООО "Ферус"" — ТОЛЬКО для
        // meta_title (реальный <title>/сниппет в поиске), кодом (не ИИ), чтобы
        // плейсхолдеры и название компании были гарантированно правильными.
        $pageMetaTitle = rtrim($pageTitle, " .") . OCFILTER_TITLE_SUFFIX;

        // Контактный блок с %CITYS%/%TELEPHONE%/%EMAIL% — тоже кодом, тоже
        // после того, как meta_description уже посчитан из чистого $bodyText
        // (иначе в meta_description мог попасть обрезок контактного блока).
        $finalBodyText = trim($bodyText) . "\n\n" . OCFILTER_DESCRIPTION_FOOTER_HTML;

        $report[$keyword] = [
            "keyword" => $keyword,
            "category_id" => $category["category_id"],
            "category_name" => $category["category_name"],
            "params" => $item["params"],
            "status" => "generated",
            "name" => $pageNameClean,
            "title" => $pageH1,
            "meta_title" => $pageMetaTitle,
            "meta_keyword" => "",
            "meta_description" => $pageMetaDescription,
            "description" => $finalBodyText,
            "button" => $pageNameClean,
            "meta_phrase_source" => $metaSource,
        ];
        $saveReport();

        usleep(500000);
    }

    echo "\nГотово. Отчёт сохранён: " . $reportFile . "\n";
    echo "Затем запусти: php ai_generate_descriptions_oc.php applyocfilter {$batchId}\n";
}

function runApplyOcFilter($batchId)
{
    $reportFile = getOcFilterReportFile($batchId);
    if (!file_exists($reportFile)) {
        echo "Файл отчёта не найден: {$reportFile}. Сначала запусти genocfilter.\n";
        exit(1);
    }

    $report = json_decode(file_get_contents($reportFile), true);
    if (!is_array($report)) {
        echo "Отчёт повреждён: {$reportFile}\n";
        exit(1);
    }

    $changed = false;

    foreach ($report as $idx => $item) {
        if (($item["status"] ?? "") !== "generated") {
            continue;
        }

        $keyword = $item["keyword"];
        echo "Применяю страницу '{$keyword}' (категория {$item['category_id']})... ";

        try {
            db()->beginTransaction();

            // Ищем существующую страницу с таким keyword в этой категории —
            // если есть, обновляем, а не плодим дубли.
            $sql = "SELECT ocfilter_page_id FROM " . TBL_PREFIX . "ocfilter_page
                    WHERE keyword = :kw AND category_id = :cat LIMIT 1";
            $stmt = db()->prepare($sql);
            $stmt->execute(["kw" => $keyword, "cat" => $item["category_id"]]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pageId = (int)$existing["ocfilter_page_id"];
                $sql = "UPDATE " . TBL_PREFIX . "ocfilter_page
                        SET params = :params, date_modified = NOW()
                        WHERE ocfilter_page_id = :id";
                $stmt = db()->prepare($sql);
                $stmt->execute(["params" => $item["params"], "id" => $pageId]);
            } else {
                // `over` — зарезервированное слово в MySQL, экранируем бэктиками.
                $sql = "INSERT INTO " . TBL_PREFIX . "ocfilter_page
                        (category_id, keyword, params, `over`, sort_order, menu_status, status, statusview, top, date_modified)
                        VALUES (:cat, :kw, :params, 'category', 0, 0, 1, 1, 0, NOW())";
                $stmt = db()->prepare($sql);
                $stmt->execute([
                    "cat" => $item["category_id"],
                    "kw" => $keyword,
                    "params" => $item["params"],
                ]);
                $pageId = (int)db()->lastInsertId();
            }

            $sql = "SELECT ocfilter_page_id FROM " . TBL_PREFIX . "ocfilter_page_description
                    WHERE ocfilter_page_id = :id AND language_id = :lang";
            $stmt = db()->prepare($sql);
            $stmt->execute(["id" => $pageId, "lang" => LANGUAGE_ID]);
            $descExists = $stmt->fetch(PDO::FETCH_ASSOC);

            $descFields = [
                "meta_title" => $item["meta_title"],
                "meta_keyword" => $item["meta_keyword"],
                "meta_description" => $item["meta_description"],
                "description" => $item["description"],
                "title" => $item["title"],
                "name" => $item["name"],
                "button" => $item["button"],
            ];

            if ($descExists) {
                $sql = "UPDATE " . TBL_PREFIX . "ocfilter_page_description SET
                            meta_title = :meta_title, meta_keyword = :meta_keyword,
                            meta_description = :meta_description, description = :description,
                            title = :title, name = :name, button = :button
                        WHERE ocfilter_page_id = :id AND language_id = :lang";
                $stmt = db()->prepare($sql);
                $stmt->execute($descFields + ["id" => $pageId, "lang" => LANGUAGE_ID]);
            } else {
                $sql = "INSERT INTO " . TBL_PREFIX . "ocfilter_page_description
                            (ocfilter_page_id, language_id, meta_title, meta_keyword, meta_description, description, title, name, button)
                        VALUES (:id, :lang, :meta_title, :meta_keyword, :meta_description, :description, :title, :name, :button)";
                $stmt = db()->prepare($sql);
                $stmt->execute($descFields + ["id" => $pageId, "lang" => LANGUAGE_ID]);
            }

            db()->commit();

            $report[$idx]["status"] = "applied";
            $report[$idx]["ocfilter_page_id"] = $pageId;
            $changed = true;
            echo "готово (ocfilter_page_id={$pageId}).\n";
        } catch (\Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            echo "ОШИБКА: {$e->getMessage()}\n";
            $report[$idx]["status"] = "error";
            $report[$idx]["error"] = $e->getMessage();
            $changed = true;
        }

        if ($changed) {
            file_put_contents($reportFile, json_encode(array_values($report), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    echo "\nГотово.\n";
}

function getOcFilterPageUrl($keyword)
{
    return SITE_PROTOCOL . "://" . SITE_HOST . "/" . $keyword;
}

// ==================== РЕЖИМ: APPLY ====================

function runApply($sectionId)
{
    if (empty($sectionId)) {
        echo "Ошибка: не указана категория. Запусти как: php ai_generate_descriptions_oc.php apply <CATEGORY_ID>\n";
        exit(1);
    }

    $reportFile = getReportFile($sectionId);

    if (!file_exists($reportFile)) {
        echo "Файл отчёта не найден: " . $reportFile . ". Сначала запусти review.\n";
        exit(1);
    }

    $report = json_decode(file_get_contents($reportFile), true);
    $changed = false;

    $sql = "UPDATE " . TBL_PREFIX . "product_description
            SET description = :text
            WHERE product_id = :id AND language_id = :lang";
    $stmt = db()->prepare($sql);

    foreach ($report as $idx => $item) {
        if (($item["status"] ?? "") !== "approved") {
            echo "ID {$item['id']}: пропуск (статус: {$item['status']}, не 'approved')\n";
            continue;
        }

        try {
            $stmt->execute([
                "text" => $item["detail_text"],
                "id" => $item["id"],
                "lang" => LANGUAGE_ID,
            ]);
            echo "ID {$item['id']}: описание обновлено.\n";
            $report[$idx]["status"] = "applied";
            $changed = true;
        } catch (\Throwable $e) {
            echo "ID {$item['id']}: ОШИБКА при обновлении — {$e->getMessage()}\n";
        }
    }

    if ($changed) {
        file_put_contents($reportFile, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    echo "\nГотово. Если у магазина настроено кэширование (файловое/Redis/Memcached) —\n";
    echo "очисти кэш каталога через админку (Система -> Обслуживание -> Очистить кэш).\n";
}

// ==================== ЗАПУСК ====================

$mode = $argv[1] ?? "";
$sectionArg = isset($argv[2]) ? (int)$argv[2] : $DEFAULT_SECTION_ID;
$limitArg = isset($argv[3]) ? (int)$argv[3] : 0;
$extraInstructions = "";

switch ($mode) {
    case "review":
        runReview($sectionArg, $extraInstructions, $limitArg);
        break;

    case "apply":
        runApply($sectionArg);
        break;

    case "preview":
        $previewProductId = $limitArg ?: null;
        if (!$previewProductId) {
            echo "Использование: php ai_generate_descriptions_oc.php preview <CATEGORY_ID> <PRODUCT_ID>\n";
            exit(1);
        }
        $reportFile = getReportFile($sectionArg);
        if (!file_exists($reportFile)) {
            echo "Файл отчёта не найден: {$reportFile}\n";
            exit(1);
        }
        $report = json_decode(file_get_contents($reportFile), true);
        $found = null;
        foreach ($report as $item) {
            if ((string)$item["id"] === (string)$previewProductId) {
                $found = $item;
                break;
            }
        }
        if (!$found) {
            echo "Товар {$previewProductId} не найден в отчёте {$reportFile}\n";
            exit(1);
        }
        $previewFile = __DIR__ . "/preview_{$previewProductId}.html";
        $previewHtml = "<!DOCTYPE html><html><head><meta charset='utf-8'>"
            . "<title>Превью: {$found['name']}</title>"
            . "<style>body{font-family:sans-serif;max-width:800px;margin:40px auto;padding:0 20px;}</style>"
            . "</head><body><h1>{$found['name']}</h1>"
            . ($found["detail_text"] ?? "(пусто)")
            . "</body></html>";
        file_put_contents($previewFile, $previewHtml);
        echo "Сохранено: {$previewFile}\n";
        break;

    case "dumpprompt":
        $productId = $limitArg ?: null;
        if (!$productId) {
            echo "Использование: php ai_generate_descriptions_oc.php dumpprompt <CATEGORY_ID> <PRODUCT_ID>\n";
            exit(1);
        }
        $product = getProductContext($productId);
        if (!$product) {
            echo "Товар {$productId} не найден.\n";
            exit(1);
        }
        $prompt = buildPrompt($product, $sectionArg, $extraInstructions, $GLOBALS['SECTION_PROMPT_FILES']);
        echo "Размер промпта: " . mb_strlen($prompt) . " символов\n";
        echo "--- ПРОМПТ ---\n{$prompt}\n";
        break;

    case "count":
        $elementIds = getElementIdsBySection($sectionArg);
        $totalInSection = count($elementIds);
        $reportFile = getReportFile($sectionArg);
        $generated = 0;
        $applied = 0;
        if (file_exists($reportFile)) {
            $report = json_decode(file_get_contents($reportFile), true);
            if (is_array($report)) {
                foreach ($report as $item) {
                    $status = $item["status"] ?? "";
                    if (in_array($status, ["approved", "applied"], true)) {
                        $generated++;
                    }
                    if ($status === "applied") {
                        $applied++;
                    }
                }
            }
        }
        echo "\nКатегория {$sectionArg}: сгенерировано и одобрено — {$generated} товаров из {$totalInSection}\n";
        echo "Из них применено — {$applied} из {$totalInSection}\n";
        break;

    case "listreadyids":
        // НЕ список товаров — список КАТЕГОРИЙ, для которых на сервере уже
        // есть отчёт с хотя бы одним готовым текстом товара (approved —
        // сгенерирован, ещё не записан в OpenCart, ИЛИ applied — уже
        // записан). Сканируем ВСЕ файлы ai_desc_report_section_<ID>.json в
        // папке скрипта (а не только текущий "ID раздела" из формы), ID
        // категории берём из имени файла, название — из БД.
        // Вывод: "ID - Название категории", отсортировано по возрастанию ID.
        $reportFiles = glob(__DIR__ . "/ai_desc_report_section_*.json");
        $readySections = []; // categoryId => ["name" => ..., "ready" => N, "applied" => N]
        foreach ($reportFiles as $reportFilePath) {
            if (!preg_match('/ai_desc_report_section_(\d+)\.json$/', $reportFilePath, $m)) {
                continue;
            }
            $reportCategoryId = (int)$m[1];
            $reportData = json_decode(file_get_contents($reportFilePath), true);
            if (!is_array($reportData)) {
                continue;
            }
            $readyCount = 0;
            $appliedCount = 0;
            foreach ($reportData as $reportItem) {
                $status = $reportItem["status"] ?? "";
                if (in_array($status, ["approved", "applied"], true)) {
                    $readyCount++;
                }
                if ($status === "applied") {
                    $appliedCount++;
                }
            }
            if ($readyCount === 0) {
                continue;
            }
            $categoryName = "";
            try {
                $catStmt = db()->prepare(
                    "SELECT name FROM " . TBL_PREFIX . "category_description
                     WHERE category_id = :id AND language_id = :lang LIMIT 1"
                );
                $catStmt->execute(["id" => $reportCategoryId, "lang" => LANGUAGE_ID]);
                $catRow = $catStmt->fetch(PDO::FETCH_ASSOC);
                $categoryName = $catRow["name"] ?? "";
            } catch (\Throwable $e) {
                // категория могла быть удалена — просто покажем ID без названия
            }
            $readySections[$reportCategoryId] = [
                "name" => $categoryName,
                "ready" => $readyCount,
                "applied" => $appliedCount,
            ];
        }
        ksort($readySections, SORT_NUMERIC);
        echo "Категорий с готовыми текстами товаров — " . count($readySections) . "\n";
        foreach ($readySections as $readyCategoryId => $readyInfo) {
            $countsStr = "готово: {$readyInfo['ready']}, на сайте: {$readyInfo['applied']}";
            echo $readyInfo["name"] !== ""
                ? "{$readyCategoryId} - {$readyInfo['name']} ({$countsStr})\n"
                : "{$readyCategoryId} ({$countsStr})\n";
        }
        break;

    case "listreadyocfilterids":
        // То же самое, но для SEO-страниц OCFilter. Партия (batch) не всегда
        // равна ID категории 1:1, поэтому сканируем ВСЕ файлы
        // ai_ocfilter_report_batch_<BATCH_ID>.json и для каждой партии с
        // готовыми страницами показываем сам ID партии, название(я)
        // категори(й), реально встретившиеся в готовых записях этой партии,
        // и сколько страниц готово. Вывод отсортирован по возрастанию ID партии.
        $ocReportFiles = glob(__DIR__ . "/ai_ocfilter_report_batch_*.json");
        $readyBatches = []; // batchId => ["names" => [...], "count" => N]
        foreach ($ocReportFiles as $ocReportFilePath) {
            if (!preg_match('/ai_ocfilter_report_batch_(.+)\.json$/', $ocReportFilePath, $m)) {
                continue;
            }
            $reportBatchId = $m[1];
            $ocReportData = json_decode(file_get_contents($ocReportFilePath), true);
            if (!is_array($ocReportData)) {
                continue;
            }
            $readyCount = 0;
            $appliedCount = 0;
            $categoryNames = [];
            foreach ($ocReportData as $ocReportItem) {
                $status = $ocReportItem["status"] ?? "";
                if (!in_array($status, ["generated", "applied"], true)) {
                    continue;
                }
                $readyCount++;
                if ($status === "applied") {
                    $appliedCount++;
                }
                $catName = $ocReportItem["category_name"] ?? "";
                if ($catName !== "" && !in_array($catName, $categoryNames, true)) {
                    $categoryNames[] = $catName;
                }
            }
            if ($readyCount === 0) {
                continue;
            }
            $readyBatches[$reportBatchId] = ["names" => $categoryNames, "count" => $readyCount, "applied" => $appliedCount];
        }
        uksort($readyBatches, function ($a, $b) {
            // Партии обычно числовые (= ID категории), но формат не гарантирован -
            // сортируем как числа, если оба числовые, иначе как строки.
            if (ctype_digit($a) && ctype_digit($b)) {
                return (int)$a <=> (int)$b;
            }
            return strcmp($a, $b);
        });
        echo "Партий с готовыми SEO-страницами — " . count($readyBatches) . "\n";
        foreach ($readyBatches as $readyBatchId => $readyBatchInfo) {
            $namesStr = implode(", ", $readyBatchInfo["names"]);
            $countsStr = "готово: {$readyBatchInfo['count']}, на сайте: {$readyBatchInfo['applied']}";
            echo $namesStr !== ""
                ? "{$readyBatchId} - {$namesStr} ({$countsStr})\n"
                : "{$readyBatchId} ({$countsStr})\n";
        }
        break;

    case "listapplied":
        $elementIds = getElementIdsBySection($sectionArg);
        $totalInSection = count($elementIds);
        $reportFile = getReportFile($sectionArg);
        if (!file_exists($reportFile)) {
            echo "Файл отчёта не найден: {$reportFile}\n";
            exit(1);
        }
        $report = json_decode(file_get_contents($reportFile), true);
        $applied = array_filter($report, fn($item) => ($item["status"] ?? "") === "applied");
        echo "\n";
        foreach ($applied as $item) {
            echo "{$item['id']}: {$item['name']}\n";
        }
        echo "\nКатегория {$sectionArg}: применено — " . count($applied) . " товаров из {$totalInSection}.\n";
        break;

    // ---------- OCFilter: SEO-страницы фильтров ----------

    case "genocfilter":
        // $sectionArg тут используется как ID партии (batch) — просто метка
        // для имени файлов отчёта/входного списка, не ID категории напрямую
        // (категория для каждой страницы берётся из самой строки списка).
        runGenOcFilter($sectionArg, $limitArg);
        break;

    case "applyocfilter":
        runApplyOcFilter($sectionArg);
        break;

    case "countocfilter":
        $ocReportFile = getOcFilterReportFile($sectionArg);
        $ocGenerated = 0;
        $ocApplied = 0;
        $ocErrors = 0;
        if (file_exists($ocReportFile)) {
            $ocReport = json_decode(file_get_contents($ocReportFile), true);
            if (is_array($ocReport)) {
                foreach ($ocReport as $item) {
                    $status = $item["status"] ?? "";
                    if (in_array($status, ["generated", "applied"], true)) {
                        $ocGenerated++;
                    }
                    if ($status === "applied") {
                        $ocApplied++;
                    }
                    if ($status === "error") {
                        $ocErrors++;
                    }
                }
            }
        } else {
            echo "Файл отчёта не найден: {$ocReportFile}\n";
            exit(0);
        }
        echo "\nПартия {$sectionArg}: сгенерировано — {$ocGenerated}, применено — {$ocApplied}, ошибок — {$ocErrors}\n";
        break;

    case "listappliedocfilter":
        $ocReportFile = getOcFilterReportFile($sectionArg);
        if (!file_exists($ocReportFile)) {
            echo "Файл отчёта не найден: {$ocReportFile}\n";
            exit(1);
        }
        $ocReport = json_decode(file_get_contents($ocReportFile), true);
        $ocApplied = array_filter($ocReport, fn($item) => ($item["status"] ?? "") === "applied");
        echo "\n";
        foreach ($ocApplied as $item) {
            echo "{$item['keyword']}: {$item['name']} — " . getOcFilterPageUrl($item['keyword']) . "\n";
        }
        echo "\nПартия {$sectionArg}: применено — " . count($ocApplied) . " страниц.\n";
        break;

    case "indexnowocfilter":
        // Отдельная РУЧНАЯ команда — не вызывается автоматически из
        // applyocfilter, т.к. пока идёт тестирование модуля, страницы не
        // должны сами собой улетать в IndexNow при каждом apply.
        ensureIndexNowKeyFile();
        $ocReportFile = getOcFilterReportFile($sectionArg);
        if (!file_exists($ocReportFile)) {
            echo "Файл отчёта не найден: {$ocReportFile}\n";
            exit(1);
        }
        $ocReport = json_decode(file_get_contents($ocReportFile), true);
        $ocUrls = [];
        foreach ($ocReport as $item) {
            if (($item["status"] ?? "") === "applied") {
                $ocUrls[] = getOcFilterPageUrl($item["keyword"]);
            }
        }
        if (empty($ocUrls)) {
            echo "В партии {$sectionArg} нет применённых SEO-страниц.\n";
            exit(0);
        }
        echo "Отправляю " . count($ocUrls) . " URL в IndexNow...\n";
        $ocResult = submitIndexNow($ocUrls);
        if (isset($ocResult["error"])) {
            echo "ОШИБКА: {$ocResult['error']}\n";
            exit(1);
        }
        foreach ($ocResult["results"] as $batchResult) {
            echo "Пакет из {$batchResult['batch_size']} URL — HTTP {$batchResult['http_code']}\n";
        }
        break;

    case "whichprompt":
        // GUI уже вызывал эту команду для подсветки текущего привязанного
        // файла при открытии редактора, но самой команды в скрипте не было
        // (пустой вывод молча игнорировался GUI) — добавлена сейчас.
        if (!$sectionArg) {
            echo "Использование: php ai_generate_descriptions_oc.php whichprompt <CATEGORY_ID>\n";
            exit(1);
        }
        echo basename(getPromptFilePath($sectionArg, $GLOBALS['SECTION_PROMPT_FILES'])) . "\n";
        break;

    case "bindprompt":
        $filenameToBind = $argv[3] ?? null;
        if (!$sectionArg || !$filenameToBind) {
            echo "Использование: php ai_generate_descriptions_oc.php bindprompt <CATEGORY_ID> <FILENAME>\n";
            exit(1);
        }
        $promptPathToBind = PROMPTS_DIR . "/" . $filenameToBind;
        if (!file_exists($promptPathToBind)) {
            echo "Файл prompts/{$filenameToBind} не найден.\n";
            exit(1);
        }
        $mapping = loadSectionPromptFiles();
        $mapping[(string)$sectionArg] = $filenameToBind;
        try {
            saveSectionPromptFiles($mapping);
            echo "Привязка обновлена: категория {$sectionArg} -> prompts/{$filenameToBind}\n";
        } catch (\Throwable $e) {
            echo "ОШИБКА: {$e->getMessage()}\n";
            exit(1);
        }
        break;

    case "unbindprompt":
        if (!$sectionArg) {
            echo "Использование: php ai_generate_descriptions_oc.php unbindprompt <CATEGORY_ID>\n";
            exit(1);
        }
        $mapping = loadSectionPromptFiles();
        if (isset($mapping[(string)$sectionArg])) {
            unset($mapping[(string)$sectionArg]);
            try {
                saveSectionPromptFiles($mapping);
                echo "Привязка для категории {$sectionArg} удалена.\n";
            } catch (\Throwable $e) {
                echo "ОШИБКА: {$e->getMessage()}\n";
                exit(1);
            }
        } else {
            echo "У категории {$sectionArg} и так не было отдельной привязки.\n";
        }
        break;

    case "listmapping":
        $mapping = loadSectionPromptFiles();
        if (empty($mapping)) {
            echo "Привязок нет — все категории используют default.txt.\n";
            break;
        }
        foreach ($mapping as $secId => $file) {
            echo "Категория {$secId} -> prompts/{$file}\n";
        }
        break;

    case "listprompts":
        if (!is_dir(PROMPTS_DIR)) {
            echo "Папка промптов не найдена: " . PROMPTS_DIR . "\n";
            exit(1);
        }
        $files = glob(PROMPTS_DIR . "/*.txt");
        foreach ($files as $f) {
            echo basename($f) . "\n";
        }
        break;

    // ---------- Промпты SEO-страниц OCFilter (отдельная папка/привязки) ----------
    // Тот же принцип, что и у обычных товарных промптов выше (listprompts/
    // whichprompt/bindprompt/unbindprompt), но папка — OCFILTER_PROMPTS_DIR,
    // а ключи привязки в общем файле section_mapping.json — с префиксом
    // "ocfilter_", чтобы не пересекаться с ID категорий товаров.

    case "listocfilterprompts":
        ensureOcFilterDefaultPrompt();
        $ocFiles = glob(OCFILTER_PROMPTS_DIR . "/*.txt");
        foreach ($ocFiles as $f) {
            echo basename($f) . "\n";
        }
        break;

    case "whichocfilterprompt":
        if (!$sectionArg) {
            echo "Использование: php ai_generate_descriptions_oc.php whichocfilterprompt <BATCH_ID>\n";
            exit(1);
        }
        ensureOcFilterDefaultPrompt();
        echo basename(getOcFilterPromptFilePath($sectionArg, $GLOBALS['SECTION_PROMPT_FILES'])) . "\n";
        break;

    case "bindocfilterprompt":
        $ocFilenameToBind = $argv[3] ?? null;
        if (!$sectionArg || !$ocFilenameToBind) {
            echo "Использование: php ai_generate_descriptions_oc.php bindocfilterprompt <BATCH_ID> <FILENAME>\n";
            exit(1);
        }
        $ocPromptPathToBind = OCFILTER_PROMPTS_DIR . "/" . $ocFilenameToBind;
        if (!file_exists($ocPromptPathToBind)) {
            echo "Файл prompts/ocfilter/{$ocFilenameToBind} не найден.\n";
            exit(1);
        }
        $ocMapping = loadSectionPromptFiles();
        $ocMapping["ocfilter_" . $sectionArg] = $ocFilenameToBind;
        try {
            saveSectionPromptFiles($ocMapping);
            echo "Привязка обновлена: партия {$sectionArg} -> prompts/ocfilter/{$ocFilenameToBind}\n";
        } catch (\Throwable $e) {
            echo "ОШИБКА: {$e->getMessage()}\n";
            exit(1);
        }
        break;

    case "unbindocfilterprompt":
        if (!$sectionArg) {
            echo "Использование: php ai_generate_descriptions_oc.php unbindocfilterprompt <BATCH_ID>\n";
            exit(1);
        }
        $ocMapping = loadSectionPromptFiles();
        $ocKey = "ocfilter_" . $sectionArg;
        if (isset($ocMapping[$ocKey])) {
            unset($ocMapping[$ocKey]);
            try {
                saveSectionPromptFiles($ocMapping);
                echo "Привязка для партии {$sectionArg} удалена (снова используется default.txt).\n";
            } catch (\Throwable $e) {
                echo "ОШИБКА: {$e->getMessage()}\n";
                exit(1);
            }
        } else {
            echo "У партии {$sectionArg} и так не было отдельной привязки.\n";
        }
        break;

    case "showdesc":
        // Быстрый просмотр текущего description товара прямо из консоли,
        // без похода в phpMyAdmin. Использование:
        //   php ai_generate_descriptions_oc.php showdesc 0 <PRODUCT_ID>
        // (первый аргумент — категория — здесь не используется, но нужен
        // для единообразия с остальными командами; можно передать 0)
        $productIdToShow = $limitArg ?: null;
        if (!$productIdToShow) {
            echo "Использование: php ai_generate_descriptions_oc.php showdesc 0 <PRODUCT_ID>\n";
            exit(1);
        }
        $sql = "SELECT product_id, name, description
                FROM " . TBL_PREFIX . "product_description
                WHERE product_id = :id AND language_id = :lang";
        $stmt = db()->prepare($sql);
        $stmt->execute(["id" => $productIdToShow, "lang" => LANGUAGE_ID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo "Товар {$productIdToShow} не найден.\n";
            exit(1);
        }
        echo "ID: {$row['product_id']}\n";
        echo "Название: {$row['name']}\n";
        echo "Длина description: " . mb_strlen($row['description']) . " символов\n";
        echo "--- DESCRIPTION ---\n";
        echo $row['description'] . "\n";
        echo "--- КОНЕЦ ---\n";
        break;

    case "listmissingfooter":
        // При миллионах товаров нельзя вытаскивать и печатать каждую строку —
        // считаем количество через COUNT(*) и показываем небольшую выборку
        // названий (LIMIT 10) просто для проверки, что фильтр отбирает то,
        // что нужно.
        $countSql = "SELECT COUNT(*) AS cnt
                     FROM " . TBL_PREFIX . "product_description
                     WHERE language_id = :lang
                       AND description != ''
                       AND description NOT LIKE '%EMAIL%'";
        $stmt = db()->prepare($countSql);
        $stmt->execute(["lang" => LANGUAGE_ID]);
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)["cnt"];

        echo "Товаров с непустым description БЕЗ коммерческого блока: {$total}\n\n";

        if ($total > 0) {
            echo "Пример первых 10 (для проверки, что фильтр верный):\n";
            $sampleSql = "SELECT product_id, name
                          FROM " . TBL_PREFIX . "product_description
                          WHERE language_id = :lang
                            AND description != ''
                            AND description NOT LIKE '%EMAIL%'
                          LIMIT 10";
            $sampleStmt = db()->prepare($sampleSql);
            $sampleStmt->execute(["lang" => LANGUAGE_ID]);
            foreach ($sampleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                echo "{$row['product_id']}: {$row['name']}\n";
            }
        }
        break;

    case "fixphonefooter":
        // Разовая миграция: у части товаров (уже обработанных addfooterall
        // ДО того, как обнаружили путаницу %TELEPHONE%/%PHONE%) сейчас лежит
        // старая версия блока с пустым телефоном. Точечно заменяем именно
        // этот старый текст на новый, не трогая остальные товары.
        $oldFooter = <<<'OLDFOOTER'
<p>Налаживание надежных партнерских отношений, развитие логистической цепи, улучшение технологических процессов производства на промышленных объектах компании – это то, чему постоянно уделяется большое внимание.</p>

<p>ООО Ферус, г. %CITY%, предлагает Вам приобрести по выгодной цене. Реализация продукции оптом и в розницу, с складов компании. Условия доставки и другую информацию, касательно покупки Вы можете уточнить у менеджеров компании по телефону или электронной почте:</p>

<p><strong>%TELEPHONE%</strong></p>

<p><strong>%EMAIL%</strong></p>
OLDFOOTER;

        $selectSql = "SELECT product_id, description
                      FROM " . TBL_PREFIX . "product_description
                      WHERE language_id = :lang
                        AND description LIKE '%<p><strong>%TELEPHONE%%'";
        $stmt = db()->prepare($selectSql);
        $stmt->execute(["lang" => LANGUAGE_ID]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo "Не найдено товаров со старой версией блока. Нечего исправлять.\n";
            break;
        }

        echo "Найдено товаров со старым блоком: " . count($rows) . "\n";

        $updateSql = "UPDATE " . TBL_PREFIX . "product_description
                      SET description = :new_desc
                      WHERE product_id = :id AND language_id = :lang";
        $updateStmt = db()->prepare($updateSql);

        $fixed = 0;
        foreach ($rows as $row) {
            if (strpos($row["description"], $oldFooter) === false) {
                continue; // на всякий случай, вдруг LIKE зацепил что-то другое
            }
            $newDescription = str_replace($oldFooter, COMMERCIAL_FOOTER_HTML, $row["description"]);
            $updateStmt->execute([
                "new_desc" => $newDescription,
                "id" => $row["product_id"],
                "lang" => LANGUAGE_ID,
            ]);
            $fixed++;
        }

        echo "Исправлено товаров: {$fixed}\n";
        break;

    case "addfooterall":
        // Дописывает коммерческий блок ВСЕМ товарам с непустым description,
        // у которых его ещё нет. Рассчитано на миллионы записей:
        // - обрабатывает пачками (по умолчанию 2000 за раз), а не по одной строке;
        // - один UPDATE на пачку (через IN(...)), а не отдельный запрос на каждый товар;
        // - идемпотентно и безопасно прерывать/перезапускать: уже обработанные
        //   строки (содержащие %EMAIL% — маркер того, что контактный блок
        //   уже есть в каком-либо виде, старом или новом) перестают попадать в выборку,
        //   так что повторный запуск просто продолжит с того места, где остановились.
        $batchSize = 2000;
        $totalUpdated = 0;
        $startTime = time();

        while (true) {
            $selectSql = "SELECT product_id
                          FROM " . TBL_PREFIX . "product_description
                          WHERE language_id = :lang
                            AND description != ''
                            AND description NOT LIKE '%EMAIL%'
                          LIMIT :batch";
            $selectStmt = db()->prepare($selectSql);
            $selectStmt->bindValue(":lang", LANGUAGE_ID, PDO::PARAM_INT);
            $selectStmt->bindValue(":batch", $batchSize, PDO::PARAM_INT);
            $selectStmt->execute();
            $ids = $selectStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($ids)) {
                break;
            }

            $placeholders = implode(",", array_fill(0, count($ids), "?"));
            $updateSql = "UPDATE " . TBL_PREFIX . "product_description
                          SET description = CONCAT(description, ?, ?)
                          WHERE language_id = ? AND product_id IN ({$placeholders})";
            $updateStmt = db()->prepare($updateSql);
            $params = array_merge(["\n\n", COMMERCIAL_FOOTER_HTML, LANGUAGE_ID], $ids);
            $updateStmt->execute($params);

            $totalUpdated += count($ids);
            $elapsed = time() - $startTime;
            echo "Обработано: {$totalUpdated} (за {$elapsed} сек)\n";

            // Небольшая пауза между пачками, чтобы не держать таблицу под
            // непрерывной нагрузкой на живом продакшн-сайте.
            usleep(200000);
        }

        echo "\nГотово. Всего обновлено товаров: {$totalUpdated}\n";
        break;

        echo "\nГотово. Блок добавлен к {$count} товарам.\n";
        break;

    case "indexnow":
        $keyFilePath = ensureIndexNowKeyFile();
        echo "Файл-подтверждение IndexNow: {$keyFilePath}\n";

        $reportFile = getReportFile($sectionArg);
        if (!file_exists($reportFile)) {
            echo "Файл отчёта не найден: {$reportFile}\n";
            exit(1);
        }
        $report = json_decode(file_get_contents($reportFile), true);
        $appliedIds = [];
        foreach ($report as $item) {
            if (($item["status"] ?? "") === "applied") {
                $appliedIds[] = $item["id"];
            }
        }

        if (empty($appliedIds)) {
            echo "В категории {$sectionArg} нет товаров со статусом 'applied'.\n";
            exit(0);
        }

        echo "Собираю URL для " . count($appliedIds) . " товаров...\n";
        $urls = [];
        foreach ($appliedIds as $id) {
            $url = getProductUrl($id);
            if ($url) {
                $urls[] = $url;
            } else {
                echo "  ID {$id}: не удалось получить URL, пропуск.\n";
            }
        }

        echo "Отправляю " . count($urls) . " URL в IndexNow...\n";
        $result = submitIndexNow($urls);

        if (isset($result["error"])) {
            echo "ОШИБКА: {$result['error']}\n";
            exit(1);
        }

        foreach ($result["results"] as $batchResult) {
            echo "Пакет из {$batchResult['batch_size']} URL — HTTP {$batchResult['http_code']}\n";
        }
        break;

    default:
        echo "Использование:\n";
        echo "  php ai_generate_descriptions_oc.php review <CATEGORY_ID> [LIMIT]\n";
        echo "  php ai_generate_descriptions_oc.php apply <CATEGORY_ID>\n";
        echo "  php ai_generate_descriptions_oc.php preview <CATEGORY_ID> <PRODUCT_ID>\n";
        echo "  php ai_generate_descriptions_oc.php dumpprompt <CATEGORY_ID> <PRODUCT_ID>\n";
        echo "  php ai_generate_descriptions_oc.php count <CATEGORY_ID>\n";
        echo "  php ai_generate_descriptions_oc.php listapplied <CATEGORY_ID>\n";
        echo "  php ai_generate_descriptions_oc.php listprompts\n";
        echo "  php ai_generate_descriptions_oc.php bindprompt <CATEGORY_ID> <FILENAME>\n";
        echo "  php ai_generate_descriptions_oc.php unbindprompt <CATEGORY_ID>\n";
        echo "  php ai_generate_descriptions_oc.php listmapping\n";
        echo "  php ai_generate_descriptions_oc.php listmissingfooter — показать товары без коммерческого блока в description\n";
        echo "  php ai_generate_descriptions_oc.php addfooterall     — дописать блок ВСЕМ товарам с описанием, у кого его нет\n";
        echo "  php ai_generate_descriptions_oc.php indexnow <CATEGORY_ID>\n";
        echo "\n  OCFilter (SEO-страницы фильтров):\n";
        echo "  php ai_generate_descriptions_oc.php genocfilter <BATCH_ID> [LIMIT]\n";
        echo "  php ai_generate_descriptions_oc.php applyocfilter <BATCH_ID>\n";
        echo "  php ai_generate_descriptions_oc.php countocfilter <BATCH_ID>\n";
        echo "  php ai_generate_descriptions_oc.php listappliedocfilter <BATCH_ID>\n";
        echo "  php ai_generate_descriptions_oc.php indexnowocfilter <BATCH_ID>\n";
        echo "  php ai_generate_descriptions_oc.php listocfilterprompts             — список файлов промптов OCFilter\n";
        echo "  php ai_generate_descriptions_oc.php whichocfilterprompt <BATCH_ID>  — какой файл привязан к партии\n";
        echo "  php ai_generate_descriptions_oc.php bindocfilterprompt <BATCH_ID> <FILE> — привязать партию к файлу\n";
        echo "  php ai_generate_descriptions_oc.php unbindocfilterprompt <BATCH_ID> — убрать привязку (вернуть default.txt)\n";
}