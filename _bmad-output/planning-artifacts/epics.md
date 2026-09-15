---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics, step-02-design-epics-revised-via-party, step-03-create-stories, step-03-create-stories-revised-via-party, step-04-final-validation]
inputDocuments:
  - _bmad-output/specs/spec-stockpicker/SPEC.md
  - _bmad-output/planning-artifacts/architecture/architecture-stockpicker-2026-09-08/ARCHITECTURE-SPINE.md
  - _bmad-output/planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md
  - _bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/DESIGN.md
  - _bmad-output/planning-artifacts/ux-designs/ux-stockpicker-2026-09-12/EXPERIENCE.md
---

# stockpicker - Epic Breakdown

## Overview

Detta dokument bryter ner kraven från SPEC.md (i PRD:ns ställe), arkitektur-spinen och
addendum till implementerbara stories för v1: den nattliga datainsamlingsmotorn.

## Requirements Inventory

### Functional Requirements

FR1: Systemet håller en aktuell lista över svenska bolag på Nasdaq Stockholm Large/Mid/Small Cap och First North, hämtad från Avanzas publika aktielistning och avstämd varje natt (tillkomna, avnoterade, listbytande bolag); varje avstämning loggar antal ändrade.
FR2: En gång per dygn hämtar systemet ägarantal och kringdata (namn, ISIN, lista, senaste kurs, börsvärde) från både Avanza och Nordnet för varje aktivt instrument.
FR3: Vid universumavstämning slår systemet upp och cachar varje nytt instruments Avanza `orderbookId` och Nordnet `nnx_instrument_id` via respektive sök-endpoint, med ISIN som nyckel; uppslag sker aldrig lat i hämtningssteget.
FR4: All hämtad data lagras append-only som en tidsserie, idempotent per `(isin, source, as_of_date)`, källindelad; ingen befintlig rad skrivs över.
FR5: Varje faktarad bär både `as_of_date` (kalenderdatum i `Europe/Stockholm`, härlett ur källans tidsstämpel när den finns, annars körningsdatum) och `fetched_at`.
FR6: Samma bolag hos Avanza och Nordnet knyts ihop via ISIN; instrument som bara finns hos en källa lagras ändå.
FR7: Per instrument och källa beräknar systemet dagsförändring, procentuell förändring, glidande medel (7/30/90 dagar), antal dygn i följd med nettoökning, och en spik-/avvikelseindikator mot den egna trenden.
FR8: Nattkörningen tål delfel — ett trasigt instrument eller en nere källa avbryter inte körningen; fel loggas per instrument.
FR9: Övergående fel återförsöks med exponentiell backoff; jobb som fortfarande fallerar lämnas till nästa körning.
FR10: Körningen är återupptagbar över flera invokationer via en kö-tabell med atomiskt jobb-claim.
FR11: En konfigurerbar "kör inte före"-tid går att ändra utan deploy.
FR12: Varje källsvar valideras mot ett förväntat schema; saknat eller förändrat fält ger ett typat `SchemaMismatch`-fel, loggat på `warning` och räknat — aldrig sparat som null.
FR13: Varje körning/slice skriver en inspekterbar körningslogg (start/slut, antal instrument, lyckade/misslyckade per källa, schemaavvikelser); universumavstämningen loggar sina förändringssiffror.
FR14: Systemet tillhandahåller en autentiserad webb-yta (inloggning med användarnamn/lösenord, långlivad signerad sessionscookie) för att bläddra insamlad ägarantal-data människovänligt; alla routes utom `/login` kräver en giltig session.
FR15: Topplista visar de 10 aktier som rankas högst efter ägarantal (läge "Flest ägare") eller trendkvalitet (läge "Stadig tillväxt"), per källa, med dag-till-dag-delta, Streak badge, Spike badge (när tillämpligt) och Sparkline per rad.
FR16: En källväxlare låter användaren växla mellan Avanza- och Nordnet-data i Topplista, Fullständig lista och Aktiedetalj; de två källornas ägarantal summeras aldrig i någon vy.
FR17: Fullständig lista visar alla spårade instrument för vald källa, sökbar, filtrerbar (spikflaggad, Stadig tillväxt, bevakad, marknadslista) och sorterbar (namn / ägarantal / %-förändring).
FR18: Bevakningslista låter användaren märka/avmärka valfritt instrument som bevakat via en optimistisk växling utan sidladdning, och visar en egen flik med bara bevakade instrument.
FR19: Aktiedetalj visar ett instruments fullständiga ägarhistorik och härledda mått för båda källorna samtidigt (Trend overlay med primär/sekundär linje), med ett intervallval (Dag/Vecka/30d/90d/År) som matchar backendens beräkningsfönster (`sma_7`/`sma_30`/`sma_90`).
FR20: Topplistan i läget "Stadig tillväxt" förklarar sorteringskriterierna — vad som gör att ett instrument kvalificerar och hur det rankas.
FR21: Leaderboard-rader visar procentuell utveckling över flera perioder (dag/vecka/90 dagar/år), inte bara dagens delta.
FR22: Källväxlaren får ett tredje läge "Alla" (visat först, före Avanza/Nordnet) som visar båda källornas ägarantal sida vid sida per rad, utan att summera dem (NFR6 gäller oförändrat).
FR23: Varje aktie länkar till sin sida på Avanza (via `orderbookId`) för vidare fördjupning.
FR24: En informationssida beskriver webbplatsen — vad som finns på varje sida och vad badges/symboler betyder.
FR25: Topplistans rader renderas korrekt på mobil utan att klippas av (bugg — ägarantal/delta-chip/sparkline-etikett syns inte fullt ut på smala skärmar).

### NonFunctional Requirements

NFR1: Systemet körs helt på Loopia delat webbhotell (Privatpaket): PHP 8.3+ (skalet 8.5), MariaDB 10.11 (verifierat på Loopia 2026-09-08). Ingen annan runtime eller databas.
NFR2: Nattarbetet triggas enbart av Loopias URL-cron (HTTP GET); inget steg får förutsätta att det slutförs i en enda invokation; systemet tål exekveringstidsgräns och en cron-instans i taget.
NFR3: Externa anrop sker seriellt, strypta per källa (anrop/s från `settings`), med exponentiell backoff vid 429 eller strypning; inga parallella massanrop.
NFR4: Endast personligt bruk — ingen komponent exponerar hämtad data utåt; anropsvolymen hålls låg; cron-endpoints kräver en hemlig token.
NFR5: En användare, en installation — ingen inloggning bortom cron-token, ingen fleranvändarhantering, ingen SLA.
NFR6: Avanzas och Nordnets ägarantal summeras aldrig (olika populationer, ingetdera är det legala aktieägarantalet).
NFR7: Ingen historisk backfill — tidsserien börjar vid första körningen.
NFR8: Datavolymen är liten (~730 000 rader/år); körningen ska rymmas inom 256 MB PHP-minne.
NFR9: Hemligheter (DB-uppgifter, cron-token) ligger i `config.php` utanför webroot, aldrig i versionshantering; drift-parametrar i en `settings`-tabell.
NFR10: Webb-UI:t renderas serverside i PHP utan klientramverk eller byggsteg; enda undantaget är en handskriven vanilla JS-fil (`watchlist.js`) för bevakningsstjärnans optimistiska växling.
NFR11: Autentiserad routing (allt utom `/login`) kräver en giltig HMAC-signerad sessionscookie med fast 30-dagars TTL; ingen PHP-native session, ingen fleranvändarhantering, ingen registrering.
NFR12: UI-språket är svenska genomgående — alla etiketter, felmeddelanden och datum i webb-gränssnittet.
NFR13: Layouten är mobilanpassad-först och responsiv (referensbredder ~390px telefon, ~900px laptop) och interpolerar genom surfplatteintervallet utan en tredje skräddarsydd layout.
NFR14: Interaktiva element i datatäta listor (Watchlist star, flikfält, växlarsegment) har ett tryckmålsgolv på ~44px.
NFR15: Text- och badgekontrast siktar på ett WCAG AA-liknande riktmärke (~4,5:1) för dämpade kombinationer; ingen formell tillgänglighetsgranskning krävs.

### Additional Requirements

