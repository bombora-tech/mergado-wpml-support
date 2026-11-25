# Changelog

Formát podle [Keep a Changelog](https://keepachangelog.com/cs/), verze podle [SemVer](https://semver.org/lang/cs/).

## [1.0.0] - 2026-11-25

### Přidáno
- Samostatný Mergado produktový feed pro každý jazyk WPML: cron URL s parametrem `zg_lang=xx` přepne jazyk WPML po celou dobu generování.
- Hotový feed se zkopíruje do `wp-content/uploads/bombora-feeds/<nazev>-<xx>.xml` (atomicky přes `.part`), stav a počet položek se ukládá.
- Zámek proti prolínání jazyků: dokud se generuje jeden jazyk, cron jiného jazyka dostane HTTP 423. Zámek vyprší po nastaveném počtu minut od poslední dávky, ručně jde uvolnit v administraci.
- Administrace **Bombora → Mergado feedy**: cron URL a URL feedu pro každý jazyk, počet přeložených produktů, poslední běh, navržená časová okna pro cron-job.org.
- `ignore_user_abort` – dávka doběhne i po timeoutu cron-job.org.
- Čeština administrace.
