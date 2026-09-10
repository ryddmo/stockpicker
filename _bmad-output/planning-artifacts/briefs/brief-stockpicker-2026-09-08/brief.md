---
title: "Product Brief: Stockpicker"
status: draft
created: 2026-09-08
updated: 2026-09-10
---

# Product Brief: Stockpicker

## Sammanfattning

Stockpicker är ett personligt analysverktyg som ska hjälpa mig att välja enskilda
aktier på ett mer underbyggt sätt än jag gör idag, då jag investerar i indexfonder.
Jag är öppen för att ta något större risk med en del av kapitalet, men bara i stabila
bolag som sköter sig över tid — inte kortsiktiga affärer.

Den signal jag vill bygga verktyget kring är **antalet ägare av en aktie hos Avanza
respektive Nordnet**, följt dag för dag. Hypotesen är att ett stadigt växande antal
ägare över lång tid säger något om ett bolags dragningskraft som pris och volym inte
fångar.

Första versionen bygger bara **insamlingsmotorn**: ett nattligt jobb som hämtar
ägarantal och kringdata för svenska bolag på Large/Mid/Small Cap och First North, och
lagrar det som en tidsserie. Målet med dokumentet är att ge ett tydligt kravunderlag för
den tekniska beskrivningen av datahämtningen; presentation och analys kommer senare.

## Problemet

När jag överväger att köpa en enskild aktie har jag idag ingen egen, strukturerad bild
av hur intresset för bolaget utvecklas bland vanliga sparare. Jag kan titta på pris,
volym och nyckeltal, men:

- **Ägarantal visas bara som en ögonblicksbild.** Avanza och Nordnet visar hur många av
  deras kunder som äger aktien just nu. Historiken finns inte lätt tillgänglig på ett
  sätt jag kontrollerar, och jag kan inte definiera mina egna härledda mått på den.
- **Jag kan inte skilja långsiktig trend från tillfällig hype.** En aktie som trendar i
  sociala medier kan få en spik i ägarantal på några dagar. Det jag vill åt är den
  långsamma, uthålliga uppgången.
- **Jag saknar ett beslutsunderlag jag litar på.** Utan egen data och egna mått blir
  aktieval en magkänsla. Jag vill ha något att luta mig mot innan jag chansar.

Kostnaden för status quo är låg i kronor — det här är ett personligt projekt — men den
är reell: antingen avstår jag från enskilda aktier helt, eller så köper jag på lösa
grunder.

## Lösningen

Ett nattligt jobb som för varje svensk aktie i det definierade universumet hämtar:

- antal ägare hos Avanza,
- antal ägare hos Nordnet (med källans tidsstämpel),
- kringdata för sammanhang (namn, ISIN, lista, senaste kurs, börsvärde).

All hämtad data lagras som en **tidsserie** — en rad per aktie, källa och dag — så att
hela historiken byggs upp från dag ett och aldrig skrivs över. Ovanpå tidsserien beräknas
härledda mått (dagsförändring, glidande medel, spikindikator — se K10).

Insamlingsmotorn är hela leveransen för v1. Den ska gå att köra om utan att skapa
dubbletter och klara att en enskild aktie eller en hel källa tillfälligt fallerar utan
att hela körningen havererar.

## Avgränsning mot befintliga verktyg

Det finns redan tjänster som visar Avanza- och Nordnet-ägarantal med viss historik
(t.ex. infon.se, avanzakollen.se, och Börsdata). Stockpicker är **inte** ett försök att
konkurrera med dem. Skälen att bygga eget:

- **Egen data och egna mått.** Jag vill definiera exakt vilka härledda signaler som
  räknas fram — inte anpassa mig till någon annans vy.
- **Full historik som jag äger.** Tidsserien börjar när jag startar jobbet och växer
  utan takbegränsning eller beroende av en tredje parts lagringspolicy.
- **Grund för egen analys och egna beslutsregler senare.** V1 är datalagret; nästa steg
  bygger vidare på det.
- **Lärprojekt.** Att bygga det är en del av poängen.

Det finns ingen vallgrav här och det ska inte låtsas finnas en. Om ett befintligt verktyg
visar sig räcka är det ett rimligt utfall.

## Vem det är för

Endast jag. En användare, en installation. Inga andra intressenter, ingen delning av
data, ingen publik åtkomst. Detta förenklar i stort sett varje beslut nedåt: ingen
inloggning, ingen fleranvändarhantering, ingen SLA.