- **Greenfield, ingen starter-template.** Composer-projekt utan ramverk. Beroenden: `guzzlehttp/guzzle ^7.9 || ^8.0`, `monolog/monolog ^3.11`, `robmorgan/phinx ^0.16.12`. `composer.json` sätter `require.php` till `>=8.3` (inte pinnad) — Loopias skal kör PHP 8.5; web-PHP-versionen väljs per domän i Kundzon.
- **Paradigm:** pipes-and-filters (`UniverseSync → Enqueue → FetchRunner → Normalizer → Deriver`) + ports-and-adapters. All källåtkomst bakom `SourceAdapter`-interface i `src/Adapter/`; ingen HTTP/URL utanför `src/Adapter/` (AD-1).
- **Adapterkontrakt:** `fetch` returnerar normaliserad rad `{isin, source, as_of_date, number_of_owners, last_price, market_cap, fetched_at}` eller typat fel `SchemaMismatch | NotFound | Transient` (AD-2).
- **Katalogstruktur:** `public_html/` (tunn front controller: `/cron/refill`, `/cron/work`, `/cron/derive`), `src/{Adapter,Pipeline,Store,Error}/`, `bin/`, `db/migrations/`, `config.php` utanför webroot, `vendor/`.
- **Cron-endpoints:** `/cron/refill` (dagligen 00:00 → `UniverseSync` + `Enqueue`), `/cron/work` (var 5:e min → `FetchRunner`, tidsbox ~60–90 s), `/cron/derive` (efter kön tom → `Deriver`). Autentiseras med token via `hash_equals` mot `config.php`.
- **Databasåtkomst enbart genom `src/Store/`-repositories** (PDO, ingen ORM): `InstrumentRepository`, `OwnerCountRepository`, `QueueRepository`, `RunRepository`, `SettingsRepository`. Ingen SQL i pipeline eller adapter.
- **`work_queue`-tillståndsmaskin:** `pending → claimed → done | failed`. Bara `Enqueue` skapar `pending`; bara `FetchRunner` gör övriga övergångar; `claimed`-rad äldre än `queue.stale_after` återöppnas till `pending` (AD-5).
- **Dataägande (AD-3):** `instrument` skrivs bara av `UniverseSync`; `owner_count_daily` är källindelad — varje adapterflöde skriver bara sina egna `source`-rader; `Deriver` läser fakta, skriver dem aldrig.
- **Tabeller:** `instrument`, `owner_count_daily`, `work_queue`, `ingest_run`, `settings`. `snake_case`, singular tabellnamn. ISIN naturlig nyckel överallt; Avanza/Nordnet-id är cachade attribut på `instrument`.
- **Migrationer via Phinx**; första migrationen sätter kanoniska `settings`-nycklar (`run_after`, `batch_size`, `rate.<källa>`, `queue.stale_after`).
- **Deploy (Story 1.10):** rsync över SSH, inte FTP. Källkod (utan `vendor/`) synkas till `~/stockpicker/`; `composer install --no-dev --optimize-autoloader` körs på servern (lokalt byggt `vendor/` är fallback); `config.php` kopieras manuellt en gång, utanför docroot; migrationer körs manuellt via SSH (`vendor/bin/phinx migrate`); subdomän med docroot `~/stockpicker/public_html/`; Loopias tre URL-cron-jobb registreras i Kundzon. Runbook i `docs/deploy.md`.
- **Härledda mått:** MariaDB window functions; SQL-vy vs materialiserad tabell avgörs vid implementation.
- **Loggning:** Monolog till fil; `warning` för schemaavvikelse, `error` för oväntat undantag.
- **Öppna frågor att hantera i drift:** Avanza-listningens endpoint-väg/filter och täckning overifierad (fastställs i Story 2.1); Nordnets uppdateringstid okänd; web-PHP:ns `memory_limit`/`max_execution_time` och URL-cronens tidsgräns + minsta intervall ännu okänt (Kundzon + probe i Story 1.10). Verifierat 2026-09-08: SSH, `rsync`, server-`composer`, `pdo_mysql`/`curl` finns.
- **Web-lager (AD-12–AD-15, 2026-09-12-tillägget):** `src/Web/` är ett eget lager sidoordnat med `src/Pipeline/` — båda anropar bara `src/Store/`, aldrig varandra; ingen SQL utanför `Store/`. Nya kontrollklasser: `AuthController`, `LeaderboardController`, `FullListController`, `WatchlistController`, `StockDetailController`.
- **Ny tabell `watchlist`** (`isin` PK/FK mot `instrument`, `RESTRICT` på delete/update, samma AD-4-mönster) ägs och skrivs bara av `src/Web/` via ett nytt `WatchlistRepository` — första skrivvägen i systemet som inte är den nattliga pipelinen (AD-15).
- **Nya routes** under samma front controller, sidoordnat med `/cron/*`: `/login`, `/` (topplista, kräver session), `/list` (fullständig lista), `/watchlist`, `/stock/{isin}` (aktiedetalj). `/` var tidigare den publika JSON-hälsokontrollen — den flyttar till `/health` (fortsatt publik, samma svarsform).
- **`config.php` utökas** med webb-inloggningens hemligheter (användarnamn, bcrypt-hashat lösenord, sessionscookiens HMAC-nyckel) utöver befintliga DB-uppgifter och `cron_token` (AD-8, AD-13).
- **Enda JS-filen i systemet:** `public_html/assets/watchlist.js` — `fetch()` POST mot en växlingsendpoint, ingen bundling, synkas som statisk fil via befintlig rsync-deploy (AD-12).
- **Delad felhanterare:** ett ofångat undantag i `src/Web/` fångas av samma `catch (\Throwable)`-mönster som redan omsluter cron-routerna, loggar via Monolog och renderar en gemensam generisk felsida (AD-14).
- **Deferred vid implementation:** exakt sök-/filter-/sorterings-SQL för Fullständig lista (WHERE-form, index, paginering) i `src/Store/`; exakt `watchlist`-schema utöver `isin`/`starred_at`.
- **Post-launch-tillägg (2026-09-13, efter Epic 4 i produktion):** Avanzas publika aktiesides-URL (för FR23) är overifierad — bara API-endpoints är dokumenterade i `addendum.md`, aldrig en sides-URL; det faktiska mönstret (troligen något i stil med `avanza.se/aktier/om-aktien.html/{orderbookId}/{slug}`) fastställs mot en riktig sida vid implementation, samma disciplin som Story 2.1:s endpoint-verifiering.

### UX Design Requirements

UX-DR1: Implementera designtoken-uppsättningen från `DESIGN.md` (färger, typografi, spacing, rundning, skuggor) som delad visuell grund — inget namngivet UI-ramverk, egenbyggda komponenter enligt spec.
UX-DR2: Leaderboard row-komponent — rank, Watchlist star, namn+badge-rad, Sparkline, ägarantal + Delta chip; namnet trunkeras med ellips först. Tryck var som helst på raden navigerar till Aktiedetalj, utom stjärnan (oberoende tryckmål, växlar bevakning på plats, ingen navigering/bekräftelse).
UX-DR3: Streak badge-komponent — "🔥 {n}d" på brand-tint, visas bara när `up_streak` ≥ 1; ersätts av No-history badgens "flat"-variant vid 0.
UX-DR4: Spike badge-komponent — "⚡ spike", bärnstensfärgad (aldrig röd/grön), oberoende av Streak badge, kan samexistera på samma rad.
UX-DR5: No-history badge-komponent — återanvänds för två meddelanden: "flat" (ingen pågående streak) och "{n}d spårade · ingen trend än" (för få rader för en trendlinje); samma dämpade visuella behandling båda gångerna.
UX-DR6: Sparkline-komponent — kontextuell linjefärg (positiv/negativ/spike/dämpad-streckad vid otillräcklig historik), 52×22 på mobil / ~130×30 på laptop.
UX-DR7: Trend overlay-komponent (bara Aktiedetalj) — primär linje = aktiv källa i Source switcher med kontextuell färglogik; sekundär linje = andra källan, alltid fast sekundärfärg + streckad oavsett trendriktning; legend-rad som parar ihop linjestil med källnamn.
UX-DR8: Source switcher-komponent — tvåvägsväxlare Avanza/Nordnet i pillerbehållare, aktiv flik inverterar till solid mörk bakgrund (UI:ts högsta-kontrast-kontroll); att växla hämtar/rankar om aktuell vy, slår aldrig ihop källornas antal.
UX-DR9: Ranking-mode toggle-komponent (bara Topplista-header) — "Flest ägare" / "Stadig tillväxt", samma pillerfamilj som Source switcher men visuellt distinkt aktivt tillstånd (vit flik + brand-text + skugga) så de två växlarna aldrig förväxlas.
UX-DR10: Range picker-komponent (bara Aktiedetalj) — Dag/Vecka/30d/90d/År, femsegmentskontroll som matchar backendens `sma_7`/`sma_30`/`sma_90`-fönster exakt; intervallbyte ritar om det redan laddade instrumentets diagram eller faller tillbaka till otillräcklig-historik-tillståndet, aldrig en delvis/missvisande linje.
UX-DR11: Watchlist star-komponent — fylld guldglyf eller tom konturglyf, oberoende tryckmål, optimistisk växling (ingen bekräftelse), består mellan sessioner.
UX-DR12: Delta chip-komponent — visar alltid antal och procent tillsammans ("+412 · 0,9 %"), aldrig bara ettdera; positiv/negativ färgvariant.
UX-DR13: Tomma/fel-tillstånd — exakt svensk mikrocopy för: otillräcklig historik (<7 rader), otillräcklig historik för längre intervall (<30/<90 rader), tom bevakningslista, noll kvalificerande Stadig tillväxt, inga sök-/filterresultat (med en "rensa filter"-åtgärd), inloggningsfel, sessionen har gått ut, datahämtning misslyckades (med en "försök igen"-åtgärd). Ton: rakt på sak, aldrig hype/gamifierat språk.
UX-DR14: Inloggningsflöde — användarnamns-/lösenordsformulär, post-och-omladdning (ingen AJAX), inline-felmeddelande på den omladdade sidan, inget utelåsningsmeddelande, lyckad inloggning etablerar långlivad sessionscookie och landar på Topplista.
UX-DR15: Navigationsmodell — beständigt flikfält med Topplista och Bevakningslista som de två toppnivå-flikarna; Fullständig lista är en fördjupning nådd via Topplistans footer-åtgärd (inte en tredje flik); Aktiedetalj är alltid en fördjupning från en radtryckning (aldrig en flik); Inloggning ligger helt utanför flikfältet.
UX-DR16: Responsivt beteende — enkolumnig kortlayout på mobil (~390px+), omflöde till explicita rutnätskolumner (rank/star/namn/ägare/trend/delta/flagga) vid laptop-bredd (~900px+); surfplatteintervallet interpolerar mot mobillayouten snarare än en tredje skräddarsydd layout.
UX-DR17: Interaktionsprimitiver — minsta tryckmålsgolv ~44px för stjärna/flikfältsobjekt/växlarsegment; ingen dragning, svep-för-att-radera eller long-press någonstans; pull-to-refresh-gest på mobil topplista plus synlig manuell uppdatering + "senast uppdaterad"-tidsstämpel på laptop.
UX-DR18: Tillgänglighetsgolv — WCAG AA-liknande kontrastriktmärke (~4,5:1) för dämpad-på-yta-kombinationer; ingen text mindre än 9px (label-storlek) för något handlingsbart; ingen dedikerad skärmläsargenomgång, inget reducerat rörelseläge, inget tangentbordsnavigeringskrav (uttryckligen utanför scope).
UX-DR19: Röst och ton-mikrocopydisciplin — skeptisk, rakt-på-sak-copy genomgående (se Gör/Gör inte-tabellen i EXPERIENCE.md); aldrig firande/gamifierat språk, aldrig emoji utöver de fasta 🔥/⚡-badgeglyferna.

