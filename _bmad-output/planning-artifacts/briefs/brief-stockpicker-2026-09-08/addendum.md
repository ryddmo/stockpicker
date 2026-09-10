---
title: "Stockpicker — Addendum: teknisk detalj för datahämtning"
status: draft
created: 2026-09-08
updated: 2026-09-10
---

# Addendum — teknisk detalj för datahämtning

Underlag till den tekniska beskrivningen. Allt nedan bygger på research gjord
2026-09-08 mot de publika, inofficiella endpoints som Avanza och Nordnet använder på
sina egna webbplatser. Endpoint-vägar och fältnamn kan ändras utan förvarning.

## Källa: Avanza — universum (listning)

**Roll:** definierar *vilka* bolag som ingår — inte ägarsiffror. (Bytt från Börsdatas API
2026-09-10; Börsdatas API kräver betald Pro-prenumeration, ingen gratisnivå.)

- Samma inofficiella endpoint-klass som Avanzas ägarsiffror (`www.avanza.se/_api/...`),
  ingen autentisering, normal `User-Agent`-header.
- Avanzas webbplats har en aktielistning / screener som räknar upp Stockholmsbörsens
  aktier per lista. **Exakt endpoint-väg och filtersyntax är overifierad** och ska
  fastställas mot ett faktiskt svar i Story 2.1 (samma metod som för `market-guide` och
  `stocklist`: fånga ett riktigt svar, validera schema, dokumentera här).
  - `GET /_api/market-guide/stock/{orderbookId}` bär redan `isin`, `name` och
    `marketList` (t.ex. `"Large Cap Stockholm"`) — fälten finns Avanza-sidan.
- Mappa Avanzas listetikett → `LC | MC | SC | First North`; filtrera bort Spotlight, NGM,
  utländska listor och icke-aktier (ETF:er, index, certifikat).
- Avanza `orderbookId` kommer **direkt ur listningen** — inget separat id-uppslag mot
  Avanza behövs längre. Endast Nordnet `nnx_instrument_id` slås upp per nytt bolag.

**Universumavstämning (nattjobbets steg 1)**

1. Hämta aktuell aktielistning från Avanza, filtrerad till mållistorna.
2. Diffa mot lagrad `instrument`-tabell:
   - nytt ISIN → lägg till rad, sätt `first_seen`, cacha Avanza `orderbookId` ur svaret,
     slå upp Nordnet `nnx_instrument_id`.
   - ISIN saknas i Avanza-svaret → sätt `last_seen`, markera inaktiv (radera inte).
   - listbyte → uppdatera `list`.
3. Logga antal tillkomna / borttagna / ändrade i `ingest_run`.
4. Fortsätt till ägardatahämtning (K2) för aktiva instrument.

Nordnet-id slås upp en gång per nytt bolag (nyckel ISIN) och cachas — inte varje natt.

## Källa: Avanza

**Ägarantal per aktie**

```
GET https://www.avanza.se/_api/market-guide/stock/{orderbookId}
```

- Ingen autentisering. Endast en normal `User-Agent`-header krävs.
- Fält av intresse: `keyIndicators.numberOfOwners` (heltal). Exempel: Investor B,
  `orderbookId` 5247 → ~533 571.
- Övriga fält i svaret ger kringdata: namn, ISIN, listtillhörighet, senaste kurs,
  börsvärde, nyckeltal, nästa rapportdatum.

**Uppslag av `orderbookId`**

```
GET https://www.avanza.se/_api/search/global-search?query={fritext}
GET https://www.avanza.se/_api/search/filtered-search      (POST med filter)
```

- ETF:er: `/_api/market-etf/{id}`. Index: `/_api/market-index/{id}`. (Utanför scope
  för v1.)

**Uppdateringstakt**

- `numberOfOwners` uppdateras dagligen, ungefär 18:00–19:00 CET.
- Uppdateras inte på helger — söndag och måndag visar lördagens värde. Källa:
  avanzakollen.se metodnot. Ej realtid.

**Bibliotek**

- `pyavanza` (Python, read-only, wrappar exakt `market-guide/stock`, ingen auth) —
  https://github.com/claha/pyavanza
- `Qluxzz/avanza` (Python), `fhqvst/avanza` (JS), `codler/avanza-api` — fullständiga
  trading-/portfölj-API:er, kräver användarnamn + lösenord + obligatorisk TOTP-2FA.
  Behövs inte för marknadsdata.
- Home Assistant-komponenten `custom-components/sensor.avanza_stock` exponerar
  `numberOfOwners` direkt.

## Källa: Nordnet

**Ägarantal per aktie**

```
GET https://www.nordnet.se/api/2/instrument_search/query/stocklist?free_text_search={fritext}
```

- Enda header som krävs: `client-id: NEXT`. Ingen session.
- Fält av intresse:
  - `results[].statistical_info.number_of_owners` (heltal). Exempel: Investor B → ~69 589.
  - `results[].statistical_info.statistics_timestamp` — källans egen tidsstämpel.
  - `results[].nnx_info.nnx_instrument_id` — Nordnets interna instrument-id.
  - Dessutom: nyckeltal, börsvärde, nästa rapportdatum.
- `stocklist`-endpointen stödjer marknads-/listfilter — kandidat för att lösa upp
  universumet (K1). Exakt filtersyntax behöver verifieras.

**nExt API v2 (officiellt) — räcker inte**

