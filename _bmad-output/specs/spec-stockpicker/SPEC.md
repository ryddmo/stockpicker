---
id: SPEC-stockpicker
companions:
  - ../../planning-artifacts/architecture/architecture-stockpicker-2026-09-08/ARCHITECTURE-SPINE.md
  - ../../planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md
sources:
  - ../../planning-artifacts/briefs/brief-stockpicker-2026-09-08/brief.md
---

> **Kanoniskt kontrakt.** Denna SPEC och filerna i `companions:` är hela det
> preservation-validerade kontraktet för vad som ska byggas, testas och verifieras.
> Källdokumenten i frontmatter är för spårbarhet — läs dem bara för narrativ bakgrund
> som kontraktet medvetet utelämnar.

# Stockpicker — datainsamlingsmotor v1

## Why

En vision att förverkliga. Stefan vill ha ett eget beslutsstöd för att välja enskilda
aktier, byggt kring en signal: **antalet ägare av en aktie hos Avanza respektive
Nordnet, följt dag för dag**. Hypotesen är att ett stadigt växande ägarantal över lång
tid säger något om ett bolag som pris och volym inte fångar. Idag är Stefan
indexinvesterare och öppen för något större risk i stabila bolag — men saknar
strukturerad, egen historik att luta besluten mot. Verktyget är personligt; ambitionen
är bättre egna beslut, inte en produkt för andra. v1 är enbart **insamlingsmotorn** —
den som bygger upp tidsserien. Presentation och analys kommer senare och behöver att
datainsamlingen redan gått ett tag.

## Capabilities

- **CAP-1 — Universum**
  - **intent:** Systemet håller en aktuell lista över svenska bolag på Nasdaq Stockholm
    Large/Mid/Small Cap och First North, hämtad från Börsdata och avstämd varje natt
    (tillkomna, avnoterade, listbytande bolag).
  - **success:** Instrumentlistan speglar Börsdatas motsvarande listor inom ett dygn;
    varje avstämning loggar antal tillkomna/borttagna/ändrade.

- **CAP-2 — Daglig hämtning**
  - **intent:** För varje aktivt instrument hämtar systemet en gång per dygn ägarantal
    och kringdata (namn, ISIN, lista, senaste kurs, börsvärde) från både Avanza och
    Nordnet.
  - **success:** Minst 99 % av aktiva instrument får en färdig rad per källa och
    handelsdag.

- **CAP-3 — Tidsserielagring**
  - **intent:** All hämtad data lagras append-only som en tidsserie, idempotent per
    `(isin, source, as_of_date)`, källindelad, och skiljer datans datum (`as_of_date`,
    kalenderdatum i `Europe/Stockholm`) från hämtningstidpunkten (`fetched_at`).
  - **success:** En omkörd natt ger samma sluttillstånd; ingen befintlig rad skrivs
    över; historiken växer monotont.

- **CAP-4 — Härledda mått**
  - **intent:** Per instrument och källa beräknar systemet dagsförändring,
    procentuell förändring, glidande medel (7/30/90 dagar), antal dygn i följd med
    nettoökning, och en spik-/avvikelseindikator mot den egna trenden.
  - **success:** För valfritt instrument går hela ägarserien plus alla härledda mått
    att hämta för hela den insamlade perioden.

- **CAP-5 — Robust nattkörning**
  - **intent:** Nattkörningen tål delfel (ett trasigt instrument eller en nere källa
    stoppar inte resten), återförsöker övergående fel med backoff, är återupptagbar
    över flera invokationer, och har en konfigurerbar "kör inte före"-tid som ändras
    utan deploy.
  - **success:** En körning som delas över flera cron-pass slutförs; efter en natt då
    en källa var nere har den andra källans data ändå landat.

- **CAP-6 — Schemakontroll vid källgränsen**
  - **intent:** När ett svar från en inofficiell endpoint saknar ett förväntat fält
    eller ändrar form, ger systemet ett typat fel (`SchemaMismatch`) i stället för att
    tyst spara ett tomt värde.
  - **success:** Ett injicerat felaktigt svar ger en `warning`-loggpost och räknas i
    körningsloggen; ingen null-rad sparas.

- **CAP-7 — Observerbarhet**
  - **intent:** Varje körning och slice är inspekterbar i efterhand — körningslogg med
    tider, antal instrument, lyckade/misslyckade per källa, upptäckta schemaavvikelser,
    plus universumavstämningens förändringar.
  - **success:** Efter en natt går det att avgöra exakt vad som lyckades och vad som
    fallerade, per källa.

## Constraints

- **Kör helt på Loopia delat webbhotell (Privatpaket).** PHP 8.3 + MariaDB 10.6. Cron
  är enbart HTTP GET mot en URL (ingen CLI-cron), med exekveringstidsgräns och en
  cron-instans i taget. Utesluter CLI-jobb, långa oavbrutna körningar och val av annan
  runtime eller databas.
- **Ägarantal-källorna är inofficiella, odokumenterade endpoints** (Avanza
  `market-guide`, Nordnet `stocklist`) som kan ändras utan förvarning. Kräver
  schemakontroll (CAP-6) och låg anropsvolym.
- **Endast personligt bruk.** Ingen redistribution eller publicering av hämtad data;
  anropsvolymen hålls låg. Detta är en arkitekturgräns, inte en implementationsdetalj.
- **En användare, en installation.** Ingen inloggning bortom en cron-token, ingen
  fleranvändarhantering, ingen SLA.
- **Avanzas och Nordnets ägarantal mäter olika populationer** och ingetdera är det
  legala aktieägarantalet — de får aldrig summeras till en total.
- **Ingen historisk backfill.** Tidsserien börjar vid första körningen; att bygga upp
  historik är ett kärnsyfte, inte en brist att åtgärda.

## Non-goals

- Allt presentations- och analyslager: webbgränssnitt, grafer, dashboards, notiser.
- Automatiska köp-/säljsignaler eller en regelmotor.
- Icke-svenska marknader; instrument som inte är aktier (fonder, ETF:er, index).
- Euroclear/Holdings/Börsdata som källa för det totala legala aktieägarantalet
  (Börsdata används enbart för att definiera universumet).
- Realtids- eller intradagsdata.
- Molninfrastruktur, fleranvändarstöd, hög tillgänglighet.
- Att fastställa definitionen av "tillfällig topp" nu — uppskjuten tills det finns
  tillräckligt med historik att lära av.

## Success signal

Efter en månads oövervakad drift finns en obruten daglig tidsserie för i princip hela
universumet (högst någon enstaka procent saknade datapunkter). Stefan kan för valfri
aktie hämta hela ägarutvecklingen och de härledda måtten för hela den insamlade
perioden. När Avanza eller Nordnet ändrar sitt svar märks det som ett larm i
körningsloggen — inte som tyst dataförlust.

## Assumptions

- Börsdatas gratisnivå ger hela universumet (LC/MC/SC + First North) med listetikett
  och ISIN. Behöver verifieras mot faktiskt API-utfall.
- Loopias URL-cron tillåter ett körningspass på flera minuter (deras egen dokumentation
  antyder det), men den exakta gränsen är okänd — arkitekturen (CAP-5) är byggd för att
  vara robust oavsett.

## Open Questions

- Nordnets exakta uppdateringstid för ägarantal är odokumenterad — provas fram via den
  konfigurerbara körtiden.
- Härledda mått som SQL-vy eller materialiserad tabell — avgörs vid implementation.
- Loopias verkliga exekveringstidsgräns för URL-cron — värt en supportfråga före
  driftsättning.