### FR Coverage Map

FR1: Epic 2 - Live universum-avstämning mot Avanzas listning (Epic 1 kör mot en hårdkodad seed-lista)
FR2: Epic 1 - Daglig hämtning från Avanza (Story 1.4) och Nordnet (Story 1.5), separat
FR3: Epic 1 - Id-uppslag och cachning (mot seed-listans ISIN i Epic 1)
FR4: Epic 1 - Append-only tidsserielagring, idempotent
FR5: Epic 1 - as_of_date (Europe/Stockholm) och fetched_at
FR6: Epic 1 - ISIN-matchning mellan källorna
FR7: Epic 3 - Härledda mått per instrument och källa
FR8: Epic 2 - Delfeltolerans i nattkörningen
FR9: Epic 1 (ett-skotts omförsök i adaptrarna, jobb åter till pending) + Epic 2 (full exponentiell backoff, rate-limit-medveten)
FR10: Epic 1 - Återupptagbar kö med atomiskt claim
FR11: Epic 1 - Konfigurerbar "kör inte före"-tid
FR12: Epic 1 (minimalt: SchemaMismatch kastas, aldrig null) + Epic 2 (fullt: larm, räkning per källa)
FR13: Epic 1 (minimalt: en ingest_run-rad per körning) + Epic 2 (fullt: lyckade/misslyckade per källa, schemaavvikelser)
FR14: Epic 4 - Autentiserad webb-yta (inloggning, signerad sessionscookie)
FR15: Epic 4 - Topplista (rankningslägen, källa, delta/streak/spike/sparkline per rad)
FR16: Epic 4 - Källväxlare Avanza/Nordnet över Topplista/Fullständig lista/Aktiedetalj
FR17: Epic 4 - Fullständig lista (sök/filter/sortering över ~740 instrument)
FR18: Epic 4 - Bevakningslista (optimistisk stjärnväxling, egen flik)
FR19: Epic 4 - Aktiedetalj (Trend overlay båda källor, Range picker)
FR20: Epic 5 - Förklaring av Stadig tillväxt-sorteringen (informationssidan)
FR21: Epic 5 - Procentuell utveckling över flera perioder på raderna
FR22: Epic 5 - Källäge "Alla" (sida vid sida, aldrig summerat)
FR23: Epic 5 - Länk till aktien på Avanza
FR24: Epic 5 - Informationssida om webbplatsen
FR25: Epic 5 - Mobil layoutbugg på Topplistans rader

## Epic List

### Epic 1: Tunn end-to-end-skiva — nattlig insamling bevisad på en seed-lista
Varje lager, tunt: projektskelett (Composer utan ramverk, katalogstruktur, `config.php`, Phinx-migrationer, cron-endpoints), `SourceAdapter`-porten, Avanza- och Nordnet-adaptrarna som separata stories, kö + tidsbox, append-only-lagring med idempotens, ISIN-matchning, datumfälten. Instrumenten kommer från en hårdkodad lista på ~20 ISIN. Plus golvet av FR12/FR13: ett förändrat/saknat fält kastar `SchemaMismatch` i stället för att skriva null, körningsloggen finns från den story `FetchRunner` föds i (inte sist), adaptrarna gör ett ett-skotts omförsök vid `Transient` så natt ett överlever kontakt, och en avslutande story kör hela pipen mot seed-listan och ser riktiga rader dyka upp. Epicen inkluderar också driftsättning på Loopia (rsync över SSH, manuell migration,
URL-cron-registrering) så att den avslutande röktesten körs mot ett riktigt driftsatt
system. Efter epicen finns riktig data på disk och pipen kör oövervakad mot seed-listan.
**FRs covered:** FR2, FR3, FR4, FR5, FR6, FR10, FR11, FR12 (minimalt), FR13 (minimalt), FR9 (ett-skotts omförsök)
**NFRs covered:** NFR1, NFR2, NFR4, NFR8, NFR9 (Story 1.10 realiserar NFR1/NFR2-driftsättningen; NFR8 verifieras i Story 1.7)

### Epic 2: Live universum + överleva en månad oövervakad
Byt seed-listan mot den riktiga universum-synken (Avanzas listning): FR1, daglig avstämning, churn-loggning. Och rustningen: delfel förlorar inte en natt (FR8), övergående fel återförsöks med backoff (FR9), fastnade `claimed`-jobb återöppnas, körningsloggen får lyckade/misslyckade per källa och schemaavvikelser, schemaändringar blir larm — inte bara en loggrad. Ordnad story-lista: live universum först, sedan härdningsstories utifrån vad de första veckorna faktiskt kastar. Återkopplingsgränsen ligger inuti den här epicen.
**FRs covered:** FR1, FR8, FR9, FR12 (fullt), FR13 (fullt)

### Epic 3: Härledda mått
Ovanpå tidsserien går det att hämta dagsförändring, procentuell förändring, glidande medel (7/30/90), antal dygn i följd med nettoökning och spik-/avvikelseindikator per instrument och källa — grunden för det framtida analyslagret.
**FRs covered:** FR7

### Epic 4: Webb-UI — bläddra ägarantal-trender
Ovanpå tidsserien och de härledda måtten (Epic 1–3) får Stefan en autentiserad, mobilanpassad webbyta för att själv bläddra, ranka, filtrera och bevaka aktier utifrån ägarantalstrender, utan att fråga databasen direkt. Inloggning (signerad sessionscookie), Topplista (topp 10, källväxlare, rankningsläge), Fullständig lista (sök/filter/sort över ~740 instrument), Bevakningslista (optimistisk stjärnväxling) och Aktiedetalj (Trend overlay för båda källorna, Range picker) byggs som ordnade stories i `src/Web/`, sidoordnat med den nattliga pipelinen (`src/Web/` och `src/Pipeline/` anropar aldrig varandra). Efter epicen kan Stefan följa morgonrutinen och helgresearchen helt i webbläsaren i stället för mot rådata.
**FRs covered:** FR14, FR15, FR16, FR17, FR18, FR19

