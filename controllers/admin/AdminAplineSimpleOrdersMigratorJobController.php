<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Jobs controller: lists import jobs, creates a new job from an uploaded or
 * FTP-placed SQL dump (with upload hardening), shows a per-job detail screen,
 * and deletes a job together with its sandbox tables. Parsing / mapping /
 * import behaviour live in the classes/ folder.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AplineSimpleOrdersMigratorJob.php';
require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomDumpParser.php';
require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomValidator.php';
require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomMapping.php';
require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomProductMapper.php';
require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomRefMapper.php';
require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomImporter.php';
require_once _PS_MODULE_DIR_ . 'apline_simple_orders_migrator/classes/AsomEmailer.php';

class AdminAplineSimpleOrdersMigratorJobController extends ModuleAdminController
{
    const ALLOWED_EXT = ['sql'];
    const MAX_NAME = 255;
    /** Basic SQL signatures any real dump must contain in its first bytes. */
    const SQL_SIGNATURES = ['CREATE TABLE', 'INSERT INTO'];

    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'asom_job';
        $this->className = 'AplineSimpleOrdersMigratorJob';
        $this->identifier = 'id_asom_job';
        $this->lang = false;
        $this->allow_export = false;
        $this->list_no_link = true;

        parent::__construct();

        $this->fields_list = [
            'id_asom_job' => [
                'title' => $this->trans('ID', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'name' => [
                'title' => $this->trans('Nazwa zadania', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            ],
            'dump_filename' => [
                'title' => $this->trans('Plik zrzutu', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            ],
            'dump_size_bytes' => [
                'title' => $this->trans('Rozmiar', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'align' => 'right',
                'callback' => 'printSize',
                'search' => false,
            ],
            'source_ps_version' => [
                'title' => $this->trans('PS (źródło)', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'align' => 'center',
                'callback' => 'printVersionBadge',
                'search' => false,
            ],
            'status' => [
                'title' => $this->trans('Status', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'align' => 'center',
                'callback' => 'printStatusBadge',
                'search' => false,
            ],
            'counts_imported' => [
                'title' => $this->trans('Zaimportowano', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'align' => 'center',
                'callback' => 'printCounts',
                'search' => false,
                'orderby' => false,
            ],
            'date_add' => [
                'title' => $this->trans('Utworzono', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'align' => 'center',
                'type' => 'datetime',
            ],
            'finished_at' => [
                'title' => $this->trans('Zakończono', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'align' => 'center',
                'type' => 'datetime',
                'search' => false,
            ],
        ];

        $this->_defaultOrderBy = 'id_asom_job';
        $this->_defaultOrderWay = 'DESC';

        $this->addRowAction('view');
        $this->addRowAction('delete');
        $this->bulk_actions = [
            'delete' => [
                'text' => $this->trans('Usuń zaznaczone', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'confirm' => $this->trans('Usunąć zaznaczone zadania razem z ich tabelami-piaskownicami?', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            ],
        ];
    }

    /**
     * Module configuration URL (so the user can get back from the jobs list).
     *
     * @return string
     */
    private function getConfigUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => 'apline_simple_orders_migrator',
            'module_name' => 'apline_simple_orders_migrator',
        ]);
    }

    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        $this->page_header_toolbar_btn['back_to_config'] = [
            'href' => $this->getConfigUrl(),
            'desc' => $this->trans('Wróć do konfiguracji', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'icon' => 'process-icon-back',
        ];
    }

    public function initToolbar()
    {
        parent::initToolbar();

        // Na liście zadań akcję „Nowe zadanie importu” pokazuje duży przycisk
        // z renderList(); domyślny mały przycisk „+” byłby jego dubletem.
        unset($this->toolbar_btn['new']);
    }

    /**
     * Polska etykieta statusu zadania (klucz techniczny zostaje w bazie i w CSS).
     *
     * @param string $status
     *
     * @return string
     */
    private function statusLabel($status)
    {
        $labels = [
            'uploaded' => $this->trans('Wgrany', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'parsing' => $this->trans('Analiza w toku', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'staged' => $this->trans('Przeanalizowany', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'mapping' => $this->trans('Mapowanie', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'importing' => $this->trans('Import w toku', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'completed' => $this->trans('Zakończony', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'failed' => $this->trans('Błąd', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'cleaned' => $this->trans('Zarchiwizowany', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
        ];

        return isset($labels[$status]) ? $labels[$status] : (string) $status;
    }

    /**
     * Polska etykieta rodzaju danych (klucze liczników i typów encji z importu).
     *
     * @param string $key
     * @param bool   $singular liczba pojedyncza (wpis logu dotyczy jednego rekordu)
     *
     * @return string
     */
    private function entityLabel($key, $singular = false)
    {
        if ($singular) {
            $one = [
                'customer' => $this->trans('Klient', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'address' => $this->trans('Adres', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'order' => $this->trans('Zamówienie', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'order_detail' => $this->trans('Pozycja zamówienia', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'order_history' => $this->trans('Wpis historii zamówienia', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'message' => $this->trans('Wiadomość do zamówienia', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'product' => $this->trans('Produkt', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            ];
            if (isset($one[$key])) {
                return $one[$key];
            }
        }

        $labels = [
            'customer' => $this->trans('Klienci', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'customers' => $this->trans('Klienci', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'address' => $this->trans('Adresy', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'addresses' => $this->trans('Adresy', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'order' => $this->trans('Zamówienia', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'orders' => $this->trans('Zamówienia', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'order_detail' => $this->trans('Pozycje zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'order_details' => $this->trans('Pozycje zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'order_history' => $this->trans('Historia zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'message' => $this->trans('Wiadomości do zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'messages' => $this->trans('Wiadomości do zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'product' => $this->trans('Produkty', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'products' => $this->trans('Produkty', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
        ];

        return isset($labels[$key]) ? $labels[$key] : (string) $key;
    }

    /**
     * Fazy zapisywane w logu: identyfikator techniczny => polska etykieta.
     *
     * @return array<string,string>
     */
    private function phaseLabels()
    {
        return [
            'parse_dump' => $this->trans('Analiza zrzutu', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'import_customers' => $this->trans('Import klientów', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'import_addresses' => $this->trans('Import adresów', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'import_orders' => $this->trans('Import zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'import_order_details' => $this->trans('Import pozycji zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'import_order_history' => $this->trans('Import historii zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'import_messages' => $this->trans('Import wiadomości', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'finish' => $this->trans('Zakończenie importu', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'send_emails' => $this->trans('Wysyłka e-maili', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
        ];
    }

    public function initContent()
    {
        // Opportunistic log retention: delete log rows older than the
        // configured window. Cheap, runs once per list view, never fatal.
        $this->purgeOldLogs();
        parent::initContent();
    }

    /**
     * Delete asom_log rows older than ASOM_KEEP_LOG_DAYS days.
     */
    private function purgeOldLogs()
    {
        try {
            $days = (int) Configuration::get(apline_simple_orders_migrator::KEEP_LOG_DAYS_KEY);
            if ($days < 1) {
                return;
            }
            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'asom_log`
                 WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
            );
        } catch (\Throwable $e) {
            // retention is best-effort
        }
    }

    public function renderList()
    {
        // Crash-safety: if the job table is missing (e.g. renamed during
        // maintenance) show an empty, explained list instead of a 500.
        try {
            $list = parent::renderList();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_orders_migrator renderList: ' . $e->getMessage(), 2);
            $list = '<div class="alert alert-warning">'
                . $this->trans('Tabela zadań importu jest niedostępna. Jeśli moduł został właśnie zainstalowany lub odinstalowany, zainstaluj go ponownie.', [], 'Modules.Aplinesimpleordersmigrator.Admin')
                . '</div>';
        }

        $newUrl = self::$currentIndex . '&add' . $this->table . '&token=' . $this->token;
        $back = '<div style="margin:10px 0;">'
            . '<a class="btn btn-primary btn-lg apline-btn-duzy" href="' . htmlspecialchars($newUrl, ENT_QUOTES) . '">'
            . '<i class="icon-plus"></i> ' . $this->trans('Nowe zadanie importu', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</a>'
            . '<a class="btn btn-default" href="'
            . htmlspecialchars($this->getConfigUrl(), ENT_QUOTES)
            . '"><i class="icon-chevron-left"></i> '
            . $this->trans('Wróć do konfiguracji', [], 'Modules.Aplinesimpleordersmigrator.Admin')
            . '</a></div>';

        $credit = ($this->module && method_exists($this->module, 'renderAplineFooter'))
            ? $this->module->renderAplineFooter()
            : '';

        return $back . $list . $credit;
    }

    /**
     * Human-readable file size for the list.
     *
     * @param int $bytes
     *
     * @return string
     */
    public function printSize($bytes)
    {
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return round($bytes, ($i > 0 ? 1 : 0)) . ' ' . $units[$i];
    }

    /**
     * Green badge for the supported 1.7.x line, red otherwise.
     *
     * @param string $version
     *
     * @return string
     */
    public function printVersionBadge($version)
    {
        $version = (string) $version;
        if ($version === '') {
            return '<span class="text-muted">&mdash;</span>';
        }
        $ok = (strpos($version, '1.7.') === 0);
        $class = $ok ? 'asom-version-ok' : 'asom-version-warn';

        return '<span class="' . $class . '">' . htmlspecialchars($version, ENT_QUOTES) . '</span>';
    }

    /**
     * Color-coded status badge.
     *
     * @param string $status
     *
     * @return string
     */
    public function printStatusBadge($status)
    {
        $status = (string) $status;
        $safe = preg_replace('/[^a-z]/', '', $status);

        return '<span class="asom-status asom-status-' . $safe . '">' . htmlspecialchars($this->statusLabel($status), ENT_QUOTES) . '</span>';
    }

    /**
     * Compact "X cust / Y ord" summary from the counts_imported JSON.
     *
     * @param string $json
     *
     * @return string
     */
    public function printCounts($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data) || !$data) {
            return '<span class="text-muted">&mdash;</span>';
        }
        $cust = isset($data['customers']) ? (int) $data['customers'] : 0;
        $ord = isset($data['orders']) ? (int) $data['orders'] : 0;

        return htmlspecialchars($this->trans('klienci', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ': ' . $cust . ' / ' . $this->trans('zamówienia', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ': ' . $ord, ENT_QUOTES);
    }

    /**
     * @return string[] base names of .sql files currently in the dump folder
     */
    private function getExistingDumpFiles()
    {
        $files = [];
        $dir = $this->module->getDumpDir();
        if (!is_dir($dir)) {
            return $files;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($dir . $entry) && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'sql') {
                $files[] = $entry;
            }
        }
        sort($files);

        return $files;
    }

    public function renderForm()
    {
        $fileOptions = [];
        foreach ($this->getExistingDumpFiles() as $f) {
            $fileOptions[] = ['id' => $f, 'name' => $f];
        }
        if (!$fileOptions) {
            $fileOptions[] = ['id' => '', 'name' => $this->trans('(w folderze zrzutów nie ma plików .sql)', [], 'Modules.Aplinesimpleordersmigrator.Admin')];
        }

        $maxBytes = (int) Configuration::get(apline_simple_orders_migrator::MAX_UPLOAD_BYTES_KEY);
        $maxHuman = $this->printSize($maxBytes);

        $this->fields_form = [
            'legend' => [
                'title' => $this->trans('Nowe zadanie importu', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'icon' => 'icon-plus',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->trans('Nazwa zadania', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    'name' => 'name',
                    'required' => true,
                    'desc' => $this->trans('Nazwa, po której rozpoznasz tę sesję importu, np. „Import ze starego sklepu 2026-05-26”.', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                ],
                [
                    'type' => 'radio',
                    'label' => $this->trans('Źródło zrzutu', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    'name' => 'dump_source',
                    'required' => true,
                    'values' => [
                        ['id' => 'src_upload', 'value' => 'upload', 'label' => $this->trans('Wgraj z komputera', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' (' . $this->trans('maks.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' ' . $maxHuman . ')'],
                        ['id' => 'src_existing', 'value' => 'existing', 'label' => $this->trans('Wybierz plik umieszczony wcześniej w folderze zrzutów (przez FTP)', [], 'Modules.Aplinesimpleordersmigrator.Admin')],
                    ],
                ],
                [
                    'type' => 'file',
                    'label' => $this->trans('Wgraj zrzut .sql', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    'name' => 'dump_upload',
                    'desc' => $this->trans('Tylko pliki .sql. Większe zrzuty wgraj przez FTP do folderu zrzutów i wybierz je poniżej.', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->trans('Istniejący plik zrzutu', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    'name' => 'dump_existing',
                    'options' => ['query' => $fileOptions, 'id' => 'id', 'name' => 'name'],
                    'desc' => $this->trans('Używane, gdy w polu „Źródło zrzutu” wybrano plik z folderu zrzutów (FTP).', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                ],
            ],
            'submit' => [
                'title' => $this->trans('Utwórz zadanie importu', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                'class' => 'btn btn-primary btn-lg apline-btn-duzy pull-right',
                'icon' => 'icon-plus',
            ],
        ];

        $this->fields_value['dump_source'] = 'upload';

        return parent::renderForm();
    }

    /**
     * Per-job detail view. Shows the stored facts plus a status-aware action
     * area: the parse trigger + progress bar while uploaded/parsing, a counts
     * summary once staged. The full per-status pipeline UI (preview, mapping,
     * import, report) is rendered by the per-status methods below. Never 500s.
     *
     * @return string
     */
    public function renderView()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);
        if (!Validate::isLoadedObject($job)) {
            return '<div class="alert alert-danger">' . $this->trans('To zadanie importu już nie istnieje.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</div>';
        }

        $rows = [
            $this->trans('Nazwa zadania', [], 'Modules.Aplinesimpleordersmigrator.Admin') => htmlspecialchars((string) $job->name, ENT_QUOTES),
            $this->trans('Plik zrzutu', [], 'Modules.Aplinesimpleordersmigrator.Admin') => htmlspecialchars((string) $job->dump_filename, ENT_QUOTES),
            $this->trans('Rozmiar', [], 'Modules.Aplinesimpleordersmigrator.Admin') => $this->printSize((int) $job->dump_size_bytes),
            $this->trans('MD5', [], 'Modules.Aplinesimpleordersmigrator.Admin') => htmlspecialchars((string) $job->dump_md5, ENT_QUOTES),
            $this->trans('Wykryty prefiks tabel źródła', [], 'Modules.Aplinesimpleordersmigrator.Admin') => htmlspecialchars((string) $job->source_db_prefix, ENT_QUOTES),
            $this->trans('Wersja PrestaShop źródła', [], 'Modules.Aplinesimpleordersmigrator.Admin') => $this->printVersionBadge((string) $job->source_ps_version),
            $this->trans('Status', [], 'Modules.Aplinesimpleordersmigrator.Admin') => $this->printStatusBadge((string) $job->status),
            $this->trans('Utworzono', [], 'Modules.Aplinesimpleordersmigrator.Admin') => htmlspecialchars((string) $job->date_add, ENT_QUOTES),
        ];

        $html = '<div class="panel"><h3><i class="icon-exchange"></i> '
            . $this->trans('Zadanie importu', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' #' . (int) $job->id . '</h3><table class="table">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th style="width:240px;">' . htmlspecialchars($label, ENT_QUOTES) . '</th><td>'
                . ($value === null || $value === '' ? '<span class="text-muted">&mdash;</span>' : $value)
                . '</td></tr>';
        }
        $html .= '</table>';
        $html .= $this->renderStatusArea($job);

        $back = '<a class="btn btn-default" href="'
            . htmlspecialchars($this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES)
            . '"><i class="icon-chevron-left"></i> ' . $this->trans('Wróć do listy zadań', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</a>';

        $credit = ($this->module && method_exists($this->module, 'renderAplineFooter'))
            ? $this->module->renderAplineFooter()
            : '';

        return $html . '<div style="margin-top:12px;">' . $back . '</div></div>' . $credit;
    }

    /**
     * Status-dependent action area inside the job detail view.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderStatusArea($job)
    {
        $ajaxUrl = $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob')
            . '&ajax=1&action=ParseDump&id_asom_job=' . (int) $job->id;

        $progress = '<div id="asom-parse" data-url="' . htmlspecialchars($ajaxUrl, ENT_QUOTES) . '"'
            . ' data-autostart="' . ($job->status === 'parsing' ? '1' : '0') . '"'
            . ' style="display:' . ($job->status === 'parsing' ? 'block' : 'none') . ';">'
            . '<div class="asom-phase">' . $this->trans('Uruchamianie...', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</div>'
            . '<div class="asom-progress"><div class="asom-progress-bar">0%</div></div>'
            . '</div>';

        if ($job->status === 'uploaded' || $job->status === 'parsing') {
            $btn = ($job->status === 'uploaded')
                ? '<button type="button" id="asom-parse-start" class="btn btn-primary btn-lg apline-btn-duzy"><i class="icon-cogs"></i> '
                    . $this->trans('Wczytaj zrzut do piaskownicy', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>'
                : '<p class="text-muted">' . $this->trans('Analiza zrzutu trwa. Wznowi się automatycznie.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';

            return '<div class="panel"><h3><i class="icon-cogs"></i> ' . $this->trans('Krok 1 — Analiza zrzutu', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>'
                . '<p>' . $this->trans('Wczytuje zrzut SQL do odizolowanych tabel-piaskownic. Dane sklepu pozostają nietknięte.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>'
                . $btn . $progress . '</div>';
        }

        if ($job->status === 'staged') {
            return $this->renderStagedPreview($job);
        }

        if ($job->status === 'mapping') {
            return $this->renderMappingPhase($job);
        }

        if ($job->status === 'importing') {
            return $this->renderImportingPhase($job);
        }

        if ($job->status === 'completed') {
            return $this->renderCompletionReport($job);
        }

        if ($job->status === 'failed') {
            $html = '<div class="alert alert-danger"><strong>' . $this->trans('To zadanie zakończyło się błędem.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</strong><br>'
                . nl2br(htmlspecialchars((string) $job->error_summary, ENT_QUOTES)) . '</div>';
            // Let the admin retry or clean up, and inspect the log.
            $html .= $this->renderPostImportActions($job);
            $html .= $this->renderLogViewer($job);

            return $html;
        }

        if ($job->status === 'cleaned') {
            $html = '<div class="alert alert-info">'
                . $this->trans('Zadanie zarchiwizowane. Tabele-piaskownice zostały usunięte, a mapowania i log pozostały do wglądu.', [], 'Modules.Aplinesimpleordersmigrator.Admin')
                . '</div>';
            $html .= $this->renderCountsSummary($job);
            $html .= $this->renderLogViewer($job);

            return $html;
        }

        return '';
    }

    /**
     * Counts table built from a job's counts_source JSON.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderCountsTable($job)
    {
        $counts = json_decode((string) $job->counts_source, true);
        $counts = is_array($counts) ? $counts : [];
        if (!$counts) {
            return '<p class="text-muted">' . $this->trans('Brak zapisanych liczników.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';
        }
        $labels = [
            'customers' => $this->trans('Klienci', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'addresses' => $this->trans('Adresy', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'orders' => $this->trans('Zamówienia', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'order_details' => $this->trans('Pozycje zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'order_history' => $this->trans('Wpisy historii zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'messages' => $this->trans('Wiadomości', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'products' => $this->trans('Produkty (w zamówieniach)', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'newsletter_subscriptions' => $this->trans('Zapisy do newslettera (tylko odczyt, nie są importowane)', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
        ];
        $html = '<table class="table"><tbody>';
        foreach ($counts as $k => $v) {
            $label = isset($labels[$k]) ? $labels[$k] : htmlspecialchars((string) $k, ENT_QUOTES);
            $html .= '<tr><th style="width:240px;">' . $label . '</th><td><strong>' . (int) $v . '</strong></td></tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Staged preview: detected version, source counts, validation verdict and
     * the gate to the mapping phase. Validation is read-only against the
     * sandbox and runs on each view (cheap: SHOW COLUMNS over a few tables).
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderStagedPreview($job)
    {
        $id = (int) $job->id;
        $validator = new AsomValidator($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_');
        $verdict = $validator->validate((string) $job->dump_md5);

        // Persist the detected version the first time we see it.
        if ($verdict['version'] !== '' && (string) $job->source_ps_version !== $verdict['version']) {
            Db::getInstance()->update('asom_job', [
                'source_ps_version' => pSQL($verdict['version']),
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_asom_job = ' . $id);
            $job->source_ps_version = $verdict['version'];
        }

        $html = '<div class="panel"><h3><i class="icon-search"></i> ' . $this->trans('Krok 2 — Podgląd i walidacja', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';

        // Version line.
        $versionTxt = $verdict['version'] !== ''
            ? $this->printVersionBadge($verdict['version'])
            : '<span class="text-muted">' . $this->trans('nieznana', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</span>';
        $html .= '<p>' . $this->trans('Wykryta wersja PrestaShop w źródle:', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' ' . $versionTxt . '</p>';

        // Counts.
        $html .= '<h4>' . $this->trans('Zawartość zrzutu (piaskownica)', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h4>';
        $html .= $this->renderCountsTable($job);

        // Errors (hard) + warnings (soft).
        foreach ($verdict['errors'] as $e) {
            $html .= '<div class="alert alert-danger">' . htmlspecialchars($e, ENT_QUOTES) . '</div>';
        }
        foreach ($verdict['warnings'] as $w) {
            $html .= '<div class="alert alert-warning">' . htmlspecialchars($w, ENT_QUOTES) . '</div>';
        }

        // Gate to mapping.
        if ($verdict['can_proceed']) {
            $token = Tools::getAdminTokenLite('AdminAplineSimpleOrdersMigratorJob');
            $action = $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob');
            $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '">'
                . '<input type="hidden" name="id_asom_job" value="' . $id . '">'
                . '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">'
                . '<button type="submit" name="submitStartMapping" class="btn btn-primary btn-lg apline-btn-duzy">'
                . '<i class="icon-arrow-right"></i> ' . $this->trans('Skonfiguruj mapowania', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>'
                . '</form>';
        } else {
            $html .= '<p class="text-danger"><strong>'
                . $this->trans('Usuń powyższe błędy, zanim przejdziesz dalej. Struktura zrzutu jest niezgodna z modułem.', [], 'Modules.Aplinesimpleordersmigrator.Admin')
                . '</strong></p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Compact counts panel for jobs already past staging.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderCountsSummary($job)
    {
        return '<div class="panel"><h3><i class="icon-list"></i> ' . $this->trans('Wczytana zawartość (piaskownica)', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>'
            . $this->renderCountsTable($job)
            . '<p class="text-muted">' . $this->trans('To zadanie jest zarchiwizowane — liczniki pochodzą z analizy zrzutu.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p></div>';
    }

    /**
     * Mapping phase UI. Runs the EAN auto-match once on first entry, then shows
     * the product mapping table (Layer 1 auto / Layer 2 manual / Layer 3
     * snapshot) with live counters and the snapshot-fallback gate.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderMappingPhase($job)
    {
        $id = (int) $job->id;
        $sandbox = apline_simple_orders_migrator::sandboxPrefix() . $id . '_';
        $mapper = new AsomProductMapper($id, $sandbox);

        // First entry: auto-match by EAN exactly once (no product mappings yet).
        $existing = AsomMapping::all($id, 'product');
        if (!$existing) {
            try {
                $mapper->autoMatchByEan();
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog('apline_simple_orders_migrator EAN auto-match: ' . $e->getMessage(), 2);
            }
        }

        $products = $mapper->getProducts();
        $counts = AsomMapping::countByStrategy($id, 'product');
        $auto = (int) $counts['auto_ean'];
        $manual = (int) $counts['live_link'];
        $snap = (int) $counts['snapshot_only'];
        $unresolved = 0;
        foreach ($products as $p) {
            if (!in_array($p['strategy'], ['auto_ean', 'live_link', 'snapshot_only'], true)) {
                ++$unresolved;
            }
        }

        $action = htmlspecialchars($this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);
        $token = htmlspecialchars(Tools::getAdminTokenLite('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);
        $searchUrl = htmlspecialchars(
            $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob')
            . '&ajax=1&action=SearchProduct&id_asom_job=' . $id,
            ENT_QUOTES
        );

        $html = '<div class="panel" id="asom-pmap" data-search-url="' . $searchUrl . '">';
        $html .= '<h3><i class="icon-random"></i> ' . $this->trans('Krok 3 — Mapowanie produktów', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';
        $html .= '<p>' . $this->trans('Każdy produkt występujący w importowanych zamówieniach jest dopasowywany po EAN do produktu w tym sklepie. Gdy nie ma dopasowania po EAN, powiąż go ręcznie albo zostaw jako migawkę (pozycja zamówienia zachowuje nazwę i cenę, ale nie jest powiązana z produktem w sklepie).', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';

        $html .= '<div class="asom-counts" style="margin-bottom:10px;">'
            . '<code>&#129302; ' . $auto . ' ' . $this->trans('dopasowano automatycznie (EAN)', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</code>'
            . '<code>&#9995; ' . $manual . ' ' . $this->trans('powiązano ręcznie', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</code>'
            . '<code>&#128274; ' . $snap . ' ' . $this->trans('tylko migawka', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</code>'
            . '<code>&#9203; ' . $unresolved . ' ' . $this->trans('wymaga decyzji', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</code>'
            . '</div>';

        // Mapping form.
        $html .= '<form method="post" action="' . $action . '">';
        $html .= '<input type="hidden" name="id_asom_job" value="' . $id . '">';
        $html .= '<input type="hidden" name="token" value="' . $token . '">';
        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->trans('Stary ID', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '<th>' . $this->trans('Produkt (stary sklep)', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '<th>' . $this->trans('EAN', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '<th class="text-right">' . $this->trans('Sprzedano', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '<th>' . $this->trans('Mapowanie', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($products as $p) {
            $oldId = htmlspecialchars($p['old_id'], ENT_QUOTES);
            $html .= '<tr>';
            $html .= '<td>' . $oldId . '</td>';
            $html .= '<td>' . ($p['name'] !== '' ? htmlspecialchars($p['name'], ENT_QUOTES) : '<span class="text-muted">&mdash;</span>') . '</td>';
            $html .= '<td>' . ($p['ean'] !== '' ? htmlspecialchars($p['ean'], ENT_QUOTES) : '<span class="text-muted">' . $this->trans('brak', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</span>') . '</td>';
            $html .= '<td class="text-right">' . (int) $p['sold'] . '</td>';
            $html .= '<td>' . $this->renderProductMappingCell($p) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        $html .= '<button type="submit" name="submitProductMappings" class="btn btn-primary btn-lg apline-btn-duzy"><i class="icon-save"></i> ' . $this->trans('Zapisz decyzje o produktach', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>';
        $html .= '</form>';

        // Snapshot fallback for everything still unresolved.
        if ($unresolved > 0) {
            $html .= '<form method="post" action="' . $action . '" style="margin-top:10px;">';
            $html .= '<input type="hidden" name="id_asom_job" value="' . $id . '">';
            $html .= '<input type="hidden" name="token" value="' . $token . '">';
            $html .= '<button type="submit" name="submitSnapshotFallback" class="btn btn-default">'
                . '<i class="icon-lock"></i> ' . sprintf($this->trans('Zastosuj migawki dla nierozstrzygniętych (%d)', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $unresolved) . '</button>';
            $html .= '</form>';
        } else {
            $html .= '<div class="alert alert-success" style="margin-top:10px;">'
                . $this->trans('Wszystkie produkty mają decyzję. Skonfiguruj poniżej mapowania pozostałych danych.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</div>';
        }

        $html .= '</div>';

        // Reference mappings (states / payments / carriers / currencies / langs).
        $html .= $this->renderRefMappings($job, $unresolved);

        return $html;
    }

    /**
     * Reference-entity mapping tables + the readiness gate to import.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     * @param int                           $productUnresolved
     *
     * @return string
     */
    private function renderRefMappings($job, $productUnresolved)
    {
        $id = (int) $job->id;
        $ref = new AsomRefMapper($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_');

        // Seed sensible defaults once.
        try {
            $ref->ensureAutoSuggestions();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_orders_migrator ref seed: ' . $e->getMessage(), 2);
        }

        $action = htmlspecialchars($this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);
        $token = htmlspecialchars(Tools::getAdminTokenLite('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);

        $html = '<div class="panel"><h3><i class="icon-sitemap"></i> ' . $this->trans('Krok 4 — Statusy zamówień, płatności, przewoźnicy, waluty i języki', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';
        $html .= '<form method="post" action="' . $action . '">';
        $html .= '<input type="hidden" name="id_asom_job" value="' . $id . '">';
        $html .= '<input type="hidden" name="token" value="' . $token . '">';

        $html .= $this->renderRefTable(
            $this->trans('Statusy zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'order_state',
            $ref->sourceOrderStates(),
            $ref->targetOrderStates(),
            AsomMapping::all($id, 'order_state'),
            true
        );
        $html .= $this->renderRefTable(
            $this->trans('Moduły płatności', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'payment_module',
            $ref->sourcePayments(),
            $ref->targetPayments(),
            AsomMapping::all($id, 'payment_module'),
            false
        );
        $html .= $this->renderRefTable(
            $this->trans('Przewoźnicy', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'carrier',
            $ref->sourceCarriers(),
            $ref->targetCarriers(),
            AsomMapping::all($id, 'carrier'),
            true
        );
        $html .= $this->renderRefTable(
            $this->trans('Waluty', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'currency',
            $ref->sourceCurrencies(),
            $ref->targetCurrencies(),
            AsomMapping::all($id, 'currency'),
            true
        );
        $html .= $this->renderRefTable(
            $this->trans('Języki', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'lang',
            $ref->sourceLangs(),
            $ref->targetLangs(),
            AsomMapping::all($id, 'lang'),
            true
        );

        $html .= '<button type="submit" name="submitRefMappings" class="btn btn-primary btn-lg apline-btn-duzy"><i class="icon-save"></i> ' . $this->trans('Zapisz mapowania', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>';
        $html .= '</form></div>';

        // Readiness gate.
        $html .= $this->renderReadiness($job, $ref, $productUnresolved);

        return $html;
    }

    /**
     * One reference-mapping table (source rows with a target dropdown).
     *
     * @param string                $title
     * @param string                $entityType
     * @param array<string,string>  $source  oldId => label
     * @param array<string,string>  $target  newId => label
     * @param array<string,array>   $current existing mappings keyed by old_id
     * @param bool                  $numericTarget whether target ids are numeric
     *
     * @return string
     */
    private function renderRefTable($title, $entityType, $source, $target, $current, $numericTarget)
    {
        $html = '<h4>' . htmlspecialchars($title, ENT_QUOTES) . '</h4>';
        if (!$source) {
            $html .= '<p class="text-muted">' . $this->trans('Nie ma nic do zmapowania (tych danych nie ma w zrzucie).', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';

            return $html;
        }

        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->trans('ID w źródle', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '<th>' . $this->trans('Nazwa w źródle', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '<th>' . $this->trans('Mapuj na (ten sklep)', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($source as $oldId => $label) {
            $oldId = (string) $oldId;
            $currentNew = isset($current[$oldId]) && $current[$oldId]['new_id'] !== null ? (string) $current[$oldId]['new_id'] : '';
            $strategy = isset($current[$oldId]) ? (string) $current[$oldId]['mapping_strategy'] : '';

            $select = '<select name="map_' . $entityType . '[' . htmlspecialchars($oldId, ENT_QUOTES) . ']" class="fixed-width-xxl">';
            $select .= '<option value="">' . $this->trans('— pomiń / brak —', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</option>';
            foreach ($target as $tid => $tlabel) {
                $tid = (string) $tid;
                $sel = ($tid === $currentNew) ? ' selected' : '';
                $optLabel = $numericTarget ? ('#' . $tid . ' ' . $tlabel) : $tlabel;
                $select .= '<option value="' . htmlspecialchars($tid, ENT_QUOTES) . '"' . $sel . '>'
                    . htmlspecialchars($optLabel, ENT_QUOTES) . '</option>';
            }
            $select .= '</select>';

            $flag = ($strategy === 'fallback')
                ? ' <span class="label label-warning">' . $this->trans('wartość zastępcza', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</span>'
                : (($strategy === 'skipped') ? ' <span class="label label-danger">' . $this->trans('niezmapowane', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</span>' : '');

            $html .= '<tr>'
                . '<td>' . htmlspecialchars($oldId, ENT_QUOTES) . '</td>'
                . '<td>' . ($label !== '' ? htmlspecialchars($label, ENT_QUOTES) : '<span class="text-muted">&mdash;</span>') . '</td>'
                . '<td>' . $select . $flag . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Readiness gate: blocks import while products are unresolved or a used
     * currency/language has no target. The actual pre-import confirmation and
     * start button are rendered by renderPreImportForm().
     *
     * @param AplineSimpleOrdersMigratorJob $job
     * @param AsomRefMapper                 $ref
     * @param int                           $productUnresolved
     *
     * @return string
     */
    private function renderReadiness($job, $ref, $productUnresolved)
    {
        $issues = [];
        if ($productUnresolved > 0) {
            $issues[] = sprintf($this->trans('Produkty czekające na decyzję (powiązanie lub migawka): %d.', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $productUnresolved);
        }
        foreach ($ref->getBlockingIssues() as $i) {
            $issues[] = $i;
        }

        $html = '<div class="panel"><h3><i class="icon-flag-checkered"></i> ' . $this->trans('Krok 5 — Potwierdzenie i import', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';
        if ($issues) {
            $html .= '<div class="alert alert-warning"><strong>' . $this->trans('Rozwiąż te kwestie przed importem:', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</strong><ul>';
            foreach ($issues as $i) {
                $html .= '<li>' . htmlspecialchars($i, ENT_QUOTES) . '</li>';
            }
            $html .= '</ul></div></div>';

            return $html;
        }

        $html .= '<div class="alert alert-success">' . $this->trans('Wszystkie mapowania są gotowe.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</div>';
        $html .= $this->renderPreImportForm($job);
        $html .= '</div>';

        return $html;
    }

    /**
     * Pre-import confirmation form: backup checkboxes + what-to-import options
     * + the start button.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderPreImportForm($job)
    {
        $id = (int) $job->id;
        $action = htmlspecialchars($this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);
        $token = htmlspecialchars(Tools::getAdminTokenLite('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);
        $requireBackup = (int) Configuration::get(apline_simple_orders_migrator::REQUIRE_BACKUP_KEY);

        $counts = json_decode((string) $job->counts_source, true);
        $counts = is_array($counts) ? $counts : [];
        $c = function ($k) use ($counts) {
            return isset($counts[$k]) ? (int) $counts[$k] : 0;
        };

        $html = '<form method="post" action="' . $action . '">';
        $html .= '<input type="hidden" name="id_asom_job" value="' . $id . '">';
        $html .= '<input type="hidden" name="token" value="' . $token . '">';

        if ($requireBackup) {
            $html .= '<h4>' . $this->trans('Potwierdzenia', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h4>';
            $html .= '<label style="display:block;"><input type="checkbox" name="cb_backup" value="1"> '
                . $this->trans('Mam kopię zapasową bazy danych tego sklepu PrestaShop 9.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</label>';
            $html .= '<label style="display:block;"><input type="checkbox" name="cb_understand" value="1"> '
                . $this->trans('Rozumiem, że import utworzy nowe konta klientów i zamówienia.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</label>';
            $html .= '<label style="display:block;"><input type="checkbox" name="cb_emails" value="1"> '
                . $this->trans('Rozumiem, że klienci mogą dostać e-maile z linkiem do ustawienia hasła (jeśli włączę tę opcję poniżej).', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</label>';
        }

        $html .= '<h4 style="margin-top:12px;">' . $this->trans('Co zaimportować', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h4>';
        // $lockedNote: opcja działa zawsze (importer jej nie wyłącza) - pokazujemy ją
        // jako zaznaczoną i nieaktywną, żeby pole nie sugerowało wyboru, którego nie ma.
        $opt = function ($name, $label, $count, $checked = true, $lockedNote = null) {
            $ck = $checked ? ' checked' : '';
            $dis = $lockedNote !== null ? ' disabled' : '';
            $cnt = $count !== null ? ' (' . (int) $count . ')' : '';
            $note = $lockedNote !== null ? ' <span class="text-muted">— ' . htmlspecialchars($lockedNote, ENT_QUOTES) . '</span>' : '';

            return '<label style="display:block;"><input type="checkbox" name="' . $name . '" value="1"' . $ck . $dis . '> '
                . htmlspecialchars($label, ENT_QUOTES) . $cnt . $note . '</label>';
        };
        $html .= $opt('opt_customers', $this->trans('Klienci', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $c('customers'), true, $this->trans('zawsze importowani', [], 'Modules.Aplinesimpleordersmigrator.Admin'));
        $html .= $opt('opt_addresses', $this->trans('Adresy', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $c('addresses'));
        $html .= $opt('opt_orders', $this->trans('Zamówienia z pozycjami', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $c('orders'), true, $this->trans('zawsze importowane', [], 'Modules.Aplinesimpleordersmigrator.Admin'));
        $html .= $opt('opt_history', $this->trans('Historia zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $c('order_history'));
        $html .= $opt('opt_messages', $this->trans('Wiadomości do zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $c('messages'), false);
        $html .= $opt('opt_emails', $this->trans('Wyślij nowym klientom e-maile z linkiem do ustawienia hasła', [], 'Modules.Aplinesimpleordersmigrator.Admin'), null, true);
        $html .= $opt('opt_skip_dup_cust', $this->trans('Pomijaj zduplikowanych klientów (wg adresu e-mail)', [], 'Modules.Aplinesimpleordersmigrator.Admin'), null, true, $this->trans('zawsze włączone', [], 'Modules.Aplinesimpleordersmigrator.Admin'));
        $html .= $opt('opt_skip_dup_ord', $this->trans('Pomijaj zduplikowane zamówienia (wg referencji)', [], 'Modules.Aplinesimpleordersmigrator.Admin'), null, true);

        $html .= '<div style="margin-top:12px;"><button type="submit" name="submitStartImport" class="btn btn-primary btn-lg apline-btn-duzy">'
            . '<i class="icon-play"></i> ' . $this->trans('Rozpocznij import', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button></div>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Validate confirmations + readiness, persist import options, switch the
     * job to importing and reset the import cursor. Re-checks readiness so the
     * gate cannot be bypassed by crafting the POST.
     *
     * @return bool
     */
    private function processStartImport()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = $this->loadMappingJob($id);
        if (!$job) {
            return false;
        }

        $sandbox = apline_simple_orders_migrator::sandboxPrefix() . $id . '_';

        // Re-validate readiness server-side.
        $mapper = new AsomProductMapper($id, $sandbox);
        $ref = new AsomRefMapper($id, $sandbox);
        $issues = [];
        if ($mapper->unresolvedCount() > 0) {
            $issues[] = $this->trans('Niektóre produkty nie mają jeszcze decyzji.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        }
        foreach ($ref->getBlockingIssues() as $bi) {
            $issues[] = $bi;
        }
        if ($issues) {
            $this->errors[] = $this->trans('Nie można rozpocząć importu — najpierw rozwiąż problemy z mapowaniem.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }

        // Backup confirmations.
        if ((int) Configuration::get(apline_simple_orders_migrator::REQUIRE_BACKUP_KEY)) {
            if (!Tools::getValue('cb_backup') || !Tools::getValue('cb_understand') || !Tools::getValue('cb_emails')) {
                $this->errors[] = $this->trans('Przed importem zaznacz wszystkie pola potwierdzeń.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

                return false;
            }
        }

        $options = [
            'import_customers' => true,
            'import_addresses' => (bool) Tools::getValue('opt_addresses'),
            'import_orders' => true,
            'import_order_history' => (bool) Tools::getValue('opt_history'),
            'import_messages' => (bool) Tools::getValue('opt_messages'),
            'send_password_reset_emails' => (bool) Tools::getValue('opt_emails'),
            'skip_duplicate_customers' => true,
            'skip_duplicate_orders' => (bool) Tools::getValue('opt_skip_dup_ord'),
        ];

        // Preserve the staged parse cursor cleanup; reset the import cursor.
        $raw = Db::getInstance()->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $id);
        $stored = json_decode((string) $raw, true);
        $stored = is_array($stored) ? $stored : [];
        $stored = array_merge($stored, $options);
        unset($stored['_import']);

        Db::getInstance()->update('asom_job', [
            'import_options' => pSQL(json_encode($stored)),
            'status' => 'importing',
            'started_at' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
            'counts_imported' => null,
            'counts_skipped' => null,
            'counts_failed' => null,
            'error_summary' => null,
        ], 'id_asom_job = ' . $id);

        $this->redirectToView($id);

        return true;
    }

    /**
     * Importing phase view: progress bar that auto-drives the batch AJAX.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderImportingPhase($job)
    {
        $id = (int) $job->id;
        $ajaxUrl = $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob')
            . '&ajax=1&action=BatchImport&id_asom_job=' . $id;

        $html = '<div class="panel"><h3><i class="icon-play"></i> ' . $this->trans('Krok 6 — Import', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';
        $html .= '<p>' . $this->trans('Import przebiega wsadami. Nie zamykaj tej strony — po jej odświeżeniu import wznowi się automatycznie.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';
        $html .= '<div id="asom-import" data-url="' . htmlspecialchars($ajaxUrl, ENT_QUOTES) . '" data-autostart="1">';
        $html .= '<div class="asom-phase">' . $this->trans('Uruchamianie...', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</div>';
        $html .= '<div class="asom-progress"><div class="asom-progress-bar">0%</div></div>';
        $html .= '<div class="asom-import-counts"></div>';
        $html .= '</div></div>';

        return $html;
    }

    /**
     * Completion report: imported/skipped/failed counts, time, the
     * password-reset email runner, the log viewer and post-import actions.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderCompletionReport($job)
    {
        $id = (int) $job->id;
        $imported = json_decode((string) $job->counts_imported, true);
        $skipped = json_decode((string) $job->counts_skipped, true);
        $failed = json_decode((string) $job->counts_failed, true);
        $imported = is_array($imported) ? $imported : [];
        $skipped = is_array($skipped) ? $skipped : [];
        $failed = is_array($failed) ? $failed : [];

        $html = '<div class="panel"><h3><i class="icon-check"></i> ' . $this->trans('Import zakończony', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';

        // Duration.
        if ($job->started_at && $job->finished_at) {
            $secs = max(0, strtotime($job->finished_at) - strtotime($job->started_at));
            $html .= '<p>' . $this->trans('Czas trwania:', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' ' . (int) $secs . ' ' . $this->trans('sek.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';
        }

        // Counts table.
        $entities = array_unique(array_merge(array_keys($imported), array_keys($skipped), array_keys($failed)));
        $html .= '<table class="table"><thead><tr><th>' . $this->trans('Element', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th><th class="text-right">'
            . $this->trans('Zaimportowano', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th><th class="text-right">' . $this->trans('Pominięto', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th><th class="text-right">'
            . $this->trans('Błędy', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th></tr></thead><tbody>';
        foreach ($entities as $e) {
            $html .= '<tr><td>' . htmlspecialchars($this->entityLabel((string) $e), ENT_QUOTES) . '</td>'
                . '<td class="text-right">' . (isset($imported[$e]) ? (int) $imported[$e] : 0) . '</td>'
                . '<td class="text-right">' . (isset($skipped[$e]) ? (int) $skipped[$e] : 0) . '</td>'
                . '<td class="text-right">' . (isset($failed[$e]) ? (int) $failed[$e] : 0) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        $html .= '</div>';

        // Password-reset emails.
        $options = json_decode((string) $job->import_options, true);
        $options = is_array($options) ? $options : [];
        if (!empty($options['send_password_reset_emails'])) {
            $emailer = new AsomEmailer($id, $this->module);
            $totalEmails = $emailer->total();
            $sentCursor = $options['_emails']['sent'] ?? 0;
            $emailsDone = isset($options['_emails']) && ($options['_emails']['last_id'] ?? 0) > 0 && $sentCursor >= 0
                && $this->emailsFinished($options, $totalEmails);

            $ajaxUrl = $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob')
                . '&ajax=1&action=SendEmails&id_asom_job=' . $id;

            $html .= '<div class="panel"><h3><i class="icon-envelope"></i> ' . $this->trans('E-maile z linkiem do ustawienia hasła', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';
            if ($totalEmails === 0) {
                $html .= '<p class="text-muted">' . $this->trans('Brak nowo utworzonych klientów, do których można wysłać e-mail.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';
            } elseif ($emailsDone) {
                $html .= '<div class="alert alert-success">' . sprintf($this->trans('Wysłano e-maili: %d z %d.', [], 'Modules.Aplinesimpleordersmigrator.Admin'), (int) $sentCursor, (int) $totalEmails) . '</div>';
            } else {
                $html .= '<p>' . sprintf($this->trans('Liczba klientów, którzy mogą dostać e-mail z linkiem do ustawienia hasła: %d.', [], 'Modules.Aplinesimpleordersmigrator.Admin'), (int) $totalEmails) . '</p>';
                $html .= '<div id="asom-emails" data-url="' . htmlspecialchars($ajaxUrl, ENT_QUOTES) . '" data-autostart="0">';
                $html .= '<button type="button" id="asom-emails-start" class="btn btn-primary btn-lg apline-btn-duzy"><i class="icon-envelope"></i> ' . $this->trans('Wyślij e-maile z linkiem do ustawienia hasła', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>';
                $html .= '<div class="asom-phase" style="margin-top:8px;"></div>';
                $html .= '<div class="asom-progress" style="display:none;"><div class="asom-progress-bar">0%</div></div>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        // Post-import actions (cleanup / re-run / delete dump).
        $html .= $this->renderPostImportActions($job);

        // Log viewer.
        $html .= $this->renderLogViewer($job);

        return $html;
    }

    /**
     * @param array $options
     * @param int   $total
     *
     * @return bool whether every eligible customer has been processed
     */
    private function emailsFinished($options, $total)
    {
        if ($total === 0) {
            return true;
        }
        // Cheap heuristic: emails are finished when sent + skipped >= total.
        $sent = isset($options['_emails']['sent']) ? (int) $options['_emails']['sent'] : 0;

        return $sent >= $total;
    }

    /**
     * Post-import action buttons. Cleanup / re-run failed are handled by
     * the process*() methods; here we render the buttons.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderPostImportActions($job)
    {
        $id = (int) $job->id;
        $action = htmlspecialchars($this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);
        $token = htmlspecialchars(Tools::getAdminTokenLite('AdminAplineSimpleOrdersMigratorJob'), ENT_QUOTES);

        $failed = json_decode((string) $job->counts_failed, true);
        $failed = is_array($failed) ? $failed : [];
        $hasFailures = false;
        foreach ($failed as $n) {
            if ((int) $n > 0) {
                $hasFailures = true;
                break;
            }
        }
        $hasSandbox = $this->jobHasSandbox($id);

        $html = '<div class="panel"><h3><i class="icon-wrench"></i> ' . $this->trans('Po imporcie', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>';

        // Re-run failed (only when there were failures and the sandbox is intact).
        if ($hasFailures && $hasSandbox) {
            $html .= '<form method="post" action="' . $action . '" style="display:inline-block;margin-right:8px;">';
            $html .= '<input type="hidden" name="id_asom_job" value="' . $id . '">';
            $html .= '<input type="hidden" name="token" value="' . $token . '">';
            $html .= '<button type="submit" name="submitRerunFailed" class="btn btn-warning"><i class="icon-repeat"></i> ' . $this->trans('Ponów import (spróbuj ponownie dla błędów)', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>';
            $html .= '</form>';
        }

        // Cleanup sandbox.
        if ($hasSandbox) {
            $html .= '<form method="post" action="' . $action . '" style="display:inline-block;margin-right:8px;"'
                . ' onsubmit="return confirm(\'' . $this->trans('Usunąć tabele-piaskownice tego zadania? Mapowania i log zostaną zachowane.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '\');">';
            $html .= '<input type="hidden" name="id_asom_job" value="' . $id . '">';
            $html .= '<input type="hidden" name="token" value="' . $token . '">';
            $html .= '<button type="submit" name="submitCleanupSandbox" class="btn btn-default"><i class="icon-trash"></i> ' . $this->trans('Wyczyść tabele-piaskownice', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>';
            $html .= '</form>';
        } else {
            $html .= '<p class="text-muted">' . $this->trans('Tabele-piaskownice zostały już wyczyszczone.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';
        }

        // Delete dump file.
        $html .= '<form method="post" action="' . $action . '" style="display:inline-block;"'
            . ' onsubmit="return confirm(\'' . $this->trans('Trwale usunąć plik zrzutu z serwera?', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '\');">';
        $html .= '<input type="hidden" name="id_asom_job" value="' . $id . '">';
        $html .= '<input type="hidden" name="token" value="' . $token . '">';
        $html .= '<button type="submit" name="submitDeleteDump" class="btn btn-default"><i class="icon-file"></i> ' . $this->trans('Usuń plik zrzutu', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>';
        $html .= '</form>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Paginated, filterable viewer over asom_log for a job.
     *
     * @param AplineSimpleOrdersMigratorJob $job
     *
     * @return string
     */
    private function renderLogViewer($job)
    {
        $id = (int) $job->id;
        $sev = (string) Tools::getValue('log_sev');
        $phase = (string) Tools::getValue('log_phase');
        $page = max(1, (int) Tools::getValue('log_page'));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $where = 'id_asom_job = ' . $id;
        if (in_array($sev, ['debug', 'info', 'warning', 'error'], true)) {
            $where .= ' AND severity = \'' . pSQL($sev) . '\'';
        }
        if ($phase !== '' && Validate::isGenericName($phase)) {
            $where .= ' AND phase = \'' . pSQL($phase) . '\'';
        }

        $total = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'asom_log` WHERE ' . $where);
        $rows = Db::getInstance()->executeS(
            'SELECT severity, phase, entity_type, entity_old_id, message, date_add
             FROM `' . _DB_PREFIX_ . 'asom_log`
             WHERE ' . $where . '
             ORDER BY id_asom_log DESC
             LIMIT ' . (int) $offset . ', ' . (int) $perPage
        );

        $base = $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob') . '&id_asom_job=' . $id . '&viewasom_job';

        $html = '<div class="panel"><h3><i class="icon-file-text"></i> ' . $this->trans('Log importu', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' (' . $total . ')</h3>';

        // Filters.
        $html .= '<form method="get" class="form-inline" style="margin-bottom:10px;">';
        foreach (['controller' => 'AdminAplineSimpleOrdersMigratorJob', 'id_asom_job' => $id, 'viewasom_job' => '1', 'token' => Tools::getValue('token')] as $k => $v) {
            $html .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES) . '">';
        }
        $sevNames = [
            'debug' => $this->trans('Debugowanie', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'info' => $this->trans('Informacja', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'warning' => $this->trans('Ostrzeżenie', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
            'error' => $this->trans('Błąd', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
        ];
        $phaseNames = $this->phaseLabels();
        $html .= '<select name="log_sev" class="fixed-width-md">';
        $html .= '<option value="">' . $this->trans('Wszystkie poziomy', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</option>';
        foreach ($sevNames as $s => $sName) {
            $sel = ($s === $sev) ? ' selected' : '';
            $html .= '<option value="' . $s . '"' . $sel . '>' . $sName . '</option>';
        }
        $html .= '</select> ';
        $html .= '<select name="log_phase" class="fixed-width-xl">';
        $html .= '<option value="">' . $this->trans('Wszystkie fazy', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</option>';
        foreach ($phaseNames as $p => $pName) {
            $sel = ($p === $phase) ? ' selected' : '';
            $html .= '<option value="' . $p . '"' . $sel . '>' . $pName . '</option>';
        }
        $html .= '</select> ';
        $html .= '<button type="submit" class="btn btn-default">' . $this->trans('Filtruj', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</button>';
        $html .= '</form>';

        if (!is_array($rows) || !$rows) {
            $html .= '<p class="text-muted">' . $this->trans('Brak wpisów logu spełniających kryteria.', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>';
        } else {
            $html .= '<table class="table"><thead><tr><th>' . $this->trans('Poziom', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th><th>' . $this->trans('Faza', [], 'Modules.Aplinesimpleordersmigrator.Admin')
                . '</th><th>' . $this->trans('Element', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th><th>' . $this->trans('Komunikat', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th><th>' . $this->trans('Data', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</th></tr></thead><tbody>';
            $sevLabel = ['error' => 'label-danger', 'warning' => 'label-warning', 'info' => 'label-info', 'debug' => 'label-default'];
            foreach ($rows as $r) {
                $cls = isset($sevLabel[$r['severity']]) ? $sevLabel[$r['severity']] : 'label-default';
                $entType = (string) $r['entity_type'] !== '' ? $this->entityLabel((string) $r['entity_type'], true) : '';
                $ent = trim($entType . ' ' . (string) $r['entity_old_id']);
                $sevText = isset($sevNames[$r['severity']]) ? $sevNames[$r['severity']] : (string) $r['severity'];
                $phaseText = isset($phaseNames[$r['phase']]) ? $phaseNames[$r['phase']] : (string) $r['phase'];
                $html .= '<tr>'
                    . '<td><span class="label ' . $cls . '">' . htmlspecialchars($sevText, ENT_QUOTES) . '</span></td>'
                    . '<td>' . htmlspecialchars($phaseText, ENT_QUOTES) . '</td>'
                    . '<td>' . ($ent !== '' ? htmlspecialchars($ent, ENT_QUOTES) : '<span class="text-muted">&mdash;</span>') . '</td>'
                    . '<td>' . htmlspecialchars((string) $r['message'], ENT_QUOTES) . '</td>'
                    . '<td>' . htmlspecialchars((string) $r['date_add'], ENT_QUOTES) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';

            // Pagination.
            $pages = (int) ceil($total / $perPage);
            if ($pages > 1) {
                $html .= '<div class="pagination"><ul>';
                $sevQ = $sev !== '' ? '&log_sev=' . urlencode($sev) : '';
                $phaseQ = $phase !== '' ? '&log_phase=' . urlencode($phase) : '';
                for ($p = 1; $p <= $pages; ++$p) {
                    $active = ($p === $page) ? ' class="active"' : '';
                    $html .= '<li' . $active . '><a href="' . htmlspecialchars($base . '&log_page=' . $p . $sevQ . $phaseQ, ENT_QUOTES) . '">' . $p . '</a></li>';
                }
                $html .= '</ul></div>';
            }
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * AJAX: send one throttled batch of password-reset emails.
     */
    public function ajaxProcessSendEmails()
    {
        header('Content-Type: application/json');
        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);
        try {
            if (!Validate::isLoadedObject($job) || $job->status !== 'completed') {
                die(json_encode(['success' => false, 'error' => 'Zadanie nie jest zakończone.']));
            }
            $emailer = new AsomEmailer($id, $this->module);
            $res = $emailer->sendBatch();
            die(json_encode($res));
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_orders_migrator emails: ' . $e->getMessage(), 3);
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    /**
     * AJAX: run one import batch. Called repeatedly by admin.js until done.
     */
    public function ajaxProcessBatchImport()
    {
        header('Content-Type: application/json');
        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);

        try {
            if (!Validate::isLoadedObject($job)) {
                die(json_encode(['success' => false, 'error' => 'Nie znaleziono zadania importu.']));
            }
            if ($job->status !== 'importing') {
                die(json_encode(['success' => false, 'error' => 'Zadanie nie jest w trakcie importu (status: ' . $this->statusLabel((string) $job->status) . ').']));
            }

            $options = json_decode((string) $job->import_options, true);
            $options = is_array($options) ? $options : [];
            $batch = (int) Configuration::get(apline_simple_orders_migrator::BATCH_SIZE_KEY);
            if ($batch < 1) {
                $batch = 50;
            }

            $importer = new AsomImporter($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_', $options);
            $res = $importer->runBatch($batch);

            die(json_encode($res));
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_orders_migrator import: ' . $e->getMessage(), 3);
            if (Validate::isLoadedObject($job)) {
                Db::getInstance()->update('asom_job', [
                    'status' => 'failed',
                    'error_summary' => pSQL($e->getMessage()),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], 'id_asom_job = ' . $id);
            }
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    /**
     * The per-row mapping control: shows the auto/manual/snapshot state with a
     * radio choice and a manual-search box.
     *
     * @param array $p one product row from AsomProductMapper::getProducts()
     *
     * @return string
     */
    private function renderProductMappingCell($p)
    {
        $oldId = htmlspecialchars($p['old_id'], ENT_QUOTES);
        $strategy = $p['strategy'];
        $newId = htmlspecialchars($p['new_id'], ENT_QUOTES);
        $matched = htmlspecialchars($p['matched_name'], ENT_QUOTES);

        $out = '';

        if ($strategy === 'auto_ean') {
            $out .= '<div class="text-success"><i class="icon-check"></i> '
                . $this->trans('Dopasowano automatycznie po EAN', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' &rarr; #' . $newId
                . ($matched !== '' ? ' (' . $matched . ')' : '') . '</div>';
        }

        // Radio: link vs snapshot. Pre-select based on current strategy.
        $linkChecked = in_array($strategy, ['live_link', 'auto_ean'], true) ? ' checked' : '';
        $snapChecked = ($strategy === 'snapshot_only') ? ' checked' : '';

        $out .= '<label style="display:block;"><input type="radio" name="pmap[' . $oldId . ']" value="link"' . $linkChecked . '> '
            . $this->trans('Powiąż z produktem:', [], 'Modules.Aplinesimpleordersmigrator.Admin') . ' '
            . '<input type="text" class="asom-psearch" autocomplete="off" placeholder="' . $this->trans('szukaj po nazwie / referencji / EAN...', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '" '
            . 'data-old="' . $oldId . '" value="' . ($matched !== '' ? $matched : '') . '" style="width:220px;">'
            . '<input type="hidden" name="pnew[' . $oldId . ']" class="asom-pnew" value="' . $newId . '">'
            . '<span class="asom-presults" style="position:relative;"></span>'
            . '</label>';

        $out .= '<label style="display:block;"><input type="radio" name="pmap[' . $oldId . ']" value="snapshot"' . $snapChecked . '> '
            . $this->trans('Tylko migawka', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</label>';

        return $out;
    }

    /**
     * AJAX (Layer 2): search this shop's products for the manual-link box.
     */
    public function ajaxProcessSearchProduct()
    {
        header('Content-Type: application/json');
        try {
            $id = (int) Tools::getValue('id_asom_job');
            $q = (string) Tools::getValue('q');
            $mapper = new AsomProductMapper($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_');
            $rows = $mapper->searchProducts($q, 10);
            $out = [];
            foreach ($rows as $r) {
                $label = (string) $r['name'];
                if ((string) $r['reference'] !== '') {
                    $label .= ' [' . $r['reference'] . ']';
                }
                if ((string) $r['ean13'] !== '') {
                    $label .= ' EAN:' . $r['ean13'];
                }
                $out[] = ['id' => (int) $r['id_product'], 'label' => $label];
            }
            die(json_encode(['success' => true, 'results' => $out]));
        } catch (\Throwable $e) {
            die(json_encode(['success' => false, 'error' => $e->getMessage(), 'results' => []]));
        }
    }

    /**
     * AJAX: re-run the EAN auto-match (e.g. after the admin added EANs to the
     * target shop). Returns the new match count.
     */
    public function ajaxProcessRescanMatches()
    {
        header('Content-Type: application/json');
        try {
            $id = (int) Tools::getValue('id_asom_job');
            $mapper = new AsomProductMapper($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_');
            $n = $mapper->autoMatchByEan();
            die(json_encode(['success' => true, 'matched' => $n]));
        } catch (\Throwable $e) {
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    /**
     * AJAX: parse one bounded slice of the dump into sandbox tables. Called
     * repeatedly by admin.js until {done:true}. Resumable via the cursor the
     * parser stores in the job; status moves uploaded -> parsing -> staged.
     */
    public function ajaxProcessParseDump()
    {
        header('Content-Type: application/json');

        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);

        try {
            if (!Validate::isLoadedObject($job)) {
                die(json_encode(['success' => false, 'error' => 'Nie znaleziono zadania importu.']));
            }
            if (!in_array($job->status, ['uploaded', 'parsing'], true)) {
                die(json_encode(['success' => false, 'error' => 'Zadanie nie jest w stanie umożliwiającym analizę zrzutu (status: ' . $this->statusLabel((string) $job->status) . ').']));
            }

            $dumpPath = $this->module->getDumpDir() . $job->dump_filename;
            if (!is_file($dumpPath)) {
                Db::getInstance()->update('asom_job', [
                    'status' => 'failed',
                    'error_summary' => pSQL('Brak pliku zrzutu: ' . $job->dump_filename),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], 'id_asom_job = ' . $id);
                die(json_encode(['success' => false, 'error' => 'Nie znaleziono pliku zrzutu w folderze zrzutów.']));
            }

            if ($job->status === 'uploaded') {
                Db::getInstance()->update('asom_job', [
                    'status' => 'parsing',
                    'started_at' => date('Y-m-d H:i:s'),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], 'id_asom_job = ' . $id);
            }

            Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 0');
            $parser = new AsomDumpParser(
                $id,
                $dumpPath,
                apline_simple_orders_migrator::sandboxPrefix() . $id . '_'
            );
            $res = $parser->parseChunk(8.0, 8388608);
            Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 1');

            $res['success'] = true;
            die(json_encode($res));
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_orders_migrator parse: ' . $e->getMessage(), 3);
            if (Validate::isLoadedObject($job)) {
                Db::getInstance()->update('asom_job', [
                    'status' => 'failed',
                    'error_summary' => pSQL($e->getMessage()),
                    'date_upd' => date('Y-m-d H:i:s'),
                ], 'id_asom_job = ' . $id);
            }
            die(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function postProcess()
    {
        // Custom creation flow (file upload + source radio) instead of the
        // default ObjectModel auto-save.
        if (Tools::isSubmit('submitAdd' . $this->table)) {
            return $this->processAddJob();
        }
        if (Tools::isSubmit('submitStartMapping')) {
            return $this->processStartMapping();
        }
        if (Tools::isSubmit('submitProductMappings')) {
            return $this->processProductMappings();
        }
        if (Tools::isSubmit('submitSnapshotFallback')) {
            return $this->processSnapshotFallback();
        }
        if (Tools::isSubmit('submitRefMappings')) {
            return $this->processRefMappings();
        }
        if (Tools::isSubmit('submitStartImport')) {
            return $this->processStartImport();
        }
        if (Tools::isSubmit('submitCleanupSandbox')) {
            return $this->processCleanupSandbox();
        }
        if (Tools::isSubmit('submitRerunFailed')) {
            return $this->processRerunFailed();
        }
        if (Tools::isSubmit('submitDeleteDump')) {
            return $this->processDeleteDump();
        }

        return parent::postProcess();
    }

    /**
     * Drop a completed job's sandbox tables and mark it cleaned. Mapping and
     * log rows are kept for audit.
     *
     * @return bool
     */
    private function processCleanupSandbox()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);
        if (!Validate::isLoadedObject($job)) {
            $this->errors[] = $this->trans('To zadanie importu już nie istnieje.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }
        if (!in_array($job->status, ['completed', 'failed'], true)) {
            $this->errors[] = $this->trans('Czyszczenie piaskownicy jest dostępne dopiero po zakończeniu importu.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }

        $this->dropJobSandbox($id);
        Db::getInstance()->update('asom_job', [
            'status' => 'cleaned',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_asom_job = ' . $id);

        $this->confirmations[] = $this->trans('Tabele-piaskownice usunięto. Mapowania i log pozostały do wglądu.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        $this->redirectToView($id);

        return true;
    }

    /**
     * Re-run only the entities that failed: clears the import cursor counts for
     * a fresh pass and flips the job back to importing. Because the import is
     * idempotent (dedup by email/reference, mappings already resolved),
     * re-running re-attempts the failures and skips what already landed.
     *
     * @return bool
     */
    private function processRerunFailed()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);
        if (!Validate::isLoadedObject($job)) {
            $this->errors[] = $this->trans('To zadanie importu już nie istnieje.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }
        if (!in_array($job->status, ['completed', 'failed'], true)) {
            $this->errors[] = $this->trans('Ponowienie jest dostępne dopiero po zakończeniu importu.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }
        // Sandbox must still exist.
        if (!$this->jobHasSandbox($id)) {
            $this->errors[] = $this->trans('Tabele-piaskownice zostały już wyczyszczone — ponowienie nie jest możliwe. Utwórz nowe zadanie ze zrzutu.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }

        // Reset the import cursor so the pipeline runs again from the start.
        $raw = Db::getInstance()->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $id);
        $opts = json_decode((string) $raw, true);
        $opts = is_array($opts) ? $opts : [];
        unset($opts['_import']);

        Db::getInstance()->update('asom_job', [
            'import_options' => pSQL(json_encode($opts)),
            'status' => 'importing',
            'date_upd' => date('Y-m-d H:i:s'),
            'error_summary' => null,
        ], 'id_asom_job = ' . $id);

        $this->confirmations[] = $this->trans('Import uruchomiono ponownie. Klienci i zamówienia zaimportowani wcześniej zostaną pominięci automatycznie.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        $this->redirectToView($id);

        return true;
    }

    /**
     * Delete the dump file from disk (after cleanup). Only ever touches a file
     * inside the module dump folder.
     *
     * @return bool
     */
    private function processDeleteDump()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);
        if (!Validate::isLoadedObject($job)) {
            $this->errors[] = $this->trans('To zadanie importu już nie istnieje.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }
        $file = basename((string) $job->dump_filename);
        if ($file !== '') {
            $path = $this->module->getDumpDir() . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->confirmations[] = $this->trans('Plik zrzutu usunięto z folderu zrzutów.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        $this->redirectToView($id);

        return true;
    }

    /**
     * @param int $idJob
     *
     * @return bool whether any sandbox table exists for the job
     */
    private function jobHasSandbox($idJob)
    {
        try {
            $like = str_replace('_', '\\_', apline_simple_orders_migrator::sandboxPrefix() . (int) $idJob . '_') . '%';
            $rows = Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL($like) . '\'');

            return is_array($rows) && count($rows) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Persist posted order-state / payment / carrier / currency / language
     * decisions in one go.
     *
     * @return bool
     */
    private function processRefMappings()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = $this->loadMappingJob($id);
        if (!$job) {
            return false;
        }

        $ref = new AsomRefMapper($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_');
        foreach (['order_state', 'payment_module', 'carrier', 'currency', 'lang'] as $type) {
            $field = 'map_' . $type;
            $pairs = Tools::getValue($field);
            if (is_array($pairs)) {
                $ref->saveDecisions($type, $pairs);
            }
        }

        $this->confirmations[] = $this->trans('Zapisano mapowania.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        $this->redirectToView($id);

        return true;
    }

    /**
     * @param int $id
     *
     * @return AplineSimpleOrdersMigratorJob|null a loaded, mapping-phase job
     */
    private function loadMappingJob($id)
    {
        $job = new AplineSimpleOrdersMigratorJob((int) $id);
        if (!Validate::isLoadedObject($job)) {
            $this->errors[] = $this->trans('To zadanie importu już nie istnieje.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }
        if ($job->status !== 'mapping') {
            $this->errors[] = $this->trans('To zadanie nie jest w fazie mapowania.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }

        return $job;
    }

    /**
     * Persist per-product mapping decisions posted from the mapping table.
     *
     * @return bool
     */
    private function processProductMappings()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = $this->loadMappingJob($id);
        if (!$job) {
            return false;
        }

        $decisions = Tools::getValue('pmap'); // [old_id => 'link'|'snapshot']
        $newIds = Tools::getValue('pnew');    // [old_id => id_product]
        if (!is_array($decisions)) {
            $decisions = [];
        }
        if (!is_array($newIds)) {
            $newIds = [];
        }

        $saved = 0;
        foreach ($decisions as $oldId => $choice) {
            $oldId = (string) $oldId;
            if ($choice === 'snapshot') {
                AsomMapping::set($id, 'product', $oldId, null, 'snapshot_only', 'Tylko migawka (wybór administratora)');
                ++$saved;
            } elseif ($choice === 'link') {
                $newId = isset($newIds[$oldId]) ? (int) $newIds[$oldId] : 0;
                if ($newId > 0) {
                    AsomMapping::set($id, 'product', $oldId, $newId, 'live_link', 'Powiązano przez administratora');
                    ++$saved;
                }
                // A "link" choice without a target is left unresolved on purpose.
            }
        }

        $this->confirmations[] = sprintf($this->trans('Zapisano decyzje o produktach: %d.', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $saved);
        $this->redirectToView($id);

        return true;
    }

    /**
     * Snapshot-only fallback for every still-unresolved product.
     *
     * @return bool
     */
    private function processSnapshotFallback()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = $this->loadMappingJob($id);
        if (!$job) {
            return false;
        }

        $mapper = new AsomProductMapper($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_');
        $n = $mapper->applySnapshotFallback();

        $this->confirmations[] = sprintf($this->trans('Produkty ustawione jako migawki: %d.', [], 'Modules.Aplinesimpleordersmigrator.Admin'), $n);
        $this->redirectToView($id);

        return true;
    }

    /**
     * Redirect back to a job's detail view.
     *
     * @param int $id
     */
    private function redirectToView($id)
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob')
            . '&id_asom_job=' . (int) $id . '&viewasom_job&conf=4'
        );
    }

    /**
     * Advance a validated, staged job to the mapping phase. Re-runs validation
     * so structure errors can never be bypassed by crafting the request.
     *
     * @return bool
     */
    private function processStartMapping()
    {
        $id = (int) Tools::getValue('id_asom_job');
        $job = new AplineSimpleOrdersMigratorJob($id);
        if (!Validate::isLoadedObject($job)) {
            $this->errors[] = $this->trans('To zadanie importu już nie istnieje.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }
        if ($job->status !== 'staged') {
            $this->errors[] = $this->trans('To zadanie nie jest gotowe do mapowania.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }

        $validator = new AsomValidator($id, apline_simple_orders_migrator::sandboxPrefix() . $id . '_');
        $verdict = $validator->validate((string) $job->dump_md5);
        if (!$verdict['can_proceed']) {
            $this->errors[] = $this->trans('Nie można kontynuować: w zrzucie brakuje wymaganych tabel lub kolumn.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }

        Db::getInstance()->update('asom_job', [
            'status' => 'mapping',
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_asom_job = ' . $id);

        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob')
            . '&id_asom_job=' . $id . '&viewasom_job&conf=4'
        );

        return true;
    }

    /**
     * Validate input, harden the dump file, then create the job row.
     * Rejects bad input (never silently fixes it); on failure the form stays
     * open and nothing is written.
     *
     * @return bool
     */
    private function processAddJob()
    {
        $name = trim((string) Tools::getValue('name'));
        $source = (string) Tools::getValue('dump_source');

        if ($name === '') {
            $this->errors[] = $this->trans('Pole „Nazwa zadania” jest wymagane.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $this->errors[] = $this->trans('Pole „Nazwa zadania” przekracza maksymalną długość 255 znaków.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        } elseif (!Validate::isGenericName($name)) {
            $this->errors[] = $this->trans('Pole „Nazwa zadania” zawiera niedozwolone znaki.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        }

        if (!in_array($source, ['upload', 'existing'], true)) {
            $this->errors[] = $this->trans('Wybierz źródło zrzutu.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        }

        $dumpDir = $this->module->getDumpDir();
        $storedName = null;
        $uploadedNow = false;

        if (!$this->errors) {
            if ($source === 'existing') {
                $storedName = $this->resolveExistingDump($dumpDir);
            } else {
                $storedName = $this->handleUpload($dumpDir);
                $uploadedNow = ($storedName !== null);
            }
        }

        if ($this->errors || $storedName === null) {
            // Keep the add form open with the errors shown.
            $this->display = 'add';

            return false;
        }

        $fullPath = $dumpDir . $storedName;

        $job = new AplineSimpleOrdersMigratorJob();
        $job->name = $name;
        $job->dump_filename = $storedName;
        $job->dump_size_bytes = (int) @filesize($fullPath);
        $job->dump_md5 = (string) @md5_file($fullPath);
        $job->source_db_prefix = 'ps_';
        $job->status = 'uploaded';
        $job->import_options = json_encode([]);

        if (!$job->save()) {
            // If we just uploaded the file but the row failed, clean it up.
            if ($uploadedNow && is_file($fullPath)) {
                @unlink($fullPath);
            }
            $this->errors[] = $this->trans('Nie udało się zapisać zadania importu. Spróbuj ponownie.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
            $this->display = 'add';

            return false;
        }

        $this->confirmations[] = $this->trans('Zadanie importu zostało utworzone.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminAplineSimpleOrdersMigratorJob') . '&conf=3');

        return true;
    }

    /**
     * Validate an FTP-placed file selected from the dropdown.
     *
     * @param string $dumpDir
     *
     * @return string|null stored base name, or null on error
     */
    private function resolveExistingDump($dumpDir)
    {
        $picked = basename((string) Tools::getValue('dump_existing'));

        if ($picked === '' || strtolower(pathinfo($picked, PATHINFO_EXTENSION)) !== 'sql') {
            $this->errors[] = $this->trans('Wybierz poprawny plik .sql z folderu zrzutów.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }
        if (!is_file($dumpDir . $picked)) {
            $this->errors[] = $this->trans('Wybrany plik zrzutu nie istnieje już w folderze zrzutów.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }
        if (!$this->sniffSql($dumpDir . $picked)) {
            $this->errors[] = $this->trans('Wybrany plik nie wygląda na zrzut SQL (nie znaleziono CREATE TABLE ani INSERT INTO).', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }

        return $picked;
    }

    /**
     * Validate and store an uploaded dump file with full hardening.
     *
     * @param string $dumpDir
     *
     * @return string|null stored base name, or null on error
     */
    private function handleUpload($dumpDir)
    {
        if (!isset($_FILES['dump_upload']) || !isset($_FILES['dump_upload']['error'])
            || $_FILES['dump_upload']['error'] === UPLOAD_ERR_NO_FILE) {
            $this->errors[] = $this->trans('Wybierz plik .sql do wgrania.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }

        $file = $_FILES['dump_upload'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = $this->trans('Wgrywanie pliku nie powiodło się. Plik może być większy, niż pozwala serwer.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            $this->errors[] = $this->trans('Nieprawidłowy typ pliku. Dozwolone są tylko zrzuty .sql.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }

        $maxBytes = (int) Configuration::get(apline_simple_orders_migrator::MAX_UPLOAD_BYTES_KEY);
        if ((int) $file['size'] <= 0) {
            $this->errors[] = $this->trans('Wgrany plik jest pusty.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }
        if ((int) $file['size'] > $maxBytes) {
            $this->errors[] = $this->trans('Plik jest za duży na wgranie z formularza. Umieść go w folderze zrzutów przez FTP i wybierz z listy.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }

        if (!$this->sniffSql($file['tmp_name'])) {
            $this->errors[] = $this->trans('Wgrany plik nie wygląda na zrzut SQL (nie znaleziono CREATE TABLE ani INSERT INTO).', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }

        // Sanitize the original name; never trust it for the path.
        $base = pathinfo((string) $file['name'], PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
        $base = ltrim((string) $base, '.');
        $base = str_replace('..', '', $base);
        if ($base === '') {
            $base = 'dump';
        }
        $base = mb_substr($base, 0, 80);

        $storedName = 'asom_' . uniqid('', true) . '_' . $base . '.sql';
        $dest = $dumpDir . $storedName;

        if (!is_dir($dumpDir)) {
            @mkdir($dumpDir, 0755, true);
        }
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            $this->errors[] = $this->trans('Nie udało się zapisać wgranego pliku. Sprawdź uprawnienia do folderu zrzutów.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return null;
        }
        @chmod($dest, 0640);

        return $storedName;
    }

    /**
     * Read the first kilobytes and confirm a basic SQL signature is present.
     *
     * @param string $path
     *
     * @return bool
     */
    private function sniffSql($path)
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $head = (string) fread($fh, 4096);
        @fclose($fh);

        $upper = strtoupper($head);
        foreach (self::SQL_SIGNATURES as $sig) {
            if (strpos($upper, $sig) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the sandbox tables for a job before deleting the row. The mapping
     * and log rows are removed automatically through the ON DELETE CASCADE FK.
     */
    public function processDelete()
    {
        $id = (int) Tools::getValue($this->identifier);
        if ($id) {
            $this->dropJobSandbox($id);
        }

        return parent::processDelete();
    }

    /**
     * Bulk delete: drop each job's sandbox first, then let the framework delete.
     */
    protected function processBulkDelete()
    {
        $ids = Tools::getValue($this->table . 'Box');
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $this->dropJobSandbox((int) $id);
            }
        }

        return parent::processBulkDelete();
    }

    /**
     * Drop every sandbox table belonging to a single job
     * (<_DB_PREFIX_>psmig_<id>_*). Safe to call when none exist.
     *
     * @param int $idJob
     */
    private function dropJobSandbox($idJob)
    {
        $idJob = (int) $idJob;
        if (!$idJob) {
            return;
        }
        try {
            $like = str_replace('_', '\\_', apline_simple_orders_migrator::sandboxPrefix() . $idJob . '_') . '%';
            $rows = Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL($like) . '\'');
            if (!is_array($rows) || !$rows) {
                return;
            }
            Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($rows as $row) {
                $name = (string) reset($row);
                if ($name !== '') {
                    Db::getInstance()->execute('DROP TABLE IF EXISTS `' . bqSQL($name) . '`');
                }
            }
            Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_orders_migrator: dropJobSandbox failed: ' . $e->getMessage(), 2);
        }
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        // Module BO assets are also injected via displayBackOfficeHeader; this
        // guarantees they load on the controller even if the hook is skipped.
        if ($this->module) {
            $this->addCSS($this->module->getAdminAssetUri('views/css/admin.css'));
            $this->addJS($this->module->getAdminAssetUri('views/js/admin.js'));
        }
    }
}