## Vad framgång är

- **Motorn samlar in korrekt.** Efter en månads drift finns en obruten daglig tidsserie
  för i princip hela universumet, med högst någon enstaka procent saknade datapunkter.
- **Historiken är användbar.** Jag kan för valfri aktie få fram ägarutvecklingen över
  hela den insamlade perioden och se de härledda måtten.
- **Den överlever endpoint-förändringar.** När Avanza eller Nordnet ändrar sitt API
  märker jag det via en tydlig signal (fel/larm), inte via tyst dataförlust.
- **Den påverkar faktiskt mina beslut.** På sikt: jag använder verktyget innan jag köper
  en enskild aktie. [ANTAGANDE — mjukt mål, mäts av mig själv]

## Scope

**Ingår i v1 (datainsamling):** hela insamlingskedjan för svenska bolag på Large/Mid/Small
Cap + First North — daglig universumavstämning mot Avanzas listning, nattlig hämtning av ägarantal
och kringdata från Avanza och Nordnet, instrumentmatchning via ISIN, tidsserielagring,
härledda mått, robust felhantering och schemakontroll. Se kravavsnittet K1–K12 för
detaljer.

**Ligger utanför v1:**

- All presentation: webbgränssnitt, grafer, dashboards, notiser.
- Automatiska köp-/säljsignaler eller regelmotor.
- Andra marknader än svenska, andra instrument än aktier (fonder, ETF:er, index).
- Euroclear/Holdings/Börsdata som källa för det totala legala aktieägarantalet.
- Realtidsdata, intradagsdata.
- Fleranvändarstöd, autentisering, molndrift, hög tillgänglighet.

## Krav: datahämtning

Kravunderlag för den tekniska beskrivningen. Detaljerade endpoint-, fält- och
schemauppgifter ligger i `addendum.md`.

**K1 — Universum.** Systemet ska underhålla en aktuell lista över instrument i
universumet (svenska LC/MC/SC + First North). Källa är **Avanzas publika listning** av
Stockholmsbörsens aktier (samma inofficiella endpoint-klass som ägarsiffrorna, se
addendum), som ger instrumenten med listtillhörighet, ISIN och Avanzas `orderbookId`.
Universumet lagras, inte hårdkodas.

**K1b — Daglig universumavstämning.** Första steget i det nattliga jobbet ska stämma av
den lagrade instrumentlistan mot Avanzas listning: bolag som tillkommit läggs till, bolag
som avnoterats markeras som borta (raderas inte — historiken behålls), bolag som bytt
lista uppdateras. För varje nytt bolag tas Avanza `orderbookId` direkt ur listningen och
Nordnet `nnx_instrument_id` slås upp en gång (via Nordnets sök-endpoint, nyckel ISIN);
båda cachas i `instrument`-tabellen. Avstämningen loggas (antal
tillkomna/borttagna/ändrade).

**K2 — Daglig hämtning.** För varje aktivt instrument i universumet ska jobbet, efter
universumavstämningen, en gång per dygn hämta: Avanza `numberOfOwners`, Nordnet
`number_of_owners` + `statistics_timestamp`, samt kringdata (namn, ISIN, lista, senaste
kurs, börsvärde).

**K3 — Schemaläggning.** Jobbet ska köras efter att Avanza uppdaterat sina siffror
(observerat ca 18–19 CET på vardagar). **Körtiden ska vara konfigurerbar** (inte
hårdkodad) så att den kan justeras utan kodändring — Nordnets exakta uppdateringstid är
okänd och behöver provas fram i drift. Jobbet ska gå även på helger, men hantera att
Avanza inte uppdaterar då (samma värde flera dagar).

**K4 — Idempotens.** Jobbet ska kunna köras om för samma dygn utan att skapa dubbletter.
Lagring sker som upsert per (instrument, källa, datum).

**K5 — Tidsserielagring.** All hämtad data lagras historiskt och skrivs aldrig över.
Datamodellen skiljer på dimensionsdata (instrument) och daglig faktadata (mätvärden per
dag och källa). Källans egen tidsstämpel sparas när den finns (Nordnet), så att
"hämtningsdatum" och "datans datum" kan skiljas åt.