### Epic 5: Post-launch-förbättringar av webb-UI
Efter Epic 4:s driftsättning (2026-09-13) märker eller vill Stefan ha sex saker i verklig användning: en mobil layoutbugg som klipper av rader, avsaknad av en direktlänk till Avanzas egen aktiesida, ingen förklaring av vad sidorna/symbolerna betyder eller vad som styr Stadig tillväxt-sorteringen, önskan om att se båda källornas ägarantal sida vid sida utan att slå ihop dem, önskan om procentuell utveckling över fler perioder än bara dagens delta, och en daglig e-postdigest som sammanfattar vad som ändrats i topp 10 sedan föregående handelsdag. Sex ordnade stories i `src/Web/` respektive `src/Pipeline/` (multi-periods %-förändring sträcker sig in i `src/Pipeline/Deriver` för nya beräknade kolumner; e-postdigesten är ett eget, isolerat steg som körs efter `/cron/derive`). Efter epicen är Topplistan läsbar på mobil, varje aktie går att öppna direkt på Avanza, en informationssida förklarar verktyget, källväxlaren har ett tredje "Alla"-läge (aldrig summerat, NFR6 oförändrat), raderna visar utveckling över flera tidsperioder, och Stefan får ett dagligt sammandrag av topp 10-förändringar via e-post.
**FRs covered:** FR20, FR21, FR22, FR23, FR24, FR25

---

## Epic 1: Tunn end-to-end-skiva — nattlig insamling bevisad på en seed-lista

Efter epicen kör pipen oövervakad mot en hårdkodad lista på ~20 ISIN: ägarantal hämtas från Avanza och Nordnet varje natt, lagras append-only som tidsserie, och varje körning lämnar ett spår. Alla arkitekturlager finns men tunna.

### Story 1.1: Projektskelett och gemensam grund

As en utvecklare,
I want ett Composer-projekt med katalogstruktur, konfigurationsmall, loggning och Phinx inkopplat,
So that alla följande stories bygger på samma grund.

**Acceptance Criteria:**

**Given** ett rent repo
**When** `composer install` körs
**Then** beroendena `guzzlehttp/guzzle`, `monolog/monolog` och `robmorgan/phinx` installeras utan fel
**And** katalogerna `public_html/`, `src/Adapter/`, `src/Pipeline/`, `src/Store/`, `src/Error/`, `bin/` och `db/migrations/` finns

**Given** projektet är installerat
**When** `config.php` saknas
**Then** finns `config.php.dist` utanför `public_html/` med nycklar för DB-uppgifter och `cron_token`
**And** `config.php` är listad i `.gitignore`

**Given** applikationen startas
**When** en loggpost skrivs
**Then** hamnar den i en loggfil utanför `public_html/` via Monolog
**And** `GET /` (front controller) svarar 200 med en enkel healthcheck

### Story 1.2: Instrumenttabell, settings och seed-lista

As en operatör,
I want en instrumenttabell förfylld med ~20 ISIN och en settings-tabell med startvärden,
So that pipen har ett definierat, litet universum att arbeta mot.

**Acceptance Criteria:**

**Given** Phinx är konfigurerat
**When** `phinx migrate` körs
**Then** skapas tabellen `instrument` med `isin` som primärnyckel och kolumner för `name`, `list`, `avanza_orderbook_id` (nullable), `nordnet_instrument_id` (nullable), `first_seen`, `last_seen`
**And** skapas tabellen `settings` (`key` PK, `value`) förfylld med `run_after`, `batch_size`, `rate.avanza`, `rate.nordnet`, `queue.stale_after`

**Given** migrationerna är körda
**When** seed-steget körs (`bin/seed-instruments.php` eller motsvarande migration)
**Then** finns ~20 hårdkodade ISIN i `instrument` med `first_seen` satt
**And** `InstrumentRepository` och `SettingsRepository` kan läsa och skriva dessa rader

### Story 1.3: SourceAdapter-port, feltyper och id-uppslag

As en utvecklare,
I want ett gemensamt adaptergränssnitt, typade feltyper och id-uppslag mot Avanza och Nordnet,
So that varje instrument kan knytas till sitt id hos båda källorna utan att HTTP-detaljer läcker in i kärnan.

**Acceptance Criteria:**

**Given** `src/Error/`
**When** koden läses
**Then** finns klasserna `SchemaMismatch`, `NotFound` och `Transient`
**And** `src/Adapter/SourceAdapter` definierar `fetch()` och `resolveId()`

**Given** ett instrument utan `avanza_orderbook_id`
**When** `AvanzaAdapter::resolveId(isin)` anropas
**Then** slås id upp via Avanzas sök-endpoint och skrivs till `instrument` via `InstrumentRepository`
**And** samma sker för `NordnetAdapter::resolveId(isin)` mot `nnx_instrument_id`

**Given** ett ISIN som ingen källa känner
**When** id-uppslaget körs
**Then** returneras `NotFound`, instrumentet lämnas utan id och en `warning` loggas
**And** ingen `curl`/Guzzle-kod finns utanför `src/Adapter/`

### Story 1.4: Avanza-hämtningsadapter med schemakoll

As en operatör,
I want att ägarantal och kringdata hämtas från Avanza för ett instrument,
So that pipen får en normaliserad Avanza-datapunkt per dygn.

**Acceptance Criteria:**

**Given** ett instrument med giltigt `avanza_orderbook_id`
**When** `AvanzaAdapter::fetch(instrument)` anropas
**Then** returneras `{isin, source: 'avanza', as_of_date, number_of_owners, last_price, market_cap, fetched_at}` ur `market-guide`-svaret

**Given** ett Avanza-svar där ett förväntat fält saknas eller bytt form
**When** adaptern validerar svaret
**Then** returneras `SchemaMismatch` (aldrig en rad med `number_of_owners = null`) och en `warning` loggas

**Given** ett HTTP-fel som 429 eller 503
**When** adaptern tar emot svaret
**Then** görs ett omförsök efter en kort paus; om det också misslyckas returneras `Transient`

### Story 1.5: Nordnet-hämtningsadapter med schemakoll

As en operatör,
I want att ägarantal och kringdata hämtas från Nordnet för ett instrument,
So that pipen får en normaliserad Nordnet-datapunkt per dygn, oberoende av Avanza.

**Acceptance Criteria:**

**Given** ett instrument med giltigt `nordnet_instrument_id`
**When** `NordnetAdapter::fetch(instrument)` anropas
**Then** returneras `{isin, source: 'nordnet', as_of_date, number_of_owners, last_price, market_cap, fetched_at}` ur `instrument_search/stocklist`-svaret
**And** källans `statistics_timestamp` bevaras som underlag för `as_of_date`

**Given** ett Nordnet-svar där ett förväntat fält saknas eller bytt form
**When** adaptern validerar svaret
**Then** returneras `SchemaMismatch` (aldrig en rad med `number_of_owners = null`) och en `warning` loggas

**Given** ett HTTP-fel eller uteblivet svar
**When** adaptern tar emot det
**Then** görs ett omförsök efter en kort paus; om det också misslyckas returneras `Transient`

### Story 1.6: Append-only tidsserielagring

As en operatör,
I want att hämtade datapunkter lagras historiskt och idempotent,
So that historiken byggs upp och en omkörd natt inte skapar dubbletter.

**Acceptance Criteria:**

**Given** Phinx-migrationerna
**When** `phinx migrate` körs
**Then** skapas `owner_count_daily` med unik nyckel `(isin, source, as_of_date)` och kolumner för `number_of_owners`, `last_price`, `market_cap`, `fetched_at`

**Given** en normaliserad rad från en adapter
**When** `OwnerCountRepository::upsert(row)` anropas
**Then** skrivs raden med `as_of_date` som kalenderdatum i `Europe/Stockholm` (ur Nordnets `statistics_timestamp` när det finns, annars körningsdatum)
**And** `fetched_at` sätts till aktuell UTC-tidsstämpel

**Given** samma rad skrivs två gånger för samma dygn
**When** `upsert` körs igen
**Then** finns exakt en rad för `(isin, source, as_of_date)` och inget historiskt värde har skrivits över

### Story 1.7: Work queue och tidsboxad FetchRunner

As en operatör,
I want en kö som betas av i tidsboxade portioner,
So that en körning kan delas över flera cron-pass utan att överskrida exekveringstiden.

**Acceptance Criteria:**

**Given** Phinx-migrationerna
**When** `phinx migrate` körs
**Then** skapas `work_queue` med `isin`, `status` (`pending|claimed|done|failed`), `run_date`, `claimed_at`

**Given** ett aktivt universum
**When** `Enqueue` körs för dagens datum
**Then** skapas en `pending`-rad per instrument (idempotent per `(isin, run_date)`)

