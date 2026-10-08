<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Admin-only data migration tool: imports customers and orders from a
 * PrestaShop 1.7.8.x SQL dump into PrestaShop 9.0.x. Streaming dump parser,
 * per-job sandbox tables, batched AJAX import. No front-end output.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class apline_simple_orders_migrator extends Module
{
    const DUMP_FOLDER_KEY = 'ASOM_DUMP_FOLDER';
    const MAX_UPLOAD_BYTES_KEY = 'ASOM_MAX_UPLOAD_BYTES';
    const BATCH_SIZE_KEY = 'ASOM_BATCH_SIZE';
    const KEEP_LOG_DAYS_KEY = 'ASOM_KEEP_LOG_DAYS';
    const REQUIRE_BACKUP_KEY = 'ASOM_REQUIRE_BACKUP_CONFIRMATION';

    const ADMIN_CONTROLLER = 'AdminAplineSimpleOrdersMigratorJob';

    /** Nazwa zakładki panelu (ukrytej) widoczna w nagłówku ekranu zadań importu. */
    const TAB_NAME = 'Migrator zamówień';

    /** Default values for the global configuration keys. */
    const DEFAULT_DUMP_FOLDER = 'dump/';
    const DEFAULT_MAX_UPLOAD_BYTES = 52428800; // 50 MB
    const DEFAULT_BATCH_SIZE = 50;
    const DEFAULT_KEEP_LOG_DAYS = 365;
    const DEFAULT_REQUIRE_BACKUP = 1;

    /**
     * Sandbox tables live inside the shop table namespace so they never
     * collide with other databases and uninstall can find them reliably.
     * Pattern: <_DB_PREFIX_>psmig_<job_id>_<original_table>.
     *
     * @return string
     */
    public static function sandboxPrefix()
    {
        return _DB_PREFIX_ . 'psmig_';
    }

    public function __construct()
    {
        $this->name = 'apline_simple_orders_migrator';
        $this->tab = 'administration';
        $this->version = '1.1.0';
        $this->author = 'Arkadiusz Pielechowski';
        $this->need_instance = false;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('APLINE Simple Orders Migrator – migracja klientów i zamówień', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        $this->description = $this->trans('Importuje klientów i zamówienia ze zrzutu SQL sklepu PrestaShop 1.7.8 do PrestaShop 9. Parser strumieniowy, tabele-piaskownice, mapowanie produktów, statusów, płatności i przewoźników, import wsadowy.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        $this->confirmUninstall = $this->trans('Czy na pewno chcesz odinstalować ten moduł? Zadania importu, mapowania, logi i wszystkie tabele-piaskownice zostaną usunięte. Wgrane pliki zrzutów pozostaną na serwerze.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
    }

    /**
     * @return string absolute path to the dump directory (with trailing slash)
     */
    public function getDumpDir()
    {
        $folder = (string) Configuration::get(self::DUMP_FOLDER_KEY);
        if ($folder === '') {
            $folder = self::DEFAULT_DUMP_FOLDER;
        }
        // Keep it inside the module folder; ignore any absolute/relative escape.
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $folder = str_replace('..', '', $folder);

        return _PS_MODULE_DIR_ . $this->name . '/' . ($folder !== '' ? $folder . '/' : '');
    }

    /**
     * @return bool whether the dump directory exists and is writable
     */
    public function isDumpDirWritable()
    {
        $dir = $this->getDumpDir();

        return is_dir($dir) && is_writable($dir);
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->installDb()
            || !$this->installConfiguration()
            || !$this->installDumpFolder()
            || !$this->installHooks()
            || !$this->installTab()
        ) {
            // Roll back to a clean state so the shop is never left half-installed.
            $this->uninstall();
            $this->_errors[] = $this->trans('Instalacja nie powiodła się i została wycofana. Sprawdź uprawnienia do folderów i spróbuj ponownie.', [], 'Modules.Aplinesimpleordersmigrator.Admin');

            return false;
        }

        return true;
    }

    public function uninstall()
    {
        // Each step is idempotent; uninstall must not fail because something is already gone.
        $this->uninstallTab();
        $this->dropSandboxTables();
        $this->dropConstantTables();

        Configuration::deleteByName(self::DUMP_FOLDER_KEY);
        Configuration::deleteByName(self::MAX_UPLOAD_BYTES_KEY);
        Configuration::deleteByName(self::BATCH_SIZE_KEY);
        Configuration::deleteByName(self::KEEP_LOG_DAYS_KEY);
        Configuration::deleteByName(self::REQUIRE_BACKUP_KEY);

        // Dump files in dump/ are intentionally preserved (admin may want them).

        return parent::uninstall();
    }

    /**
     * Create the three persistent tables (job / mapping / log).
     * InnoDB is required (not _MYSQL_ENGINE_) because mapping and log rows
     * depend on job rows through ON DELETE CASCADE foreign keys.
     *
     * @return bool
     */
    private function installDb()
    {
        $sqlJob = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'asom_job` (
            `id_asom_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `dump_filename` VARCHAR(255) NOT NULL,
            `dump_size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
            `dump_md5` VARCHAR(32) DEFAULT NULL,
            `source_ps_version` VARCHAR(32) DEFAULT NULL,
            `source_db_prefix` VARCHAR(32) DEFAULT \'ps_\',
            `status` ENUM(\'uploaded\',\'parsing\',\'staged\',\'mapping\',\'importing\',\'completed\',\'failed\',\'cleaned\') NOT NULL DEFAULT \'uploaded\',
            `import_options` TEXT DEFAULT NULL,
            `counts_source` TEXT DEFAULT NULL,
            `counts_imported` TEXT DEFAULT NULL,
            `counts_skipped` TEXT DEFAULT NULL,
            `counts_failed` TEXT DEFAULT NULL,
            `error_summary` TEXT DEFAULT NULL,
            `started_at` DATETIME DEFAULT NULL,
            `finished_at` DATETIME DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_asom_job`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

        $sqlMapping = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'asom_mapping` (
            `id_asom_mapping` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_asom_job` INT UNSIGNED NOT NULL,
            `entity_type` ENUM(\'customer\',\'address\',\'order\',\'product\',\'category\',\'carrier\',\'payment_module\',\'order_state\',\'currency\',\'lang\') NOT NULL,
            `old_id` VARCHAR(64) NOT NULL,
            `new_id` VARCHAR(64) DEFAULT NULL,
            `mapping_strategy` ENUM(\'live_link\',\'snapshot_only\',\'auto_ean\',\'fallback\',\'skipped\') NOT NULL,
            `note` VARCHAR(512) DEFAULT NULL,
            PRIMARY KEY (`id_asom_mapping`),
            UNIQUE KEY `uq_job_entity_old` (`id_asom_job`,`entity_type`,`old_id`),
            KEY `idx_lookup` (`id_asom_job`,`entity_type`,`new_id`),
            CONSTRAINT `fk_asom_mapping_job` FOREIGN KEY (`id_asom_job`)
                REFERENCES `' . _DB_PREFIX_ . 'asom_job` (`id_asom_job`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

        $sqlLog = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'asom_log` (
            `id_asom_log` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_asom_job` INT UNSIGNED NOT NULL,
            `severity` ENUM(\'debug\',\'info\',\'warning\',\'error\') NOT NULL DEFAULT \'info\',
            `phase` VARCHAR(64) NOT NULL,
            `entity_type` VARCHAR(64) DEFAULT NULL,
            `entity_old_id` VARCHAR(64) DEFAULT NULL,
            `message` TEXT NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_asom_log`),
            KEY `idx_job_severity` (`id_asom_job`,`severity`),
            KEY `idx_phase` (`id_asom_job`,`phase`),
            CONSTRAINT `fk_asom_log_job` FOREIGN KEY (`id_asom_job`)
                REFERENCES `' . _DB_PREFIX_ . 'asom_job` (`id_asom_job`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sqlJob)
            && Db::getInstance()->execute($sqlMapping)
            && Db::getInstance()->execute($sqlLog);
    }

    /**
     * @return bool
     */
    private function installConfiguration()
    {
        return Configuration::updateValue(self::DUMP_FOLDER_KEY, self::DEFAULT_DUMP_FOLDER)
            && Configuration::updateValue(self::MAX_UPLOAD_BYTES_KEY, self::DEFAULT_MAX_UPLOAD_BYTES)
            && Configuration::updateValue(self::BATCH_SIZE_KEY, self::DEFAULT_BATCH_SIZE)
            && Configuration::updateValue(self::KEEP_LOG_DAYS_KEY, self::DEFAULT_KEEP_LOG_DAYS)
            && Configuration::updateValue(self::REQUIRE_BACKUP_KEY, self::DEFAULT_REQUIRE_BACKUP);
    }

    /**
     * Ensure the dump folder exists and is protected from public HTTP access.
     *
     * @return bool
     */
    private function installDumpFolder()
    {
        $dir = $this->getDumpDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // It is fine if the folder already shipped with these guards.
        return is_dir($dir);
    }

    /**
     * @return bool
     */
    private function installHooks()
    {
        return $this->registerHook('displayBackOfficeHeader');
    }

    /**
     * @return bool
     */
    private function installTab()
    {
        if (Tab::getIdFromClassName(self::ADMIN_CONTROLLER)) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = self::ADMIN_CONTROLLER;
        $tab->module = $this->name;
        $tab->active = 1;
        // Hidden tab (no visible parent): reached from the module config page.
        $tab->id_parent = -1;
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = self::TAB_NAME;
        }

        return (bool) $tab->add();
    }

    /**
     * @return bool
     */
    private function uninstallTab()
    {
        $id = (int) Tab::getIdFromClassName(self::ADMIN_CONTROLLER);
        if (!$id) {
            return true;
        }

        try {
            $tab = new Tab($id);

            return (bool) $tab->delete();
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Drop every sandbox table created for any job. Foreign-key checks are
     * disabled so copied dump constraints never block the drop.
     */
    private function dropSandboxTables()
    {
        try {
            $like = str_replace('_', '\\_', self::sandboxPrefix()) . '%';
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
            // Best effort: a missing table must never make uninstall fail.
        }
    }

    /**
     * Drop the three persistent tables. Children first, then parent; FK checks
     * disabled as a belt-and-braces guard.
     */
    private function dropConstantTables()
    {
        try {
            Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 0');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'asom_log`');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'asom_mapping`');
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'asom_job`');
            Db::getInstance()->execute('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable $e) {
            // Idempotent uninstall: ignore anything already gone.
        }
    }

    /**
     * Module configuration URL (so AdminController screens can link back).
     *
     * @return string
     */
    public function getConfigUrl()
    {
        return $this->context->link->getAdminLink('AdminModules', true, [], [
            'configure' => $this->name,
            'module_name' => $this->name,
        ]);
    }

    /**
     * Adres zasobu panelu (CSS/JS) z numerem wersji, żeby po aktualizacji
     * przeglądarka nie trzymała starego pliku.
     *
     * @param string $relativePath np. 'views/css/admin.css'
     *
     * @return string
     */
    public function getAdminAssetUri($relativePath)
    {
        return $this->_path . $relativePath . '?v=' . $this->version;
    }

    public function getContent()
    {
        $output = '';

        if (isset($this->context->controller) && method_exists($this->context->controller, 'addCSS')) {
            $this->context->controller->addCSS($this->getAdminAssetUri('views/css/admin.css'));
        }

        if (Tools::isSubmit('submitAsomConfig')) {
            $output .= $this->postProcessConfig();
        }

        if (!$this->isDumpDirWritable()) {
            $output .= $this->displayWarning($this->trans('Folder zrzutów nie jest zapisywalny: %s. Wgrywanie i analiza zrzutów nie zadziałają, dopóki nie poprawisz jego uprawnień (np. chmod 0775).', [$this->getDumpDir()], 'Modules.Aplinesimpleordersmigrator.Admin'));
        }

        $manageUrl = $this->context->link->getAdminLink(self::ADMIN_CONTROLLER);

        $this->context->smarty->assign([
            'asom_manage_url' => $manageUrl,
            'asom_dump_path' => 'modules/' . $this->name . '/' . trim(str_replace(_PS_MODULE_DIR_ . $this->name . '/', '', $this->getDumpDir()), '/') . '/',
        ]);
        $output .= $this->display(__FILE__, 'views/templates/admin/configure.tpl');

        return $output . $this->renderConfigForm() . $this->renderLikeBox() . $this->renderAplineFooter();
    }

    /**
     * Validate and persist the global configuration. Rejects bad input (never
     * silently coerces): non-positive numbers and overlong folder names fail.
     *
     * @return string confirmation or error HTML
     */
    private function postProcessConfig()
    {
        $errors = [];

        $folder = trim((string) Tools::getValue(self::DUMP_FOLDER_KEY));
        $maxBytes = (string) Tools::getValue(self::MAX_UPLOAD_BYTES_KEY);
        $batch = (string) Tools::getValue(self::BATCH_SIZE_KEY);
        $keepDays = (string) Tools::getValue(self::KEEP_LOG_DAYS_KEY);
        $requireBackup = (int) Tools::getValue(self::REQUIRE_BACKUP_KEY) ? 1 : 0;

        if ($folder === '' || mb_strlen($folder) > 255) {
            $errors[] = $this->trans('Nazwa folderu zrzutów jest wymagana i nie może przekraczać 255 znaków.', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        } elseif (strpos($folder, '..') !== false || strpos($folder, '/') === 0 || strpos($folder, '\\') !== false || preg_match('#[a-zA-Z]:#', $folder)) {
            $errors[] = $this->trans('Folder zrzutów musi być prostą ścieżką względną wewnątrz modułu (bez „..” i bez ścieżek bezwzględnych).', [], 'Modules.Aplinesimpleordersmigrator.Admin');
        }

        $numericFields = [
            $this->trans('Maksymalny rozmiar wgrywanego pliku (bajty)', [], 'Modules.Aplinesimpleordersmigrator.Admin') => $maxBytes,
            $this->trans('Rozmiar wsadu (rekordów na jedno wywołanie AJAX)', [], 'Modules.Aplinesimpleordersmigrator.Admin') => $batch,
            $this->trans('Przechowuj logi (dni)', [], 'Modules.Aplinesimpleordersmigrator.Admin') => $keepDays,
        ];
        foreach ($numericFields as $label => $value) {
            if (!ctype_digit($value) || (int) $value < 1) {
                $errors[] = $this->trans('Pole „%s” musi być dodatnią liczbą całkowitą.', [$label], 'Modules.Aplinesimpleordersmigrator.Admin');
            }
        }

        if ($errors) {
            return $this->displayError(implode('<br>', $errors));
        }

        Configuration::updateValue(self::DUMP_FOLDER_KEY, $folder);
        Configuration::updateValue(self::MAX_UPLOAD_BYTES_KEY, (int) $maxBytes);
        Configuration::updateValue(self::BATCH_SIZE_KEY, (int) $batch);
        Configuration::updateValue(self::KEEP_LOG_DAYS_KEY, (int) $keepDays);
        Configuration::updateValue(self::REQUIRE_BACKUP_KEY, $requireBackup);

        return $this->displayConfirmation($this->trans('Ustawienia zostały zapisane.', [], 'Modules.Aplinesimpleordersmigrator.Admin'));
    }

    /**
     * @return string
     */
    private function renderConfigForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Ustawienia ogólne', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Folder zrzutów (względem modułu)', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                        'name' => self::DUMP_FOLDER_KEY,
                        'desc' => $this->trans('Folder, w którym leżą pliki zrzutów SQL (wgrane przez FTP lub z formularza). Domyślnie: dump/', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Maksymalny rozmiar wgrywanego pliku (bajty)', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                        'name' => self::MAX_UPLOAD_BYTES_KEY,
                        'desc' => $this->trans('Maksymalny rozmiar pliku wgrywanego z formularza. Większe zrzuty umieść w folderze zrzutów przez FTP. Domyślnie: 52428800 (50 MB).', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Rozmiar wsadu (rekordów na jedno wywołanie AJAX)', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                        'name' => self::BATCH_SIZE_KEY,
                        'desc' => $this->trans('Tyle rekordów (klientów, adresów, zamówień itd.) jest importowanych w jednym żądaniu AJAX. Domyślnie: 50.', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Przechowuj logi (dni)', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                        'name' => self::KEEP_LOG_DAYS_KEY,
                        'desc' => $this->trans('Wpisy logu starsze niż podana liczba dni są usuwane automatycznie (przy otwarciu ekranów zadań importu). Domyślnie: 365.', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Wymagaj potwierdzeń przed importem', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                        'desc' => $this->trans('Przed startem importu trzeba zaznaczyć potwierdzenia: kopia zapasowa, nowe konta i zamówienia, e-maile do klientów.', [], 'Modules.Aplinesimpleordersmigrator.Admin'),
                        'name' => self::REQUIRE_BACKUP_KEY,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'backup_on', 'value' => 1, 'label' => $this->trans('Tak', [], 'Admin.Global')],
                            ['id' => 'backup_off', 'value' => 0, 'label' => $this->trans('Nie', [], 'Admin.Global')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Zapisz ustawienia', [], 'Admin.Actions'),
                    'class' => 'btn btn-primary btn-lg apline-btn-duzy pull-right',
                    'icon' => 'icon-save',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitAsomConfig';
        $helper->fields_value = [
            self::DUMP_FOLDER_KEY => Configuration::get(self::DUMP_FOLDER_KEY),
            self::MAX_UPLOAD_BYTES_KEY => Configuration::get(self::MAX_UPLOAD_BYTES_KEY),
            self::BATCH_SIZE_KEY => Configuration::get(self::BATCH_SIZE_KEY),
            self::KEEP_LOG_DAYS_KEY => Configuration::get(self::KEEP_LOG_DAYS_KEY),
            self::REQUIRE_BACKUP_KEY => Configuration::get(self::REQUIRE_BACKUP_KEY),
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Author credit with a link to https://pielechowski.pl, shown on the
     * configuration page. The module is MIT-licensed: the credit is kept by
     * default, it is not a license requirement.
     *
     * @return string
     */
    public function renderAplineFooter()
    {
        return '
        <style>
            .apline-credit { margin-top: 24px; font-size: 12px; opacity: 0.9; }
            .apline-credit a { font-weight: 600; }
        </style>
        <div class="apline-credit">
            ' . $this->trans('Moduł stworzony przez', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '
            <a href="https://pielechowski.pl" target="_blank" rel="noopener noreferrer">PIELECHOWSKI.PL</a>
        </div>';
    }

    /**
     * Subtle "need custom development?" box shown on the configuration page.
     *
     * @return string
     */
    public function renderLikeBox()
    {
        return '
        <div class="panel">
            <h3>&#9749; ' . $this->trans('Podoba Ci się ten moduł?', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</h3>
            <p>' . $this->trans('Potrzebujesz rozwiązań dla PrestaShop na zamówienie, optymalizacji wydajności albo integracji?', [], 'Modules.Aplinesimpleordersmigrator.Admin') . '</p>
            <a class="btn btn-default" href="https://pielechowski.pl" target="_blank" rel="noopener noreferrer">&#8594; PIELECHOWSKI.PL</a>
        </div>';
    }

    /**
     * Load module back-office assets, only on this module's own screens.
     * Wrapped so a failure here never breaks an unrelated admin page.
     */
    public function hookDisplayBackOfficeHeader()
    {
        try {
            $controller = Tools::getValue('controller');
            $configure = Tools::getValue('configure');
            $onModuleScreen = ($controller === self::ADMIN_CONTROLLER)
                || ($configure === $this->name);

            if (!$onModuleScreen) {
                return;
            }

            if (method_exists($this->context->controller, 'addCSS')) {
                $this->context->controller->addCSS($this->getAdminAssetUri('views/css/admin.css'));
            }
            if (method_exists($this->context->controller, 'addJS')) {
                $this->context->controller->addJS($this->getAdminAssetUri('views/js/admin.js'));
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_orders_migrator: ' . $e->getMessage(), 3);
        }
    }
}