**K6 — Instrumentmatchning.** Samma bolag hos Avanza och Nordnet ska knytas ihop via
ISIN. Instrument som bara finns hos en källa ska ändå lagras.

**K7 — Felhantering.** Ett fel på en enskild aktie eller ett utfall från en hel källa
ska loggas per instrument och inte avbryta resten av körningen. Misslyckade hämtningar
ska återförsökas med backoff. Efter körning ska det framgå hur många instrument som
lyckades/misslyckades per källa.

**K8 — Rate limiting.** Anropen mot Avanza och Nordnet ska strypas (riktvärde: ett fåtal
anrop per sekund) med exponentiell backoff vid 429 eller strypning. Inga parallella
massanrop.

**K9 — Kontrakts-/schemakontroll.** Svaren från båda endpoints ska valideras mot ett
förväntat schema. Om ett förväntat fält saknas eller byter form ska körningen ge en
tydlig felsignal i stället för att tyst spara null.

**K10 — Härledda mått.** Systemet ska kunna beräkna per instrument och källa:
dagsförändring i ägarantal, procentuell förändring, glidande medel (7/30/90 dagar),
antal dygn i följd med nettoökning, samt en avvikelse-/spikindikator mot den egna
trenden. Beräkningen kan ske vid lagring eller vid uttag — valet motiveras i den
tekniska beskrivningen.

**K11 — Efterlevnad.** Endast personligt bruk. Ingen redistribution eller publicering av
hämtad data. Anropsvolymen hålls låg. Detta är en uttalad begränsning, inte en
implementationsdetalj.

**K12 — Observerbarhet.** Varje körning ska lämna en körningslogg (starttid, sluttid,
antal instrument, lyckade/misslyckade per källa, upptäckta schemaavvikelser) som går att
inspektera i efterhand.

**Icke-funktionellt.** Enanvändarsystem. Datavolymen är liten (~1 000 instrument × 2
källor × 365 dagar ≈ 730 000 rader per år) och ska rymmas inom 256 MB PHP-minne.
Systemet driftas helt oövervakat på Loopia delat webbhotell (Privatpaket): PHP 8.3+,
MariaDB 10.11, och nattjobbet triggas enbart av Loopias URL-cron (HTTP GET) — ingen
CLI-cron, ingen egen server, ingen molninfrastruktur. Bindande stack- och
plattformsbeslut ligger i arkitektur-spinen.

> _Uppdaterad 2026-09-08: infrastrukturvalet (Loopia, MariaDB, URL-cron) fastställdes i
> arkitektur-spinen efter att briefen skrevs; tidigare formulering ("SQLite på laptop")
> är ersatt._
>
> _Uppdaterad 2026-09-10: universumkällan bytt från Börsdatas API till Avanzas publika
> listning — Börsdatas API kräver betald Pro-prenumeration (ingen gratisnivå). Se
> sprint-change-proposal-2026-09-10.md._

## Öppna frågor

- **Avanza-listningens täckning och form:** att Avanzas publika listning ger hela
  universumet (LC/MC/SC + First North) med listetikett, ISIN och `orderbookId`, och exakt
  endpoint-väg/filter, behöver verifieras mot faktiskt API-utfall (görs i Story 2.1).
- **Nordnets uppdateringstakt:** endast ~daglig enligt research, exakt tid odokumenterad.
  Hanteras genom konfigurerbar körtid (K3) — provas fram i drift.
- **Endpoint-stabilitet:** Avanza- och Nordnet-endpointsen för ägarantal är inofficiella
  och kan ändras utan förvarning. Ingen garanti att Nordnets `stocklist` förblir öppen
  utan session.
- **Härledda mått vid lagring vs vid uttag (K10):** avgörs i den tekniska beskrivningen.
- **Definition av "tillfällig topp":** medvetet uppskjuten — ska läras av utfall när det
  finns tillräckligt med historik.

## Vision

Om insamlingsmotorn fungerar blir nästa steg ett analyslager ovanpå tidsserien: en vy
där jag kan söka fram bolag vars ägarantal vuxit stadigt över månader eller år,
filtrera bort de som bara haft en tillfällig topp, och jämföra ägarutveckling mot
kursutveckling. På sikt kan det bli en uppsättning egna beslutsregler — inte
automatiska affärer, utan en kortlista att granska manuellt innan jag köper. Verktyget
förblir personligt; ambitionen är bättre egna beslut, inte en produkt för andra.