- https://github.com/nordnet/next-api-v2-examples
- `POST /api/2/login/start` + `/login/verify` med en Ed25519-privatnyckel registrerad
  hos Nordnet (ansökan via Nordnet Trading Support, ingen testmiljö).
- `/api/2/instruments/{id}` ger instrumentmasterdata men **inte** ägarantal.
  `/statistics`, `/ownership`, `/shareholder_statistics` gav alla `NOT_FOUND` vid test.
- Slutsats: den publika `stocklist`-vägen är den enda praktiska källan till ägarantal.

**Uppdateringstakt**

- `number_of_owners` bär `statistics_timestamp` och observeras röra sig dagligen. Exakt
  kadens och tidpunkt är odokumenterad — behandla som ~daglig, osäker.

**Bibliotek**

- `fhqvst/nordnet` (JS), `denro/nordnet` (Go), `zjael/nordnet-api` — session via
  användarnamn/lösenord. Behövs inte för `stocklist`.

## Instrumentmatchning Avanza ↔ Nordnet

- Nyckel: **ISIN**. Båda källorna exponerar ISIN i sina svar.
- Instrument som bara finns hos en källa lagras ändå (K6).
- De två ägarsiffrorna mäter olika populationer ("unika Avanza-kunder" vs "unika
  Nordnet-kunder") och ska aldrig summeras rakt av som ett "totalt ägarantal". Totalen
  (legalt antal aktieägare) finns bara hos Euroclear/Holdings/Börsdata och ligger
  utanför scope.

## Datamodell (skiss)

**Dimensionstabell `instrument`**

| kolumn | not |
|---|---|
| isin | naturlig nyckel |
| name | |
| list | LC / MC / SC / First North |
| avanza_orderbook_id | nullable |
| nordnet_instrument_id | nullable |
| first_seen / last_seen | för av-/pånotering |

**Faktatabell `owner_count_daily`**

| kolumn | not |
|---|---|
| isin | FK |
| source | `avanza` \| `nordnet` |
| as_of_date | datans datum (källans tidsstämpel om den finns, annars körningsdatum) |
| fetched_at | när jobbet hämtade |
| number_of_owners | heltal |
| last_price | nullable |
| market_cap | nullable |
| PK (isin, source, as_of_date) | ger idempotens (K4) |

**Körningslogg `ingest_run`**

starttid, sluttid, antal instrument, lyckade/misslyckade per källa, upptäckta
schemaavvikelser.

Datavolym: ~1 000 instrument × 2 källor × 365 dagar ≈ 730 000 rader/år — ryms inom
256 MB PHP-minne. Databas: MariaDB 10.11 på Loopia, bundet av plattformen (se
arkitektur-spinen). Ett annat databasval för ett framtida analyslager är ett separat
beslut.

## Härledda mått (K10) — kandidater

Per (isin, source), över `number_of_owners`-serien sorterad på `as_of_date`:

- `delta_1d` = värde(t) − värde(t−1)
- `pct_1d` = delta_1d / värde(t−1)
- `sma_7`, `sma_30`, `sma_90` = glidande medel
- `up_streak` = antal dygn i följd med delta_1d > 0
- `spike_score` = (värde(t) − sma_30) / rullande standardavvikelse(30) — hög positiv
  = tillfällig topp snarare än trend; används för att *nedvikta* snarare än signalera

Exakt definition av "tillfällig topp" är inte fastställd (öppen fråga).

## Konfiguration (v1)

Uppdelningen är fastställd i arkitektur-spinen (AD-8): hemligheter i `config.php` utanför
`public_html/`, aldrig i versionshantering; drift-parametrar i `settings`-tabellen, lästa
vid varje körning.

- **`config.php`** (hemligt, utanför webroot): MariaDB-anslutningsuppgifter, cron-token
- **`settings`-tabell** (ändras utan deploy): `run_after` (kör-inte-före-tid, klockslag i
  `Europe/Stockholm` — justeras för att prova fram Nordnets uppdateringstid),
  `rate.avanza` / `rate.nordnet` (anrop/s) och backoff-parametrar, `batch_size`,
  `queue.stale_after`, vilka listor som ingår i universumet (LC/MC/SC/First North på/av)

Loopias URL-cron styr *när* endpointsen anropas; körfönstret gate:as dessutom av
`run_after` så det kan ändras utan att röra cron-schemat.

## Efterlevnad / ToS (bakgrund)

- Ingen av bankerna erbjuder ett sanktionerat publikt data-API. `market-guide` och
  `stocklist` är odokumenterade interna API:er.
- Avanzas `robots.txt` har inga restriktioner. Nordnets `robots.txt` blockerar bara
  inloggade konto-sidor. Båda bankernas kundvillkor förbjuder formellt systematisk/
  automatiserad extraktion och all redistribution.
- Många publika projekt konsumerar dessa endpoints öppet (pyavanza, HA-komponenter,
  infon.se, avanzakollen.se) utan känd åtgärd.
- Praktisk risk för personligt, lågvolyms, icke-redistribuerat bruk: låg — realistiskt
  begränsad till IP-strypning eller spärr, inte rättslig åtgärd. Risken stiger kraftigt
  vid redistribution, hög anropsvolym eller kommersiellt bruk.

## Osäkerhetsflaggor (från research)

- Inofficiella endpoint-vägar kan ändras utan förvarning (Avanza har bytt `id` →
  `orderbookId` tidigare).
- Nordnets uppdateringstid för ägarantal är overifierad.
- Rate limits för båda är okända.
- Ingen garanti att Nordnets `stocklist`-endpoint förblir öppen utan session.
