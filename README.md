# APLINE Simple Orders Migrator — migracja klientów i zamówień do PrestaShop 9

![PrestaShop 9](https://img.shields.io/badge/PrestaShop-9.x-DF0067) ![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4) ![Wersja](https://img.shields.io/badge/wersja-1.1.0-2ea44f) ![Licencja](https://img.shields.io/badge/licencja-MIT-blue)

Moduł przenosi **klientów, adresy i zamówienia** ze starego sklepu **PrestaShop 1.7.8** do nowego sklepu **PrestaShop 9** na podstawie jednego zrzutu bazy SQL. Działa wyłącznie w panelu administracyjnym, nie dodaje nic na stronie sklepu i nie korzysta z zewnętrznych usług — dane zostają na Twoim serwerze.

> To wyspecjalizowane narzędzie, a nie uniwersalny migrator. Obsługuje jedną ścieżkę: PrestaShop 1.7.8.x → PrestaShop 9.x, dla klientów, adresów, zamówień, pozycji zamówień, historii zamówień i (opcjonalnie) wiadomości do zamówień. Produktów, kategorii, zdjęć ani ustawień sklepu moduł nie przenosi — produkty najpierw wczytaj natywnym importem CSV.

## Funkcje

- Parser zrzutów SQL czytający plik strumieniowo — duże zrzuty nie wyczerpują pamięci PHP; analiza przebiega w kawałkach i da się ją wznowić.
- Automatyczne wykrywanie prefiksu tabel źródła i wersji PrestaShop w zrzucie oraz sprawdzenie wymaganych tabel i kolumn przed importem.
- Osobne tabele-piaskownice dla każdego zadania — dane sklepu nie są ruszane, dopóki nie klikniesz „Rozpocznij import”.
- Trójwarstwowe mapowanie produktów: automatyczne dopasowanie po EAN, ręczne powiązanie z wyszukiwarką produktów albo „migawka” (pozycja zamówienia zachowuje nazwę i cenę, bez powiązania z produktem w sklepie).
- Mapowanie statusów zamówień, modułów płatności, przewoźników, walut i języków z podpowiedziami; brak waluty użytej w zamówieniach blokuje import.
- Import wsadowy z paskiem postępu, transakcją na wsad i izolacją błędów — pojedynczy błędny rekord trafia do logu i nie przerywa całego importu.
- Pomijanie duplikatów: klienci wg adresu e-mail, zamówienia wg referencji.
- Opcjonalna wysyłka klientom e-maili z linkiem do ustawienia hasła (stare hasła nie są przenoszone).
- Raport zakończenia (zaimportowano / pominięto / błędy), log importu z filtrami i stronicowaniem, czyszczenie piaskownicy, ponowienie importu, usuwanie pliku zrzutu.
- Polski panel z dużymi przyciskami najważniejszych akcji.

## Wymagania

- PrestaShop 9.x, PHP 8.1 lub nowszy (zgodny z wymaganiami użytej wersji PrestaShop).
- Baza MySQL/MariaDB z tabelami InnoDB (moduł zakłada własne tabele z kluczami obcymi).
- Zrzut SQL bazy sklepu PrestaShop 1.7.8.x w standardowym formacie mysqldump / phpMyAdmin (jedna instrukcja `INSERT` w wierszu, instrukcje zakończone średnikiem).
- Prawo zapisu do folderu `dump/` w katalogu modułu.
- Sklep docelowy z domyślnym przewoźnikiem, domyślnym modułem płatności (przelew bankowy lub czek), domyślnymi statusami zamówień i domyślną grupą klientów.

## Instalacja

1. Pobierz `apline_simple_orders_migrator.zip` z zakładki [Releases](../../releases/latest).
2. Panel PrestaShop → Moduły → Menedżer modułów → „Załaduj moduł” → wskaż ZIP.
3. Kliknij „Konfiguruj”.

Instalacja z Gita: sklonuj repozytorium do `modules/apline_simple_orders_migrator` (nazwa folderu musi być równa nazwie modułu).

Instalacja tworzy trzy tabele (`asom_job`, `asom_mapping`, `asom_log`), rejestruje hook panelu `displayBackOfficeHeader` i dodaje ukrytą zakładkę „Migrator zamówień”, do której prowadzi przycisk na stronie konfiguracji.

> **Zabezpiecz folder `dump/`.** Zrzut bazy zawiera dane osobowe klientów. Folder ma plik `.htaccess` blokujący dostęp przez HTTP na serwerze Apache; na nginx musisz zablokować dostęp do `modules/apline_simple_orders_migrator/dump/` w konfiguracji serwera. Po imporcie usuń plik zrzutu przyciskiem „Usuń plik zrzutu”.

## Konfiguracja

### Ustawienia ogólne

Strona konfiguracji modułu (sekcja „Ustawienia ogólne”), przycisk „Zapisz ustawienia”:

| Pole | Domyślnie | Znaczenie |
|---|---|---|
| Folder zrzutów (względem modułu) | `dump/` | Folder z plikami `.sql`; prosta ścieżka względna wewnątrz modułu (bez `..` i ścieżek bezwzględnych) |
| Maksymalny rozmiar wgrywanego pliku (bajty) | `52428800` (50 MB) | Limit dla pliku wgrywanego z formularza; większe zrzuty wgraj przez FTP |
| Rozmiar wsadu (rekordów na jedno wywołanie AJAX) | `50` | Tyle rekordów (klientów, adresów, zamówień itd.) jest importowanych w jednym żądaniu |
| Przechowuj logi (dni) | `365` | Wpisy logu starsze niż podana liczba dni są usuwane przy otwarciu ekranów zadań importu |
| Wymagaj potwierdzeń przed importem | Tak | Przed startem importu trzeba zaznaczyć potwierdzenia (kopia zapasowa, nowe konta i zamówienia, e-maile) |

### Przebieg importu krok po kroku

1. **Przygotowanie.** Zrób kopię zapasową bazy sklepu PrestaShop 9. Opcjonalnie wczytaj najpierw produkty (natywny import CSV), żeby pozycje zamówień dało się powiązać z produktami po EAN.
2. **Zadanie importu.** Na stronie konfiguracji kliknij „Zarządzaj zadaniami importu”, a potem „Nowe zadanie importu”. Wpisz *Nazwę zadania* i wybierz *Źródło zrzutu*: „Wgraj z komputera” (pole *Wgraj zrzut .sql*) albo „Wybierz plik umieszczony wcześniej w folderze zrzutów (przez FTP)” (pole *Istniejący plik zrzutu*). Kliknij „Utwórz zadanie importu”.
3. **Krok 1 — Analiza zrzutu.** Otwórz zadanie (ikona podglądu na liście) i kliknij „Wczytaj zrzut do piaskownicy”. Pasek postępu pokazuje przebieg; po zamknięciu strony analiza wznowi się po powrocie.
4. **Krok 2 — Podgląd i walidacja.** Sprawdź wykrytą wersję PrestaShop i liczniki (klienci, adresy, zamówienia, pozycje, historia, wiadomości, produkty). Błędy strukturalne blokują dalszy krok, ostrzeżenia (np. wersja inna niż 1.7.x, ten sam zrzut użyty już w innym zadaniu) nie. Kliknij „Skonfiguruj mapowania”.
5. **Krok 3 — Mapowanie produktów.** Produkty z EAN są dopasowywane automatycznie. Pozostałe powiąż ręcznie (pole *Powiąż z produktem*: wpisz co najmniej 2 znaki nazwy, referencji, EAN lub MPN) albo wybierz *Tylko migawka*. „Zapisz decyzje o produktach” zapisuje wybory; „Zastosuj migawki dla nierozstrzygniętych” ustawia migawkę dla wszystkich pozostałych naraz.
6. **Krok 4 — Statusy zamówień, płatności, przewoźnicy, waluty i języki.** Zaakceptuj podpowiedzi lub zmień je w kolumnie *Mapuj na (ten sklep)* i kliknij „Zapisz mapowania”. Waluta użyta w zamówieniach musi istnieć w sklepie (Międzynarodowe → Lokalizacja → Waluty), inaczej import jest zablokowany.
7. **Krok 5 — Potwierdzenie i import.** Zaznacz potwierdzenia, wybierz zakres (*Adresy*, *Historia zamówień*, *Wiadomości do zamówień*, *Wyślij nowym klientom e-maile z linkiem do ustawienia hasła*, *Pomijaj zduplikowane zamówienia*) i kliknij „Rozpocznij import”. Klienci, zamówienia z pozycjami i pomijanie zduplikowanych klientów są zawsze włączone.
8. **Krok 6 — Import.** Trzymaj stronę otwartą; po odświeżeniu import wznowi się od miejsca przerwania.
9. **Po imporcie.** Raport pokazuje liczby zaimportowanych, pominiętych i błędnych rekordów. Jeśli włączono e-maile, kliknij „Wyślij e-maile z linkiem do ustawienia hasła”. Przejrzyj *Log importu*, a gdy wszystko się zgadza, użyj „Wyczyść tabele-piaskownice” i „Usuń plik zrzutu”.

### Wiadomość e-mail do klientów

Wiadomość wysyłana jest w partiach po 10 sztuk z krótką przerwą między wiadomościami, tylko do klientów utworzonych przez dane zadanie. Zawiera link do ustawienia hasła (standardowy mechanizm resetu hasła PrestaShop) ważny przez czas ustawiony w PrestaShop (domyślnie 24 godziny); po jego upływie klient użyje na stronie logowania opcji „Nie pamiętasz hasła?”. Treść zmienisz w `mails/pl/account_migrated.html` i `mails/pl/account_migrated.txt` (temat wiadomości jest ustawiony w kodzie, w `classes/AsomEmailer.php`); dostępne zmienne: `{firstname}`, `{lastname}`, `{email}`, `{shop_name}`, `{reset_url}`.

PrestaShop wybiera szablon wg kolejności: język klienta → domyślny język sklepu → `en`. Katalog `mails/en/` zawiera dlatego kopię polskiego szablonu jako awaryjny; jeśli prowadzisz sklep w innym języku, przetłumacz oba katalogi albo dodaj katalog z kodem języka (np. `mails/de/`).

## Aktualizacja

Wgraj ZIP nowej wersji tak jak przy instalacji — PrestaShop uruchomi skrypty z `upgrade/` i zachowa ustawienia, zadania importu, mapowania oraz logi. Aktualizacja do 1.1.0 ustawia polską nazwę zakładki panelu; ustawienia i dane zostają bez zmian. Nie odinstalowuj modułu w celu aktualizacji.

## Odinstalowanie

Odinstalowanie usuwa tabele modułu (`asom_job`, `asom_mapping`, `asom_log`), wszystkie tabele-piaskownice (`<prefiks>psmig_*`), ustawienia (`ASOM_*`) oraz zakładkę panelu. **Nie cofa zaimportowanych danych** — klienci i zamówienia zostają w sklepie. Pliki zrzutów z folderu `dump/` pozostają na serwerze; usuń je ręcznie, jeśli nie są już potrzebne. Usunięcie zadania z listy również nie cofa importu.

## Jak to działa

- **Piaskownica.** Parser wczytuje do tabel `<prefiks>psmig_<id zadania>_<tabela>` wybrane tabele zrzutu: klientów, adresy, zamówienia, pozycje, historię, wiadomości, produkty, statusy, przewoźników, moduły, waluty, języki, grupy i zapisy do newslettera. Zapisy do newslettera są tylko liczone — moduł ich nie importuje. Z instrukcji `CREATE TABLE` usuwane są klucze obce, a wszystko inne zostaje nietknięte.
- **Import.** Dla każdej tabeli kopiowane są kolumny występujące zarówno w zrzucie, jak i w tabeli sklepu PrestaShop 9 (kolumny usunięte w 9 są pomijane, nowe dostają wartość domyślną), a klucze obce są podmieniane na podstawie mapowań. Zamówienia trafiają do bazy jako historyczne zapisy — bez zmian stanów magazynowych, bez e-maili i bez uruchamiania procesu zamówienia.
- **Klienci.** Dostają losowe, zahashowane hasło i domyślną grupę klientów sklepu. Klient z adresem e-mail już istniejącym w sklepie nie jest tworzony ponownie (jego zamówienia i adresy trafiają na istniejące konto); klient bez poprawnego adresu e-mail jest liczony jako błąd, a jego zamówienia nie zostaną zaimportowane.
- **Zamówienia.** Zachowują referencję, numer koszyka (`id_cart`) ze starego sklepu i nazwę płatności; po imporcie licznik koszyków jest przesuwany za największy zachowany numer, żeby nowe koszyki go nie powtarzały. Zamówienie z istniejącą w sklepie referencją jest pomijane (opcja *Pomijaj zduplikowane zamówienia*), a jego pozycje, historia i wiadomości nie są dopisywane drugi raz.
- **Adresy.** Zachowują znacznik „usunięty” ze starego sklepu (usunięty adres nie wraca do książki adresowej klienta, ale pozostaje widoczny w zamówieniach).
- **Mapowania.** Statusy zamówień domyślnie 1:1 po numerze (gdy brak — status nr 1); płatności po nazwie modułu (gdy brak — przelew bankowy `ps_wirepayment`); przewoźnicy po nazwie (gdy brak — pierwszy nieusunięty przewoźnik sklepu); waluty i języki po kodzie ISO (brak języka — język domyślny). Mapowanie zmienia moduł płatności zamówienia, a pole z nazwą płatności zostaje takie jak w starym sklepie.
- **Ponawianie.** „Ponów import” przetwarza zadanie od początku i opiera się na pomijaniu duplikatów, dlatego przy planowanym ponawianiu zostaw włączoną opcję *Pomijaj zduplikowane zamówienia*. Ponowienie wymaga zachowanych tabel-piaskownic.
- **Wydajność.** Analiza zrzutu pracuje w porcjach (do 8 sekund lub 8 MB na jedno wywołanie), a import we wsadach o rozmiarze z ustawienia *Rozmiar wsadu*.
- **Prywatność i bezpieczeństwo.** Moduł działa tylko w panelu, a wywołania AJAX przechodzą przez kontroler administracyjny z tokenem. Wgrywane są wyłącznie pliki `.sql` (sprawdzane są rozmiar i początek zawartości pliku), zapisywane pod unikalną nazwą z uprawnieniami `0640`. Moduł nie wysyła danych poza serwer — jedyna komunikacja wychodząca to e-maile do klientów przez pocztę sklepu, jeśli je włączysz.

## Rozwiązywanie problemów

- **PrestaShop odrzuca ZIP przy instalacji** — pobierz paczkę ponownie z Releases; nie pakuj folderu samodzielnie (ZIP musi mieć jeden katalog główny o nazwie modułu).
- **Analiza się zatrzymuje albo wersja jest nieznana** — zrzut musi być standardowym eksportem mysqldump / phpMyAdmin. Wyeksportuj bazę ponownie z domyślnymi ustawieniami.
- **„W zrzucie brakuje wymaganej tabeli lub kolumny”** — eksport jest niepełny; wyeksportuj całą bazę, a nie wybrane tabele.
- **Waluta blokuje import** — dodaj walutę w Międzynarodowe → Lokalizacja → Waluty, a potem otwórz mapowanie ponownie.
- **Klienci nie mogą się zalogować** — to zamierzone: hasła nie są przenoszone. Klient ustawia nowe hasło z e-maila albo przez „Nie pamiętasz hasła?” na stronie logowania.
- **Folder zrzutów nie jest zapisywalny** — nadaj folderowi `dump/` uprawnienia do zapisu (np. `chmod 0775`).

## Zmiany

Historia wersji: [CHANGELOG.md](CHANGELOG.md).

## Licencja i autor

MIT — pełny tekst w [LICENSE.md](LICENSE.md). Moduł możesz używać, zmieniać i rozpowszechniać, także komercyjnie; zachowaj tylko informację o prawach autorskich i licencji. Moduł zapisuje dane klientów i zamówień w sklepie — przed każdym importem wykonaj pełną kopię bazy danych.

Arkadiusz Pielechowski · [pielechowski.pl](https://pielechowski.pl)
