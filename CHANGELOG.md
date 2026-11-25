# Changelog

Formát podle [Keep a Changelog](https://keepachangelog.com/cs/), verze podle [SemVer](https://semver.org/lang/cs/).

## [1.2.0] - 2026-11-25

### Přidáno
- Doplnění feedu z WooCommerce: Mergado Pack u balíčků WPC Product Bundles (`woosb`) vynechává `SHIPPING_WEIGHT`, `CATEGORY` a `PRODUCTNO`. Plugin je po dokončení feedu doplní z produktu (váha, kategorie s primární kategorií Rank Math/Yoast na prvním místě, SKU). Položky, které údaje mají, zůstávají beze změny. Počet doplněných hodnot se zobrazuje v administraci.

## [1.1.0] - 2026-11-25

### Opraveno
- Feed nevýchozího jazyka se generoval ve výchozím jazyce: Mergado generuje celý feed už na `init`, kdy WPML jazyk z URL přepíše. Jazyk se nově přepíná i hned po inicializaci WPML (`init` 2–9) a těsně před každým dotazem na produkty a termy (`pre_get_posts`, `pre_get_terms`); dotazům na produkty se předává i `lang`.

### Přidáno
- Kontrola výsledku: u nevýchozího jazyka se ověří, že URL produktů ve feedu obsahují `/xx/` („Jazyk OK“ / „Špatný jazyk“).
- Diagnostika posledního běhu (jazyk WPML a zásobník hooků při prvních dotazech na produkty).

## [1.0.0] - 2026-11-25

### Přidáno
- Samostatný Mergado produktový feed pro každý jazyk WPML: cron URL s parametrem `zg_lang=xx` přepne jazyk WPML po celou dobu generování.
- Hotový feed se zkopíruje do `wp-content/uploads/bombora-feeds/<nazev>-<xx>.xml` (atomicky přes `.part`), stav a počet položek se ukládá.
- Zámek proti prolínání jazyků: dokud se generuje jeden jazyk, cron jiného jazyka dostane HTTP 423. Zámek vyprší po nastaveném počtu minut od poslední dávky, ručně jde uvolnit v administraci.
- Administrace **Bombora → Mergado feedy**: cron URL a URL feedu pro každý jazyk, počet přeložených produktů, poslední běh, navržená časová okna pro cron-job.org.
- `ignore_user_abort` – dávka doběhne i po timeoutu cron-job.org.
- Čeština administrace.
