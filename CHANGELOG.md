# Historia zmian

Wszystkie istotne zmiany w module są opisane w tym pliku.

Format opiera się na [Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), a wersjonowanie na [Semantic Versioning](https://semver.org/lang/pl/).

## [1.1.0] – 2026-10-08

### Zmieniono
- Wszystkie teksty modułu są po polsku jako tekst źródłowy: strona konfiguracji, ekrany zadań importu, komunikaty i błędy, pasek postępu (JavaScript), log importu, nazwa zakładki panelu oraz wiadomość e-mail do klientów.
- Statusy zadań, poziomy i fazy logu oraz rodzaje danych w raportach wyświetlają się po polsku (wartości techniczne w bazie, w adresach i w klasach CSS bez zmian).
- Szablon e-maila `account_migrated` ma polską treść i temat; leży w `mails/pl/`, a kopia w `mails/en/` jest awaryjnym szablonem, po który PrestaShop sięga, gdy nie ma szablonu dla języka klienta ani domyślnego języka sklepu.
- Opcje „Klienci”, „Zamówienia z pozycjami” i „Pomijaj zduplikowanych klientów” są pokazane jako zawsze włączone — importer i tak nigdy ich nie wyłączał, więc pole wyboru wprowadzało w błąd.
- Filtr fazy w logu importu jest listą wyboru zamiast pola tekstowego z technicznym identyfikatorem.
- Adresy plików CSS i JavaScript panelu zawierają numer wersji, więc po aktualizacji przeglądarka pobiera nowe pliki.
- Wpisy logu pokazują rodzaj rekordu w liczbie pojedynczej („Klient 12”, „Zamówienie 45”).
- Opis ustawienia „Rozmiar wsadu” mówi o rekordach (klienci, adresy, zamówienia itd.), bo tyle faktycznie przetwarza jedno wywołanie AJAX.
- Zgodność z PrestaShop: `ps_versions_compliancy` od `9.0.0`.

### Naprawiono
- Ekrany zadań importu używają tłumaczeń PrestaShop 9 (`trans()`) zamiast usuniętej z tej wersji metody `l()` kontrolera — bez tego lista zadań i podgląd zadania kończyły się błędem krytycznym.
- Link w e-mailu z ustawieniem hasła ma format zgodny z PrestaShop 9 (`token` = klucz klienta, `id_customer`, `reset_token`); wcześniej strona hasła odrzucała taki link. Klientowi bez klucza (`secure_key`) moduł tworzy go przed wysyłką.

### Dodano
- Duże przyciski najważniejszych akcji (klasa `apline-btn-duzy`): „Zarządzaj zadaniami importu”, „Zapisz ustawienia”, „Nowe zadanie importu”, „Utwórz zadanie importu”, „Wczytaj zrzut do piaskownicy”, „Skonfiguruj mapowania”, „Zapisz decyzje o produktach”, „Zapisz mapowania”, „Rozpocznij import” i „Wyślij e-maile z linkiem do ustawienia hasła”.
- Przycisk „Nowe zadanie importu” nad listą zadań (zastępuje mały domyślny przycisk „+”).
- `upgrade/upgrade-1.1.0.php`: ustawia polską nazwę zakładki panelu („Migrator zamówień”) dla wszystkich języków i odtwarza zakładkę, jeśli ją usunięto. Ustawienia ogólne, zadania importu, mapowania i logi zostają bez zmian.
- Podgląd zawartości zrzutu pokazuje liczbę zapisów do newslettera (tabela jest tylko wczytywana do piaskownicy, moduł jej nie importuje).

### Przegląd danych
- W kodzie, szablonach e-maili, dokumentacji i folderze `dump/` nie ma danych klientów, zamówień, adresów, domen ani mapowań konkretnego sklepu; nowe ustawienia nie były potrzebne.

### Licencja i autor
- Licencja **MIT** (wcześniej Custom Attribution License v1.0): moduł możesz używać, zmieniać i rozpowszechniać, także komercyjnie, z zachowaniem noty o prawach autorskich i licencji.
- Autor: Arkadiusz Pielechowski — podpis „Moduł stworzony przez PIELECHOWSKI.PL” na stronie konfiguracji i ramka „Podoba Ci się ten moduł?” prowadzą do https://pielechowski.pl.
- Lżejsze logo modułu (28 KB zamiast ok. 0,8–0,9 MB) — szybsza lista modułów w panelu.

## [1.0.1] – 2026-10-08

### Naprawiono
- Po imporcie licznik koszyków (`AUTO_INCREMENT` tabeli `cart`) jest przesuwany za największy `id_cart` zachowany w zaimportowanych zamówieniach i wiadomościach. Wcześniej nowe koszyki dostawały te same numery, przez co składanie zamówienia kończyło się błędem „zamówienie dla tego koszyka zostało już złożone”, a krok wiadomości do zamówienia mógł pobrać zaimportowaną wiadomość.
- Adresy usunięte w starym sklepie są importowane jako usunięte (wcześniej wracały jako aktywne i pojawiały się w książkach adresowych klientów).

## [1.0.0] – 2026-06-01

### Dodano
- Pierwsze wydanie.
- Narzędzie dla administratora do migracji **klientów i zamówień** ze zrzutu SQL sklepu PrestaShop **1.7.8.x** do PrestaShop **9.0.x**.
- Zadania importu tworzone ze zrzutu `.sql` wgranego z formularza (z zabezpieczeniami wgrywania) albo umieszczonego przez FTP w folderze `dump/` modułu.
- Strumieniowy parser zrzutów SQL, który nie ładuje całego pliku do pamięci, wykrywa prefiks tabel źródła i zapisuje dane do tabel-piaskownic (`psmig_<zadanie>_*`) niezależnych od danych sklepu aż do momentu importu.
- Walidacja po analizie: wykrycie wersji PrestaShop w źródle oraz sprawdzenie wymaganych tabel i kolumn (brak = błąd blokujący).
- Trójwarstwowe mapowanie produktów: automatyczne dopasowanie po EAN do produktów sklepu, ręczne powiązanie z wyszukiwarką i migawka dla pozostałych.
- Mapowanie statusów zamówień, modułów płatności, przewoźników, walut i języków z automatycznymi podpowiedziami, wartościami zastępczymi i dopasowaniem walut oraz języków po kodzie ISO (brak waluty blokuje import).
- Import wsadowy z możliwością wznowienia, transakcją na wsad, izolacją błędów pojedynczych rekordów i pomijaniem duplikatów (klienci wg e-maila, zamówienia wg referencji).
- Nowi klienci dostają losowe hasło oraz opcjonalny, rozłożony w czasie e-mail z linkiem do ustawienia hasła, żeby mogli przejąć przeniesione konto.
- Raport zakończenia z liczbami zaimportowanych, pominiętych i błędnych rekordów oraz log importu z filtrami i stronicowaniem.
- Czyszczenie piaskownicy, ponowienie importu i usuwanie pliku zrzutu po imporcie.
- Odporny panel: brak tabeli zadań daje wyjaśnioną pustą listę zamiast błędu 500.
- Licencja Custom Attribution License v1.0 z odnośnikiem APLINE generowanym po stronie serwera.

### Zakres (wersja 1.0.0)
- Obsługiwana ścieżka: PrestaShop 1.7.8.x → 9.0.x; dane: klienci, adresy, zamówienia, pozycje zamówień, historia zamówień, wiadomości do zamówień.
- Poza zakresem: produkty, kategorie, obrazy, konfiguracja sklepu, wielosklepowość, synchronizacja na żywo i migracja w drugą stronę.
