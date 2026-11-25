# Bombora Mergado WPML

Mergado Pack for WooCommerce generuje produktový feed jen v jednom jazyce a WPML nepodporuje (cron bere vždy výchozí jazyk). Tento modul vytvoří **samostatný feed pro každý jazyk WPML**.

- Složka pluginu: `bombora-mergado-wpml`, nastavení: **Bombora → Mergado feedy**.
- Instalace a aktualizace přes **Bombora Manager** (*Bombora → Moduly*).

## Jak to funguje
1. Do administrace se jednou vloží cron URL z Mergado Pack (`/mergado/?action=productCron&token=…`).
2. Pro každý jazyk se zobrazí cron URL s `&zg_lang=xx` → nastavit v cron-job.org, každý jazyk ve vlastním časovém okně.
3. Při takovém requestu modul přepne jazyk WPML, Mergado vygeneruje dávku; po dokončení feedu se XML zkopíruje do `uploads/bombora-feeds/<nazev>-<xx>.xml`.
4. URL hotových feedů se zobrazí v administraci → napojit do Mergada / Merchant Center.

## Pravidla
- V Mergado Pack vypnout interní WP-Cron.
- Jazyky se nesmí generovat současně (Mergado má jeden rozpracovaný feed). Modul to hlídá zámkem – jiný jazyk dostane HTTP 423, dokud se aktuální nedokončí nebo zámek nevyprší.
- Do feedu jazyka jdou jen produkty přeložené do tohoto jazyka.
- Přepíná se jen jazyk, ne měna WCML.
- Bez WPML modul nic nedělá.

## Vydání nové verze
1. Zvýšit verzi v hlavičce `bombora-mergado-wpml.php` **i** v `BMW_VERSION`.
2. Doplnit `CHANGELOG.md`.
3. Push do `main` → GitHub Action vytvoří Release `vX.Y.Z` s `bombora-mergado-wpml.zip` + `.sha256`.

Ručně: `./build.sh` → `dist/`.
