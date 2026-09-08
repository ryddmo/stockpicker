---
stepsCompleted: [step-01-validate-prerequisites, step-02-design-epics, step-02-design-epics-revised-via-party, step-03-create-stories, step-03-create-stories-revised-via-party, step-04-final-validation]
inputDocuments:
  - _bmad-output/specs/spec-stockpicker/SPEC.md
  - _bmad-output/planning-artifacts/architecture/architecture-stockpicker-2026-09-08/ARCHITECTURE-SPINE.md
  - _bmad-output/planning-artifacts/briefs/brief-stockpicker-2026-09-08/addendum.md
---

# stockpicker - Epic Breakdown

## Overview

Detta dokument bryter ner kraven från SPEC.md (i PRD:ns ställe), arkitektur-spinen och
addendum till implementerbara stories för v1: den nattliga datainsamlingsmotorn.

## Requirements Inventory

### Functional Requirements

FR1: Systemet håller en aktuell lista över svenska bolag på Nasdaq Stockholm Large/Mid/Small Cap och First North, hämtad från Börsdata och avstämd varje natt (tillkomna, avnoterade, listbytande bolag); varje avstämning loggar antal ändrade.
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

### NonFunctional Requirements

NFR1: Systemet körs helt på Loopia delat webbhotell (Privatpaket): PHP 8.3, MariaDB 10.6. Ingen annan runtime eller databas.
NFR2: Nattarbetet triggas enbart av Loopias URL-cron (HTTP GET); inget steg får förutsätta att det slutförs i en enda invokation; systemet tål exekveringstidsgräns och en cron-instans i taget.
NFR3: Externa anrop sker seriellt, strypta per källa (anrop/s från `settings`), med exponentiell backoff vid 429 eller strypning; inga parallella massanrop.
NFR4: Endast personligt bruk — ingen komponent exponerar hämtad data utåt; anropsvolymen hålls låg; cron-endpoints kräver en hemlig token.
NFR5: En användare, en installation — ingen inloggning bortom cron-token, ingen fleranvändarhantering, ingen SLA.
NFR6: Avanzas och Nordnets ägarantal summeras aldrig (olika populationer, ingetdera är det legala aktieägarantalet).
NFR7: Ingen historisk backfill — tidsserien börjar vid första körningen.
NFR8: Datavolymen är liten (~730 000 rader/år); körningen ska rymmas inom 256 MB PHP-minne.
NFR9: Hemligheter (DB-uppgifter, cron-token) ligger i `config.php` utanför webroot, aldrig i versionshantering; drift-parametrar i en `settings`-tabell.

### Additional Requirements

- **Greenfield, ingen starter-template.** Composer-projekt utan ramverk. Beroenden: `guzzlehttp/guzzle ^7.9 || ^8.0`, `monolog/monolog ^3.11`, `robmorgan/phinx ^0.16.12`.
- **Paradigm:** pipes-and-filters (`UniverseSync → Enqueue → FetchRunner → Normalizer → Deriver`) + ports-and-adapters. All källåtkomst bakom `SourceAdapter`-interface i `src/Adapter/`; ingen HTTP/URL utanför `src/Adapter/` (AD-1).
- **Adapterkontrakt:** `fetch` returnerar normaliserad rad `{isin, source, as_of_date, number_of_owners, last_price, market_cap, fetched_at}` eller typat fel `SchemaMismatch | NotFound | Transient` (AD-2).
- **Katalogstruktur:** `public_html/` (tunn front controller: `/cron/refill`, `/cron/work`, `/cron/derive`), `src/{Adapter,Pipeline,Store,Error}/`, `bin/`, `db/migrations/`, `config.php` utanför webroot, `vendor/`.
- **Cron-endpoints:** `/cron/refill` (dagligen 00:00 → `UniverseSync` + `Enqueue`), `/cron/work` (var 5:e min → `FetchRunner`, tidsbox ~60–90 s), `/cron/derive` (efter kön tom → `Deriver`). Autentiseras med token via `hash_equals` mot `config.php`.
- **Databasåtkomst enbart genom `src/Store/`-repositories** (PDO, ingen ORM): `InstrumentRepository`, `OwnerCountRepository`, `QueueRepository`, `RunRepository`, `SettingsRepository`. Ingen SQL i pipeline eller adapter.
- **`work_queue`-tillståndsmaskin:** `pending → claimed → done | failed`. Bara `Enqueue` skapar `pending`; bara `FetchRunner` gör övriga övergångar; `claimed`-rad äldre än `queue.stale_after` återöppnas till `pending` (AD-5).
- **Dataägande (AD-3):** `instrument` skrivs bara av `UniverseSync`; `owner_count_daily` är källindelad — varje adapterflöde skriver bara sina egna `source`-rader; `Deriver` läser fakta, skriver dem aldrig.
- **Tabeller:** `instrument`, `owner_count_daily`, `work_queue`, `ingest_run`, `settings`. `snake_case`, singular tabellnamn. ISIN naturlig nyckel överallt; Avanza/Nordnet-id är cachade attribut på `instrument`.
- **Migrationer via Phinx**; första migrationen sätter kanoniska `settings`-nycklar (`run_after`, `batch_size`, `rate.<källa>`, `queue.stale_after`).
- **Deploy:** SSH + Composer; `vendor/` byggs och laddas upp; migrationer körs manuellt via SSH.
- **Härledda mått:** MariaDB window functions; SQL-vy vs materialiserad tabell avgörs vid implementation.
- **Loggning:** Monolog till fil; `warning` för schemaavvikelse, `error` för oväntat undantag.
- **Öppna frågor att hantera i drift:** Börsdatas gratisnivå-täckning overifierad; Nordnets uppdateringstid okänd; Loopias exekveringstidsgräns för URL-cron okänd (supportfråga före drift).

