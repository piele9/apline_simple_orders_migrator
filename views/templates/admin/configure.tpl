{*
 * APLINE Simple Orders Migrator module for PrestaShop 9.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 *}
<div class="panel">
    <h3><i class="icon-tasks"></i> {l s='Lista kontrolna przed startem' d='Modules.Aplinesimpleordersmigrator.Admin'}</h3>
    <p>{l s='To narzędzie importuje klientów i zamówienia ze zrzutu SQL sklepu PrestaShop 1.7.8 do tego sklepu PrestaShop 9. Przed pierwszym importem przejdź przez poniższą listę.' d='Modules.Aplinesimpleordersmigrator.Admin'}</p>
    <ul class="list-unstyled">
        <li>&#9744; {l s='Umieść pliki zrzutów SQL w folderze:' d='Modules.Aplinesimpleordersmigrator.Admin'} <code>{$asom_dump_path|escape:'html':'UTF-8'}</code> {l s='(przez FTP) albo wgraj mniejsze zrzuty z ekranu zadań importu.' d='Modules.Aplinesimpleordersmigrator.Admin'}</li>
        <li>&#9744; {l s='Zrób kopię zapasową bazy danych tego sklepu PrestaShop 9 przed importem.' d='Modules.Aplinesimpleordersmigrator.Admin'}</li>
        <li>&#9744; {l s='Upewnij się, że w PrestaShop 9 jest domyślny przewoźnik, domyślny moduł płatności (przelew bankowy lub czek), domyślne statusy zamówień i domyślna grupa klientów.' d='Modules.Aplinesimpleordersmigrator.Admin'}</li>
        <li>&#9744; {l s='Opcjonalnie: najpierw dodaj produkty do PrestaShop 9, aby pozycje zamówień dało się powiązać z produktami w sklepie (po EAN lub ręcznie).' d='Modules.Aplinesimpleordersmigrator.Admin'}</li>
    </ul>
</div>

<div class="panel">
    <h3><i class="icon-exchange"></i> {l s='Zadania importu' d='Modules.Aplinesimpleordersmigrator.Admin'}</h3>
    <p>{l s='Każdy import to osobne zadanie: wgraj lub wskaż zrzut, wczytaj go do tabel-piaskownic, zmapuj produkty, statusy, płatności i przewoźników, a następnie zaimportuj dane wsadami.' d='Modules.Aplinesimpleordersmigrator.Admin'}</p>
    <a class="btn btn-primary btn-lg apline-btn-duzy" href="{$asom_manage_url|escape:'html':'UTF-8'}">
        <i class="icon-list"></i> {l s='Zarządzaj zadaniami importu' d='Modules.Aplinesimpleordersmigrator.Admin'}
    </a>
</div>