**Given** en fylld kö
**When** `FetchRunner` körs
**Then** claimas upp till `batch_size` jobb atomiskt (`UPDATE ... WHERE status='pending' ... LIMIT n`)
**And** för varje claimat jobb anropas Avanza- och Nordnet-adaptern var för sig, resultaten lagras via `OwnerCountRepository`, och jobbet sätts till `done`
**And** `FetchRunner` avslutar rent när tidsboxen (~60–90 s) löper ut, med återstående jobb kvar som `pending`

**Given** ett claimat jobb där en adapter returnerar `Transient` (efter adapterns egna omförsök)
**When** `FetchRunner` tar emot det
**Then** återställs jobbet till `pending`, `FetchRunner` går vidare till nästa jobb och kraschar inte

**Given** en `FetchRunner`-slice som bearbetar upp till `batch_size` jobb (NFR8)
**When** slicen körs via `/cron/work` (web-PHP-kontext, inte CLI)
**Then** håller sig minnesanvändningen under web-PHP:s `memory_limit` på Loopia (mål: 256 MB)
**And** `batch_size` startvärde i `settings` är satt så att en slice ryms med marginal

### Story 1.8: Minimal körningslogg

As en operatör,
I want att varje körning lämnar en rad jag kan inspektera,
So that jag ser att pipen levde och ungefär vad den gjorde — från första storyn den existerar.

**Acceptance Criteria:**

**Given** Phinx-migrationerna
**When** `phinx migrate` körs
**Then** skapas `ingest_run` med `started_at`, `finished_at`, `instrument_count`, `ok_count`, `fail_count`, `run_type`

**Given** en `FetchRunner`- eller `Enqueue`-körning
**When** den avslutas
**Then** skrivs en `ingest_run`-rad via `RunRepository` med tider och räknare
**And** raden går att läsa i efterhand (via `bin/`-skript)

### Story 1.9: Cron-endpoints med token och kör-inte-före-tid

As en operatör,
I want skyddade endpoints som Loopias URL-cron kan anropa,
So that pipen kör automatiskt vid rätt tid utan att vara publik.

**Acceptance Criteria:**

**Given** front controllern i `public_html/`
**When** `GET /cron/refill?token=…` anropas med rätt token
**Then** körs `Enqueue` mot seed-listan för dagens datum
**And** `GET /cron/work?token=…` kör en `FetchRunner`-slice

**Given** ett anrop med fel eller saknad token
**When** endpointen träffas
**Then** svaras 403 och inget arbete utförs (jämförelse via `hash_equals`)

**Given** `settings.run_after` är satt till t.ex. `22:00`
**When** `/cron/work` anropas före den tiden
**Then** utförs ingen hämtning och svaret anger att körfönstret inte är öppet

### Story 1.10: Loopia-driftsättning och deploy-runbook

As en operatör,
I want ett repeterbart sätt att lägga upp koden på Loopia och köra migrationer,
So that jag kan driftsätta nya versioner utan att klicka runt i en filhanterare.

**Kända Loopia-fakta (verifierade 2026-09-08 via SSH):** hemkatalog med en mapp per
domän (ingen delad `public_html/`); `rsync` 3.4.4, `composer` och `php` (8.5 på skalet,
`memory_limit` 1024M på CLI) finns i PATH; `pdo_mysql`, `curl`, `mbstring`, `json`
laddade. Appen läggs i `~/stockpicker/` med `config.php` och `src/` där, och en subdomän
(t.ex. `stockpicker.<domän>`) vars docroot pekar på `~/stockpicker/public_html/`.

**Acceptance Criteria:**

**Given** ett committat projekt utan `vendor/`
**When** `bin/deploy.sh` körs
**Then** synkas källkoden till `~/stockpicker/` på Loopia över SSH/rsync med `--delete`, utom `config.php`, `.git/`, `vendor/`, `_bmad-output/`, tester och `docs/`
**And** `composer install --no-dev --optimize-autoloader` körs på servern via SSH så att `vendor/` byggs mot Loopias PHP
**And** ett lokalt byggt `vendor/` som synkas med är den dokumenterade fallbacken om server-composer inte är tillgängligt

**Given** en färsk deploy
**When** `vendor/bin/phinx migrate -e production` körs över SSH
**Then** appliceras utestående migrationer mot MariaDB-databasen på Loopia

**Given** en första driftsättning
**When** runbooken följs
**Then** ligger `config.php` på plats (manuellt kopierad, aldrig via rsync) i `~/stockpicker/` utanför docroot
**And** subdomänens docroot pekar på `~/stockpicker/public_html/`
**And** Loopias tre URL-cron-jobb (`/cron/refill`, `/cron/work`, `/cron/derive`) är registrerade med rätt token
**And** en runbook i repot (`docs/deploy.md`) beskriver stegen och de Loopia-specifika förutsättningarna (SSH aktiverat, subdomän + docroot till underkatalog, web-PHP-version och dess `memory_limit`/`max_execution_time`, URL-cronens tidsgräns och minsta intervall)

### Story 1.11: End-to-end-röktest mot seed-listan

As en operatör,
I want en manuell helkörning av hela pipen mot de ~20 seed-instrumenten,
So that jag ser riktiga rader dyka upp och vet att kedjan håller innan jag litar på cron.

**Acceptance Criteria:**

**Given** ett driftsatt system med seed-listan laddad och käll-id:n upplösta
**When** `/cron/refill` körs och därefter `/cron/work` upprepas tills `work_queue` är tom
**Then** finns rader i `owner_count_daily` för de flesta seed-instrumenten, per källa, för dagens `as_of_date`
**And** en `ingest_run`-rad speglar körningen med rimliga räknare

**Given** ett seed-instrument vars käll-id inte kunde lösas
**When** helkörningen passerar det
**Then** hoppas det över utan att stoppa resten, och utfallet syns i loggen

**Given** en `Transient` från en källa under röktestet
**When** den inträffar
**Then** slutförs körningen ändå över flera `/cron/work`-pass och det jobbet får sitt värde till slut

---

## Epic 2: Live universum + överleva en månad oövervakad

Seed-listan byts mot den riktiga universum-synken mot Avanzas listning, och pipen härdas för trettio nätters oövervakad drift. Ordnad story-lista: live universum först, sedan härdning.

### Story 2.1: Avanza-universumadapter (listning)

_(Ersätter den tidigare Börsdata-varianten av Story 2.1, mergad 2026-09-10 men aldrig
driftsatt — Börsdatas API kräver betald Pro-prenumeration.)_

As en operatör,
I want att hela det svenska universumet hämtas från Avanzas publika aktielistning,
So that pipen täcker alla bolag på LC/MC/SC och First North, inte bara seed-listan.

**Acceptance Criteria:**

**Given** Avanzas publika aktiescreener (samma inofficiella endpoint-klass som ägarsiffrorna, ingen nyckel)
**When** `AvanzaUniverseAdapter::listUniverse()` anropas
**Then** returneras alla aktier på Nasdaq Stockholm Large/Mid/Small Cap och First North Stockholm med `name`, listetikett och Avanza `orderbookId` — ISIN finns inte i listningen och slås upp per instrument i Story 2.2
**And** listetiketten mappas till `LC | MC | SC | First North`; endast de fyra mållistorna efterfrågas

**Given** ett Avanza-svar med förändrad form (saknat eller typfelaktigt fält, eller en tom mållista)
**When** adaptern validerar det
**Then** returneras `SchemaMismatch` och ingen partiell lista sparas

**Given** att endpoint-vägen och filtersyntaxen fastställs i implementationen
**When** Story 2.1 är klar
**Then** är den riktiga endpointen, request-formen och svarsschemat dokumenterade i addendum, och testfixtures speglar ett riktigt svar

### Story 2.2: UniverseSync — daglig avstämning

As en operatör,
I want att instrumentlistan stäms av mot Börsdata varje natt,
So that tillkomna, avnoterade och listbytande bolag hanteras automatiskt.

**Acceptance Criteria:**

**Given** en lagrad instrumentlista och en färsk lista från Avanza
**When** `UniverseSync` körs
**Then** läggs nya instrument till med `first_seen` satt — ISIN slås upp via Avanzas `market-guide/stock/{orderbookId}`, Avanza `orderbookId` cachas ur listningen, och Nordnet-id slås upp (per Story 1.3)
**And** ISIN som saknas i Avanza-svaret får `last_seen` satt och markeras inaktiva (raderas inte)
**And** listbyten uppdaterar `instrument.list`

**Given** `UniverseSync` har körts
**When** körningen loggas
**Then** innehåller `ingest_run` (eller en dedikerad rad) antal tillkomna, borttagna och ändrade

