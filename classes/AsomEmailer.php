<?php
/**
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * Sends migrated customers a password-reset email so they can claim their new
 * account (their password was deliberately randomized on import). Runs as a
 * resumable, throttled follow-up phase after the import completes: a small
 * batch per AJAX call so large customer sets do not hammer the SMTP server.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class AsomEmailer
{
    /** Emails per AJAX call. */
    const BATCH = 10;

    /** @var int */
    private $idJob;
    /** @var Module */
    private $module;
    /** @var Db */
    private $db;

    /**
     * @param int    $idJob
     * @param Module $module
     */
    public function __construct($idJob, $module)
    {
        $this->idJob = (int) $idJob;
        $this->module = $module;
        $this->db = Db::getInstance();
    }

    /**
     * @param string $severity
     * @param string $message
     * @param string $oldId
     */
    private function log($severity, $message, $oldId = null)
    {
        try {
            $this->db->insert('asom_log', [
                'id_asom_job' => $this->idJob,
                'severity' => pSQL($severity),
                'phase' => 'send_emails',
                'entity_type' => 'customer',
                'entity_old_id' => $oldId === null ? null : pSQL((string) $oldId),
                'message' => pSQL(Tools::substr((string) $message, 0, 4000)),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // logging never breaks sending
        }
    }

    /**
     * New customer ids imported by this job (from asom_mapping).
     *
     * @return int[] new id_customer values
     */
    private function importedCustomerNewIds()
    {
        $rows = $this->db->executeS(
            'SELECT new_id FROM `' . _DB_PREFIX_ . 'asom_mapping`
             WHERE id_asom_job = ' . $this->idJob . '
               AND entity_type = \'customer\'
               AND mapping_strategy = \'live_link\'
               AND new_id IS NOT NULL
             ORDER BY CAST(new_id AS UNSIGNED) ASC'
        );
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $out[] = (int) $r['new_id'];
            }
        }

        return $out;
    }

    /**
     * Load the email cursor (last sent customer id + sent count).
     *
     * @return array{last_id:int,sent:int}
     */
    private function loadCursor()
    {
        $raw = $this->db->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $this->idJob);
        $opts = json_decode((string) $raw, true);
        $opts = is_array($opts) ? $opts : [];
        $c = isset($opts['_emails']) && is_array($opts['_emails']) ? $opts['_emails'] : [];

        return [
            'last_id' => isset($c['last_id']) ? (int) $c['last_id'] : 0,
            'sent' => isset($c['sent']) ? (int) $c['sent'] : 0,
        ];
    }

    /**
     * @param array $cursor
     */
    private function saveCursor(array $cursor)
    {
        $raw = $this->db->getValue('SELECT import_options FROM `' . _DB_PREFIX_ . 'asom_job` WHERE id_asom_job = ' . $this->idJob);
        $opts = json_decode((string) $raw, true);
        $opts = is_array($opts) ? $opts : [];
        $opts['_emails'] = $cursor;
        $this->db->update('asom_job', ['import_options' => pSQL(json_encode($opts))], 'id_asom_job = ' . $this->idJob);
    }

    /**
     * Total customers eligible for a reset email.
     *
     * @return int
     */
    public function total()
    {
        return count($this->importedCustomerNewIds());
    }

    /**
     * Send one throttled batch of reset emails.
     *
     * @return array {done, sent, total, percent, message}
     */
    public function sendBatch()
    {
        $all = $this->importedCustomerNewIds();
        $total = count($all);
        if ($total === 0) {
            return ['success' => true, 'done' => true, 'sent' => 0, 'total' => 0, 'percent' => 100, 'message' => 'Brak klientów do wysłania e-maili.'];
        }

        $cursor = $this->loadCursor();
        $sentThisCall = 0;

        foreach ($all as $idCustomer) {
            if ($idCustomer <= $cursor['last_id']) {
                continue;
            }
            if ($sentThisCall >= self::BATCH) {
                break;
            }
            $cursor['last_id'] = $idCustomer;
            try {
                if ($this->sendOne($idCustomer)) {
                    ++$cursor['sent'];
                }
            } catch (\Throwable $e) {
                $this->log('warning', 'Nie udało się wysłać e-maila: ' . $e->getMessage(), (string) $idCustomer);
            }
            ++$sentThisCall;
            // Gentle throttle between messages.
            usleep(100000); // 100 ms
        }

        $this->saveCursor($cursor);

        $processed = 0;
        foreach ($all as $idCustomer) {
            if ($idCustomer <= $cursor['last_id']) {
                ++$processed;
            }
        }
        $done = ($processed >= $total);
        $percent = $total > 0 ? (int) floor(($processed / $total) * 100) : 100;

        if ($done) {
            $this->log('info', 'Wysyłka e-maili z linkiem do ustawienia hasła zakończona. Wysłano: ' . $cursor['sent'] . ' z ' . $total . '.');
        }

        return [
            'success' => true,
            'done' => $done,
            'sent' => $cursor['sent'],
            'total' => $total,
            'percent' => $percent,
            'message' => 'Wysyłanie e-maili (' . $processed . ' z ' . $total . ')...',
        ];
    }

    /**
     * Generate a reset token and send the migration email to one customer.
     *
     * @param int $idCustomer
     *
     * @return bool
     */
    private function sendOne($idCustomer)
    {
        $customer = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($customer) || !$customer->email) {
            return false;
        }

        // The PrestaShop password page identifies the customer by secure_key and
        // checks the one-time reset token separately (see PasswordController).
        // Customers imported without a secure_key get one, like Customer::add().
        if (!$customer->secure_key) {
            $customer->secure_key = md5(uniqid((string) mt_rand(0, mt_getrandmax()), true));
        }

        // PS native password-reset token with its validity window.
        $customer->stampResetPasswordToken();
        $customer->update();
        $resetToken = $customer->getValidResetPasswordToken();
        if (!$resetToken) {
            return false;
        }

        $idLang = (int) $customer->id_lang ? (int) $customer->id_lang : (int) Configuration::get('PS_LANG_DEFAULT');

        $resetUrl = $this->context()->link->getPageLink(
            'password',
            true,
            $idLang,
            'token=' . $customer->secure_key . '&id_customer=' . (int) $customer->id . '&reset_token=' . $resetToken
        );
        $shopName = Configuration::get('PS_SHOP_NAME');

        $templateVars = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{email}' => $customer->email,
            '{shop_name}' => $shopName,
            '{reset_url}' => $resetUrl,
        ];

        $mailDir = _PS_MODULE_DIR_ . $this->module->name . '/mails/';

        return (bool) Mail::Send(
            $idLang,
            'account_migrated',
            $this->mailSubject(),
            $templateVars,
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            null,
            null,
            $mailDir
        );
    }

    /**
     * @return string
     */
    private function mailSubject()
    {
        return 'Twoje konto zostało przeniesione — ustaw nowe hasło';
    }

    /**
     * @return Context
     */
    private function context()
    {
        return Context::getContext();
    }
}