### UX Design Requirements

Ingen UX — inget användargränssnitt i v1.

### FR Coverage Map

FR1: Epic 2 - Live universum-avstämning mot Börsdata (Epic 1 kör mot en hårdkodad seed-lista)
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

## Epic List

### Epic 1: Tunn end-to-end-skiva — nattlig insamling bevisad på en seed-lista
Varje lager, tunt: projektskelett (Composer utan ramverk, katalogstruktur, `config.php`, Phinx-migrationer, cron-endpoints), `SourceAdapter`-porten, Avanza- och Nordnet-adaptrarna som separata stories, kö + tidsbox, append-only-lagring med idempotens, ISIN-matchning, datumfälten. Instrumenten kommer från en hårdkodad lista på ~20 ISIN. Plus golvet av FR12/FR13: ett förändrat/saknat fält kastar `SchemaMismatch` i stället för att skriva null, körningsloggen finns från den story `FetchRunner` föds i (inte sist), adaptrarna gör ett ett-skotts omförsök vid `Transient` så natt ett överlever kontakt, och en avslutande story kör hela pipen mot seed-listan och ser riktiga rader dyka upp. Efter epicen finns riktig data på disk och pipen kör oövervakad mot seed-listan.
**FRs covered:** FR2, FR3, FR4, FR5, FR6, FR10, FR11, FR12 (minimalt), FR13 (minimalt), FR9 (ett-skotts omförsök)

### Epic 2: Live universum + överleva en månad oövervakad
Byt seed-listan mot den riktiga Börsdata-synken: FR1, daglig avstämning, churn-loggning. Och rustningen: delfel förlorar inte en natt (FR8), övergående fel återförsöks med backoff (FR9), fastnade `claimed`-jobb återöppnas, körningsloggen får lyckade/misslyckade per källa och schemaavvikelser, schemaändringar blir larm — inte bara en loggrad. Ordnad story-lista: live universum först, sedan härdningsstories utifrån vad de första veckorna faktiskt kastar. Återkopplingsgränsen ligger inuti den här epicen.
**FRs covered:** FR1, FR8, FR9, FR12 (fullt), FR13 (fullt)

### Epic 3: Härledda mått
Ovanpå tidsserien går det att hämta dagsförändring, procentuell förändring, glidande medel (7/30/90), antal dygn i följd med nettoökning och spik-/avvikelseindikator per instrument och källa — grunden för det framtida analyslagret.
**FRs covered:** FR7

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

### Story 1.10: End-to-end-röktest mot seed-listan

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

Seed-listan byts mot den riktiga Börsdata-synken, och pipen härdas för trettio nätters oövervakad drift. Ordnad story-lista: live universum först, sedan härdning.

### Story 2.1: Börsdata-adapter för universumlistan

As en operatör,
I want att hela det svenska universumet hämtas från Börsdata,
So that pipen täcker alla bolag på LC/MC/SC och First North, inte bara seed-listan.

**Acceptance Criteria:**

**Given** en giltig Börsdata API-nyckel i `settings` eller `config.php`
**When** `BorsdataAdapter::listUniverse()` anropas
**Then** returneras alla instrument på Nasdaq Stockholm Large/Mid/Small Cap och First North Growth Market med `isin`, `name` och listetikett
**And** listetiketten mappas till `LC | MC | SC | First North`

**Given** ett Börsdata-svar med förändrad form
**When** adaptern validerar det
**Then** returneras `SchemaMismatch` och ingen partiell lista sparas

### Story 2.2: UniverseSync — daglig avstämning

As en operatör,
I want att instrumentlistan stäms av mot Börsdata varje natt,
So that tillkomna, avnoterade och listbytande bolag hanteras automatiskt.

**Acceptance Criteria:**

**Given** en lagrad instrumentlista och en färsk Börsdata-lista
**When** `UniverseSync` körs
**Then** läggs nya ISIN till med `first_seen` satt och id-uppslag mot Avanza/Nordnet triggas (per Story 1.3)
**And** ISIN som saknas i Börsdata-svaret får `last_seen` satt och markeras inaktiva (raderas inte)
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

**Given** valet mellan SQL-vy och materialiserad tabell
**When** `Deriver` implementeras
**Then** är valet dokumenterat och `Deriver` skriver aldrig till `owner_count_daily`

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
**When** uttaget körs (skyddad läs-endpoint eller `bin/`-skript)
**Then** returneras hela serien per källa för hela den insamlade perioden, med de härledda måtten

**Given** ett ISIN utan data
**When** uttaget körs
**Then** returneras ett tomt men välformat svar, inget fel