**Given** `/cron/refill`
**When** endpointen anropas
**Then** kör den `UniverseSync` följt av `Enqueue` för de aktiva instrumenten (seed-listan används inte längre)

### Story 2.3: Delfeltolerans och fellogg per instrument

As en operatör,
I want att ett trasigt instrument eller en nere källa inte stoppar hela natten,
So that jag alltid får så mycket data som möjligt.

**Acceptance Criteria:**

**Given** en `FetchRunner`-slice där ett instrument ger `NotFound` eller `SchemaMismatch`
**When** felet inträffar
**Then** loggas det per instrument, jobbet sätts till `failed`, och `FetchRunner` går vidare till nästa jobb

**Given** en källa som svarar med fel på alla anrop under en slice
**When** slicen körs
**Then** landar den andra källans data ändå
**And** `ingest_run` visar utfallet uppdelat per källa

### Story 2.4: Retry med exponentiell backoff

As en operatör,
I want att övergående fel återförsöks,
So that tillfälliga nätverksfel och strypning inte skapar luckor i serien.

**Acceptance Criteria:**

**Given** en adapter som returnerar `Transient`
**When** `FetchRunner` tar emot det
**Then** återförsöks anropet med exponentiell backoff upp till ett tak inom tidsboxen
**And** om det fortfarande misslyckas lämnas jobbet som `pending` till nästa cron-pass

**Given** ett `429`-svar
**When** det tas emot
**Then** ökar backoff-fönstret och anropstakten mot den källan sänks för resten av slicen (styrt av `settings.rate.<källa>`)

**Given** `FetchRunner`
**When** den gör externa anrop
**Then** sker de seriellt, aldrig parallellt

### Story 2.5: Återöppning av fastnade jobb

As en operatör,
I want att jobb som fastnat i `claimed` frigörs,
So that en avbruten tidsbox inte lämnar instrument obehandlade för alltid.

**Acceptance Criteria:**

**Given** en `work_queue`-rad i `claimed` äldre än `settings.queue.stale_after`
**When** `FetchRunner` startar en ny slice
**Then** återöppnas raden till `pending` innan nya jobb claimas

**Given** en normal slice som avslutas i tid
**When** den är klar
**Then** finns inga `claimed`-rader kvar från den slicen

### Story 2.6: Full körningslogg och schemaavvikelse-larm

As en operatör,
I want att schemaändringar hos de inofficiella endpointsen syns tydligt,
So that jag upptäcker en trasig källa direkt istället för efter veckor av nulldata.

**Acceptance Criteria:**

**Given** en körning
**When** den avslutas
**Then** innehåller `ingest_run` `ok_count`/`fail_count` per källa och antal `SchemaMismatch`

**Given** minst en `SchemaMismatch` under en körning
**When** körningen avslutas
**Then** höjs en synlig signal (larm-flagga på körningen och/eller e-post/notis), inte bara en loggrad

**Given** en status-inspektion
**When** operatören tittar
**Then** går de senaste körningarnas utfall och eventuella larm att se på ett ställe

---

## Epic 3: Härledda mått

Ovanpå den nu riktiga tidsserien beräknas de mått som blir grunden för det framtida analyslagret.

### Story 3.1: Deriver — beräkna härledda mått

As en analytiker (Stefan),
I want dagsförändring, glidande medel, up-streak och spik-score per instrument och källa,
So that jag kan studera ägarutvecklingen över tid istället för bara råa nivåer.

**Acceptance Criteria:**

**Given** en `owner_count_daily`-serie för `(isin, source)`
**When** `Deriver` körs
**Then** beräknas `delta_1d`, `pct_1d`, `sma_7`, `sma_30`, `sma_90`, `up_streak` och `spike_score` med MariaDB window functions
**And** måtten beräknas separat per källa (Avanza, Nordnet) och slås aldrig ihop till ett
kombinerat mått (beslutat 2026-09-11, jfr NFR6 — samma princip som `owner_count_daily` redan
följer)

**Given** valet mellan SQL-vy och materialiserad tabell
**When** `Deriver` implementeras
**Then** används en SQL-vy (beslutat 2026-09-11 — ingen materialisering, ingen refresh-logik;
`/cron/derive` behöver inte trigga någon ombyggnad) och `Deriver` skriver aldrig till
`owner_count_daily`

**Given** en instrument-källa med färre än 7 datapunkter
**When** måtten beräknas
**Then** returneras `null` för de mått som kräver mer historik, utan fel

### Story 3.2: /cron/derive-endpoint

As en operatör,
I want att måtten uppdateras automatiskt efter att kön betats av,
So that de alltid speglar senaste natten.

**Acceptance Criteria:**

**Given** en tömd `work_queue` för dagens datum
**When** `GET /cron/derive?token=…` anropas
**Then** kör `Deriver` och uppdaterar/materialiserar måtten
**And** fel eller saknad token ger 403

### Story 3.3: Uttag av en akties serie och mått

As en analytiker (Stefan),
I want att hämta hela ägarserien plus alla mått för ett instrument,
So that jag kan granska ett bolag inför ett köp.

**Acceptance Criteria:**

**Given** ett ISIN med insamlad historik
**When** uttaget körs via ett `bin/`-skript över SSH (beslutat 2026-09-11 — inget nytt skyddat
läs-endpoint; samma mönster och renderingsdisciplin som `bin/show-runs.php`)
**Then** returneras hela serien per källa för hela den insamlade perioden, med de härledda måtten

**Given** ett ISIN utan data
**When** uttaget körs
**Then** returneras ett tomt men välformat svar, inget fel

---

## Epic 4: Webb-UI — bläddra ägarantal-trender

Ovanpå tidsserien och de härledda måtten (Epic 1–3) får Stefan en autentiserad, mobilanpassad webbyta för att själv bläddra, ranka, filtrera och bevaka aktier utifrån ägarantalstrender.

### Story 4.1: Inloggning

As Stefan (den enda användaren),
I want att logga in med användarnamn och lösenord och få en varaktig inloggning,
So that jag slipper autentisera om varje morgon från mobilen.

**Acceptance Criteria:**

**Given** ingen giltig sessionscookie
**When** jag öppnar valfri autentiserad route
**Then** omdirigeras jag tyst till `/login`, utan felmeddelande (kan inte skiljas från en förstagångsbesökare, AD-13)

**Given** `/login`-formuläret
**When** jag skickar korrekt användarnamn och lösenord
**Then** verifieras lösenordet med `password_verify()` mot `config.php`
**And** en HMAC-signerad sessionscookie sätts med utgångstid nu + 30 dagar
**And** jag omdirigeras till Topplista (`/`)

**Given** `/login`-formuläret
**When** jag skickar fel användarnamn eller lösenord
**Then** laddas sidan om och visar "Fel användarnamn eller lösenord." inline under formuläret
**And** inget utelåsningsmeddelande visas

**Given** en cookie med giltig signatur vars utgångstid har passerat
**When** jag öppnar en autentiserad route
**Then** visas inloggningsformuläret med "Sessionen har gått ut. Logga in igen."

**Given** en cookie med saknad eller ogiltig/manipulerad signatur
**When** jag öppnar en autentiserad route
**Then** behandlas den exakt som en saknad cookie (tyst till inloggningsformuläret, ingen skillnad i felmeddelande)

**Given** `/health`
**When** den anropas utan session
**Then** svarar den fortsatt publikt, utan autentisering, med samma JSON-svarsform som tidigare (flyttad hit från `/`)

**Given** projektets `config.php.dist`
**When** den uppdateras för den här storyn
**Then** innehåller den nycklar för webb-inloggningens användarnamn, bcrypt-hashat lösenord och sessionscookiens HMAC-nyckel, utöver befintliga DB-uppgifter och `cron_token` (AD-8, AD-13)

### Story 4.2: Topplista med bevakningsstjärnans mekanik

As Stefan,
I want att se de tio aktier som toppar ägarantal eller stadig tillväxt, och kunna bevaka direkt från listan,
So that jag kan göra min morgonkaffekoll på några sekunder.

**Acceptance Criteria:**

**Given** en giltig session
**When** jag öppnar `/` (Topplista)
**Then** visas topp 10 instrument för källan Avanza och rankningsläget Flest ägare (default)
**And** varje rad renderas som en Leaderboard row (rank, stjärna, namn, badge-rad, Sparkline, ägarantal + Delta chip) enligt `DESIGN.md`-tokens

**Given** Topplistan
**When** jag växlar Source switcher till Nordnet
**Then** hämtas och rankas listan om för Nordnets data, utan att någonsin slå ihop med Avanzas antal (FR16)
**And** det aktiva Ranking-mode-läget består

**Given** Topplistan
**When** jag växlar Ranking-mode toggle till Stadig tillväxt
**Then** rankas listan efter trendkvalitet (hög `up_streak`, uppåttrendande `sma_30`, lågt `spike_score`) istället för rått ägarantal
**And** det aktiva källvalet består

**Given** ett instrument med `up_streak` ≥ 1
**When** raden renderas
**Then** visas en Streak badge "🔥 {n}d"
**Given** `up_streak` = 0
**Then** visas No-history badgens "flat"-variant istället

**Given** ett instrument med avvikande `spike_score`
**When** raden renderas
**Then** visas en Spike badge "⚡ spike", oberoende av och eventuellt samtidigt med en Streak badge på samma rad

**Given** ett instrument med färre än 7 datapunkter
**When** raden renderas
**Then** visas Sparklinen dämpad och streckad
**And** badgen visar "{n}d spårade · ingen trend än"

**Given** rankningsläget Stadig tillväxt
**When** noll instrument kvalificerar
**Then** visas "Inga aktier med stadig tillväxt just nu." istället för en tom lista

**Given** en Leaderboard row
**When** jag trycker var som helst på raden utom stjärnan
**Then** navigerar appen till `/stock/{isin}`

**Given** en Leaderboard row
**When** jag trycker på Watchlist star
**Then** växlar stjärnan direkt (optimistiskt UI, `fetch()` POST mot `/watchlist/toggle`) utan sidladdning och utan navigering
**And** en `watchlist`-rad (`isin`, `starred_at`) upsertas eller tas bort i databasen via ett nytt `WatchlistRepository` (FR18, AD-15)

**Given** en utgången eller ogiltig session
**When** `/watchlist/toggle` anropas
**Then** svarar endpointen `401` med en minimal textkropp — aldrig inloggningssidans HTML
**And** `watchlist.js` gör en full sidladdning till `/login` (AD-12)

**Given** ett fel vid datahämtning från `src/Store/`
**When** Topplistan renderas
**Then** visas "Kunde inte hämta senaste data. [Försök igen]" istället för skelett/innehåll

**Given** mobil bredd (~390px)
**When** Topplistan renderas
**Then** staplas Source switcher och Ranking-mode toggle som två separata kontrollrader under headern
**Given** laptop-bredd (~900px+)
**Then** flyttar de till en gemensam header-rad bredvid sidtiteln, och raderna flödar om till ett rutnät (star/rank/namn/ägare/trend/delta/flagga)

**Given** mobil bredd
**When** jag drar nedåt högst upp på Topplistan (pull-to-refresh)
**Then** hämtas den senast beräknade ögonblicksbilden om
**Given** laptop-bredd
**Then** finns istället en synlig manuell "uppdatera"-funktion plus en "senast uppdaterad"-tidsstämpel

**Given** interaktiva element i en tät lista (Watchlist star, flikfältets objekt, Source switcher-/Ranking-mode-segment)
**When** de renderas
**Then** har de ett tryckmålsgolv på ~44px
**And** dämpad text/badge-text mot sin bakgrund (t.ex. `{colors.text-muted}` på `{colors.bg-surface}`/`{colors.nohist-bg}`) håller ett WCAG AA-liknande kontrastriktmärke (~4,5:1)

**Given** projektets databas
**When** den här storyn implementeras
**Then** skapas en Phinx-migration för tabellen `watchlist` (`isin` PK/FK `RESTRICT` mot `instrument`, `starred_at`)
**And** `WatchlistController` i `src/Web/` är den enda skribenten till tabellen

### Story 4.3: Aktiedetalj

As Stefan,
I want att se en akties fullständiga ägarhistorik och härledda mått för båda källorna samtidigt,
So that jag kan avgöra om en rörelse är en verklig trend innan jag bevakar eller agerar på den.

**Acceptance Criteria:**

**Given** en rad i Topplista, Fullständig lista eller Bevakningslista
**When** jag trycker på den (utom stjärnan)
**Then** navigerar jag till `/stock/{isin}` med instrumentets namn som skärmtitel

**Given** Aktiedetalj
**When** sidan laddas
**Then** hämtas båda källornas fulla serier ur `owner_count_metrics`, oavsett Source switcherns läge (AD-14)
**And** Trend overlay ritar den aktiva källan som primärlinje med Sparklinens kontextuella färglogik, och den andra källan som sekundärlinje i en fast, streckad färg oavsett trendriktning
**And** en legend under diagrammet parar ihop varje linjestil med sitt källnamn

**Given** Range picker (default Dag)
**When** jag väljer Vecka / 30d / 90d / År
**Then** ritas trendlinjen om för det redan laddade instrumentet
**And** gränserna Vecka/30d/90d matchar exakt backendens `sma_7`/`sma_30`/`sma_90`-fönster

**Given** ett vald intervall vars fönster överstiger tillgänglig historik (t.ex. 30d på ett instrument med <30 rader)
**When** intervallet väljs
**Then** visas "Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om {n} dagar" istället för ett avkortat eller missvisande diagram

**Given** Aktiedetaljens header
**When** jag trycker på Watchlist star
**Then** växlar den optimistiskt via samma `/watchlist/toggle`-endpoint som Story 4.2

**Given** ett fel vid datahämtning
**When** Aktiedetalj renderas
**Then** visas "Kunde inte hämta senaste data. [Försök igen]"

### Story 4.4: Fullständig lista

As Stefan,
I want att söka, filtrera och sortera alla spårade instrument för vald källa,
So that jag kan gräva förbi topp 10 under en helgresearch.

**Acceptance Criteria:**

**Given** Topplistans footer-åtgärd "Visa fullständig lista"
**When** jag trycker den
**Then** öppnas `/list` med alla spårade instrument (~740) för aktuell källa, sorterade fallande efter ägarantal som default

**Given** Fullständig lista
**When** jag skriver i sökfältet
**Then** smalnas listan av till instrument vars namn matchar fritextsökningen

**Given** Fullständig lista
**When** jag ändrar sorteringskontrollen till namn / ägarantal / %-förändring
**Then** sorteras listan om enligt valet

**Given** Fullständig lista
**When** jag slår på filtret Stadig tillväxt, spikflaggad, bara bevakade, eller en marknadslista (t.ex. Large Cap)
**Then** begränsas listan till matchande rader
**And** flera filter kan kombineras samtidigt med sökning och sortering

**Given** en kombination av sök/filter som ger noll rader
**When** listan renderas
**Then** visas "Inga resultat för dessa filter." med en synlig "rensa filter"-åtgärd

**Given** Source switcher
**When** jag växlar källa
**Then** hämtas och rankas listan om för den källan utan att slå ihop med den andra
**And** aktiva filter, sortering och sökning består

**Given** en rad i Fullständig lista
**When** jag trycker på den (utom stjärnan)
**Then** navigerar jag till `/stock/{isin}`
**Given** jag trycker på stjärnan istället
**Then** växlar bevakningen precis som på Topplistan (samma toggle-endpoint)

**Given** ny sök-/filter-/sorteringslogik
**When** den implementeras
**Then** landar all SQL som nya metoder i `src/Store/`-repositories, aldrig inline i `FullListController` (AD-14)

### Story 4.5: Bevakningslista-flik

As Stefan,
I want en egen flik som bara visar mina bevakade aktier,
So that jag snabbt kan se om momentum håller i sig utan att vada genom hela listan.

**Acceptance Criteria:**

**Given** flikfältet
**When** jag trycker på Bevakningslista
**Then** öppnas `/watchlist` direkt, ingen omväg via Topplista
**And** bara instrument med en `watchlist`-rad visas, var och en som en Leaderboard row med egen Streak badge, delta och Sparkline

**Given** inga bevakade instrument
**When** Bevakningslista renderas
**Then** visas "Inga aktier bevakade än." med en hänvisning tillbaka till Topplista/Fullständig lista, inget påtvingat onboarding-flöde

**Given** en rad i Bevakningslistan
**When** jag trycker på stjärnan
**Then** avmarkeras den optimistiskt (samma `/watchlist/toggle`-endpoint som Story 4.2) och raden försvinner ur listan utan sidladdning

**Given** en rad i Bevakningslistan
**When** jag trycker på den (utom stjärnan)
**Then** navigerar jag till `/stock/{isin}`

**Given** flikfältet på valfri autentiserad sida
**When** det renderas
**Then** är Topplista och Bevakningslista de två toppnivå-flikarna
**And** Fullständig lista nås bara via Topplistans footer-åtgärd, inte som en egen flik
**And** Inloggning ligger helt utanför flikfältet

---

## Epic 5: Post-launch-förbättringar av webb-UI

Sex saker Stefan märker eller vill ha i verklig användning efter Epic 4:s
driftsättning (2026-09-13): en mobil layoutbugg, en saknad länk till Avanza,
avsaknad av förklarande text, ett önskat tredje källäge, önskan om utveckling
över fler tidsperioder, och en daglig e-postdigest över förändringar i topp 10.

### Story 5.1: Mobil layoutbugg på listornas rader

As Stefan,
I want att rader på Topplista, Fullständig lista och Bevakningslista visas fullständigt på mobil utan att klippas av,
So that jag kan läsa ägarantal, delta och sparkline-etiketten även på telefonen.

**Acceptance Criteria:**

**Given** en rad med en lång sparkline-etikett (t.ex. "4d spårade · ingen trend än")
**When** raden renderas på en smal skärm (~390px, samma referensbredd som `DESIGN.md`)
**Then** får hela raden plats utan att klippas av eller tvinga fram horisontell scroll
**And** ägarantalet och Delta chip förblir synliga

**Given** samma delade radkomponent används på Topplista, Fullständig lista och Bevakningslista
**When** fixen implementeras
**Then** verifieras den på alla tre sidorna, inte bara Topplistan

**Given** fixen är på plats
**When** en lista öppnas i en 390px-bred vy
**Then** går ingen del av någon rad utanför sidans bredd

### Story 5.2: Länk till aktien på Avanza

As Stefan,
I want en länk från Aktiedetalj till aktiens sida på Avanza,
So that jag kan fördjupa mig ytterligare på Avanzas egen sida.

**Acceptance Criteria:**

**Given** det exakta URL-mönstret för Avanzas publika aktiesida är overifierat (bara API-endpoints är dokumenterade i `addendum.md`)
**When** story påbörjas
**Then** verifieras mönstret mot en riktig sida, samma disciplin som Story 2.1:s endpoint-verifiering, innan länken byggs

**Given** Aktiedetalj är öppen för ett instrument med cachat `avanza_orderbook_id`
**When** sidan renderas
**Then** visas en synlig länk till aktiens sida på Avanza, byggd från `orderbookId`
**And** länken öppnas i en ny flik, lämnar inte appens inloggade session

**Given** ett instrument utan cachat `avanza_orderbook_id`
**When** Aktiedetalj renderas
**Then** döljs länken tyst istället för att visa en trasig länk

### Story 5.3: Informationssida

As Stefan,
I want en informationssida som förklarar webbplatsens sidor och symboler,
So that jag inte behöver minnas vad varje badge betyder eller hur Stadig tillväxt rankas.

**Acceptance Criteria:**

**Given** jag är inloggad
**When** jag navigerar till informationssidan
**Then** beskrivs Topplista, Fullständig lista, Bevakningslista och Aktiedetalj i klartext

**Given** informationssidan
**When** jag läser den
**Then** förklaras varje symbol (🔥 Streak, ⚡ Spike, ☆/★ Watchlist star, Delta chip) och vad den betyder

**Given** rankningsläget Stadig tillväxt
**When** jag läser informationssidan
**Then** förklaras i vanligt språk (inte teknisk jargong) vad som gör att ett instrument kvalificerar och hur det rankas

**Given** informationssidan
**When** den renderas
**Then** är den nåbar från Topplistan (en synlig länk), inte bara via direkt URL

### Story 5.4: Källäge "Alla" på Topplista

As Stefan,
I want ett tredje källäge "Alla" på Topplistan som visar båda källornas ägarantal sida vid sida,
So that jag kan jämföra Avanza och Nordnet utan att öppna Aktiedetalj för varje aktie — utan att siffrorna någonsin summeras (NFR6).

**Acceptance Criteria:**

**Given** Topplistans källväxlare
**When** den renderas
**Then** visas tre lägen i ordningen Alla, Avanza, Nordnet — Alla först

**Given** källäget Alla är valt
**When** en rad renderas
**Then** visas både Avanzas och Nordnets ägarantal på raden separat (t.ex. "Avanza 1 234 · Nordnet 567")
**And** aldrig ett summerat tal

**Given** källäget Alla och rankningsläget Flest ägare
**When** Topplistan renderas
**Then** rankas raderna efter Avanzas ägarantal (samma primära källa som redan är standard överallt annars), med Nordnets antal visat bredvid — aldrig som rankningsgrund

**Given** källäget Alla och rankningsläget Stadig tillväxt
**When** Topplistan renderas
**Then** rankas raderna efter samma Stadig tillväxt-kriterier som i Avanza-läget (Avanzas `up_streak`/`spike_score`), med Nordnets ägarantal visat bredvid

**Given** ett instrument som saknar data hos en av källorna
**When** det renderas i läget Alla
**Then** visas den källan som "ingen data" istället för ett missvisande nolltal

**Given** Fullständig lista och Bevakningslista
**When** denna story implementeras
**Then** förblir de oförändrade — läget Alla är avgränsat till Topplistan i denna story

### Story 5.5: Procentuell utveckling över flera perioder

As Stefan,
I want att se procentuell utveckling över dag/vecka/90 dagar/år direkt på Topplistans rader,
So that jag snabbt kan bedöma om en trend håller i sig över flera tidshorisonter, inte bara dagens rörelse.

**Acceptance Criteria:**

**Given** `owner_count_metrics`-vyn
**When** Deriver beräknar mått för ett instrument/källa
**Then** beräknas även `pct_7d`, `pct_90d` och `pct_365d` (utöver befintliga `pct_1d`), med samma "null tills tillräckligt med rader"-princip som `sma_7`/`sma_30`/`sma_90`

**Given** ett instrument med färre rader än respektive fönster kräver
**When** måtten beräknas
**Then** är motsvarande `pct_Nd`-kolumn `NULL`, aldrig ett missvisande tal

**Given** en rad på Topplistan
**When** den renderas
**Then** visas procentuell utveckling för dag/vecka/90 dagar/år, med ett tydligt "otillräcklig historik"-tecken för perioder utan data

**Given** detta är den enda story i Epic 5 som ändrar `src/Pipeline/Deriver`
**When** story implementeras
**Then** följer ändringen samma disciplin som Story 3.1 (MariaDB window functions, SQL-vy, gap-medveten null-hantering) — Epic 4/5:s övriga "rör aldrig `src/Pipeline/`"-regel gäller inte just denna story

### Story 5.6: Daglig e-postdigest över förändringar i topp 10

As Stefan,
I want ett dagligt e-postmeddelande som visar vad som förändrats i topp 10 för
Flest ägare och Stadig tillväxt jämfört med föregående handelsdag,
So that jag kan se nya och försvunna namn samt rankningsförflyttningar utan att
själv behöva öppna Topplistan varje dag.

**Acceptance Criteria:**

**Given** dagens och föregående handelsdags `owner_count_metrics`-topp-10 för både
Flest ägare och Stadig tillväxt
**When** `/cron/derive` har beräknat dagens mått färdigt
**Then** skickas ett e-postmeddelande till `stockpicker@ryddmo.se` via SMTP
(`mailcluster.loopia.se`) som sammanfattar förändringarna i båda topplistorna

**Given** `run_date` inte var en riktig handelsdag (helg, eller en `trading_holiday`
— samma grind `cron_gate()` redan använder för övriga cron-rutter)
**When** derive körs
**Then** skickas inget e-postmeddelande alls den dagen, och ingen diff beräknas

**Given** ett instrument som kommit in i eller fallit ur topp 10 sedan föregående
handelsdag
**When** e-postmeddelandet byggs
**Then** listas det under "IN"/"UT" för respektive topplista

**Given** ett instrument som fanns i topp 10 både igår och idag men bytt placering
**When** e-postmeddelandet byggs
**Then** visas förflyttningen med en riktningspil och plats-siffror (t.ex.
"Investor B ↑ #7→#4")

**Given** fler än 5 förändringar (in/ut/förflyttning sammanräknat) i en topplista
**When** e-postmeddelandet byggs
**Then** listas de fem första individuellt och resten som "+N till"

**Given** SMTP-uppgifterna för `stockpicker@ryddmo.se`
**When** de konfigureras
**Then** hämtas de från miljövariabler, aldrig hårdkodade eller committade —
samma mönster som `Config::cronToken()`

**Given** en misslyckad SMTP-sändning (fel lösenord, cluster nere, etc.)
**When** felet inträffar
**Then** loggas det via `Logging::logger()` precis som andra cron-fel, och
derive:s egentliga jobb (att beräkna måtten) påverkas inte — digest-steget är
en egen, isolerad klass som inte kan fälla resten av `/cron/derive`

**Given** den allra första körningen, utan en "igår"-rad att jämföra mot
**When** e-postmeddelandet byggs
**Then** hanteras avsaknaden av föregående dag utan krasch (t.ex. genom att
hoppa över sändningen den dagen tills det finns något att diffa mot)
