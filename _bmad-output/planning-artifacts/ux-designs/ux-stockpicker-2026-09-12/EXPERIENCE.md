---
name: stockpicker
status: final
sources: []
updated: 2026-09-12
---

# Stockpicker — Experience Spine

> Enanvändar, en-yta responsiv webbapp för att följa trender i ägarantal för svenska/nordiska aktier, hämtat från Avanza och Nordnet (aldrig sammanslaget). Ihopparad med `DESIGN.md` (visuell identitet). Mobile-first, måste även fungera bra på laptop.

## Grund

En enda responsiv webbplats — inte separata native-appar, inte två kodbaser för mobil/laptop. Inget namngivet UI-system; komponenterna är egenbyggda, specificerade i `DESIGN.md`. Exakt en användare, ett hårdkodat användarnamn/lösenord, ingen registrering, inget stöd för flera konton, inget lösenordsåterställningsflöde. Inloggning använder en långlivad token så att Stefan inte behöver autentisera om varje morgon från mobilen. `DESIGN.md` är referensen för visuell identitet — den här spinen är beteendet.

## Informationsarkitektur

| Yta | Nås från | Syfte |
|---|---|---|
| Inloggning | Appen öppnas kallt utan giltig session; visas även igen om sessionen någonsin går ut | Autentiserar den enda hårdkodade användaren; vid lyckad inloggning etableras den beständiga sessionen och landar på Topplista |
| Topplista | Landningsvy efter en giltig session; flikfält | Topp 10-aktier efter ägarantal, per källa. Hostar Avanza/Nordnet Source switcher och Ranking-mode toggle med "Flest ägare" / "Stadig tillväxt". Varje rad visar dag-till-dag-delta, Streak badge, Spike badge (när tillämpligt), och en Sparkline. En footer-åtgärd visar Fullständig lista. |
| Fullständig lista | "Visa fullständig lista"-åtgärden från Topplista | Alla ~740 spårade instrument för den aktuella källan. Sorterbar efter namn / ägarantal / %-förändring. Filtrerbar på spikflaggad, Stadig tillväxt, bara bevakade, och marknadslista (till exempel Large Cap kontra övriga). Stödjer sökning över hela instrumentmängden. |
| Bevakningslista | Flikfält (egen flik, inte ett Topplista-filter) | Bara bevakade aktier — snabbkoll-vyn för sådant Stefan redan har bestämt är värt att följa. |
| Aktiedetalj | Tryck på valfri rad i Topplista, Fullständig lista eller Bevakningslista | En akties fullständiga historik: Dag/Vecka/30d/90d/År Range picker, Trend overlay för båda källorna (fortfarande visuellt distinkta, aldrig sammanslagna), streak-/spike-detaljer, Watchlist star. Visar ett skonsamt tomt tillstånd när historiken är otillräcklig (se Tillståndsmönster). Visuell referens: [`mockups/stock-detail.html`](mockups/stock-detail.html). |

Navigationsmodell: ett beständigt flikfält med **Topplista** och **Bevakningslista** som de två toppnivå-flikarna (eftersom Bevakningslista är sin egen flik, inte ett Topplista-filter). Fullständig lista är en fördjupning nådd från Topplistans footer-åtgärd, inte en tredje flik — det är en "gå djupare"-yta, inte en destination Stefan startar en session på. Aktiedetalj är alltid en fördjupning från en radtryckning, aldrig en flik. Inloggning ligger helt utanför flikfältet, som grinden före allt annat.

## Röst och ton

Personlig, rakt på sak, skeptisk mot hype. Det här är ett verktyg för att avgöra om en rörelse är verklig, och det ska låta så — aldrig som att det försöker göra Stefan exalterad över en aktie. Den estetiska hållningen finns i `DESIGN.md`; det här är mikrocopy-disciplinen.

| Gör | Gör inte |
|---|---|
| "2 av 10 spikar just nu — inte samma sak som en trend." | "🚀 De här aktierna är glödheta idag!" |
| "Inte tillräckligt med historik än — kolla in igen om {n} dagar." | "Häng kvar för spännande insikter snart!" |
| "Inga aktier bevakade än." | "Din bevakningslista är tom! Lägg till din första favorit för att komma igång 🎉" |
| "⚡ spike" | "🔥 Het aktie!" |
| "Inga aktier med stadig tillväxt just nu." | "Inga resultat hittades. Prova att justera dina filter!" |
| Vanliga siffror: "+412 · 0,9 %" | Att kommentera en siffra: "+412 (grymt!)" |
| "Sessionen har gått ut. Logga in igen." | "Oj! Något gick fel 😅" |

## Komponentmönster

Bara beteendespecifikationer — visuella specifikationer finns i avsnittet Komponenter i `DESIGN.md`.

| Komponent | Används i | Beteenderegler |
|---|---|---|
| Leaderboard row | Topplista, Fullständig lista, Bevakningslista | Att trycka var som helst på raden utom stjärnan navigerar till Aktiedetalj. Stjärnan är ett oberoende tryckmål — att trycka på den växlar bevakningsstatus på plats, ingen navigering, ingen bekräftelse. |
| Streak badge | Leaderboard row, Fullständig lista-rad, Aktiedetalj | Visar bara den pågående sammanhängande uppgångs-streaken (`up_streak` från `owner_count_metrics`), formaterad "🔥 {n}d". Döljs (ersätts av "flat"-fallbacken) när streaken är 0. Det här är en känd asymmetri: backenden har idag ingen symmetrisk nedgångs-streak eller löpande +/−-dagräkning — designa inte en tvåsidig "+7 / −2"-indikator mot data som ännu inte finns; det är en backend-förbättring att lämna vidare, inte ett UI-problem att dölja över. |
| Spike badge | Leaderboard row, Fullständig lista-rad/flagg-kolumn, Aktiedetalj | Visas bara när `spike_score` är avvikande för det instrumentet. Oberoende av Streak badge — båda kan visas på samma rad, och ingen av dem antyder den andra. |
| Watchlist star | Leaderboard row, Fullständig lista-rad, Aktiedetalj-header | Fylld stjärna = bevakad. Tryckning växlar direkt (optimistiskt UI, ingen bekräftelsedialog — det här är en personlig lågrisklista, inte en destruktiv åtgärd). Tillståndet består mellan sessioner eftersom det bara finns en användare. |
| Delta chip | Leaderboard row, Fullständig lista-rad, Aktiedetalj | Icke-interaktiv, del av radens eget innehåll — inte ett eget tryckmål. Visar alltid antal och procent tillsammans (se `DESIGN.md`); att trycka på den gör samma sak som att trycka på resten av raden (öppnar Aktiedetalj). |
| Sparkline | Leaderboard row, Fullständig lista-rad, Aktiedetalj (som en linje inuti Trend overlay) | Icke-interaktiv, del av radens eget innehåll — inte ett eget tryckmål. Rent en blicksignal; Range picker och Trend overlay på Aktiedetalj är där en användare faktiskt interagerar med trenddata. |
| Source switcher | Topplista-header, Fullständig lista-header | Standard: Avanza. Tvåvägsväxlare, Avanza / Nordnet. Att växla hämtar och rankar om den aktuella vyn för den källan; slår aldrig ihop de två källornas siffror i en rad. Rankningsläge och aktiva filter består vid ett källbyte — bara den underliggande datan ändras. |
| Ranking-mode toggle | Bara Topplista-header | Standard: Flest ägare. "Flest ägare" (nivåbaserad, dagens råa ägarantal) kontra "Stadig tillväxt" (trendkvalitetsbaserad: hög uppgångs-streak, `sma_30`-uppåttrend, lågt `spike_score`). Att växla ändrar rankningsalgoritmen, inte källan. Fullständig lista använder sina egna explicita sorterings-/filterkontroller istället för den här växlaren. |
| Range picker | Aktiedetalj | Standard: Dag. Väljare med Dag / Vecka / 30d / 90d / År — de tre mittersta gränserna (Vecka/30d/90d) matchar exakt backendens egna `sma_7`/`sma_30`/`sma_90`-fönster, så intervallet Stefan väljer är alltid det intervall trendlinjen faktiskt är utjämnad över, aldrig en godtycklig kalenderhink. Att ändra intervall ritar om trenddiagrammet för det redan laddade instrumentet; om det begärda intervallet överstiger tillgänglig historik faller det tillbaka till läget "inte tillräckligt med historik än" (se Tillståndsmönster) istället för att rita en delvis eller missvisande linje. |

## Tillståndsmönster

| Tillstånd | Yta | Behandling |
|---|---|---|
| Otillräcklig historik (<7 rader) | Aktiedetalj, 7-dagars+ intervallvyer; Topplista/Fullständig lista-sparkline | Sparkline renderas dämpad/streckad (`{colors.text-muted}`); badgen visar "{n}d spårade · ingen trend än." Speglar backendens egen null-tills-N-rader-konvention — `sma_7` behöver 7 rader, så UI:t ritar helt enkelt inte en trend det inte har. |
| Otillräcklig historik för ett längre intervall (<30 eller <90 rader) | Aktiedetaljens Range picker | Kortare intervall (Dag/Vecka) renderas normalt om tillräckligt med rader finns; att välja 30d på ett instrument med <30 rader, eller 90d/År på ett med <90 rader, visar "Inte tillräckligt med historik för det här intervallet ännu — kolla in igen om {n} dagar" istället för ett trasigt eller avkortat diagram. |
| Tom bevakningslista | Bevakningslista-fliken | "Inga aktier bevakade än." med en enkel hänvisning tillbaka till Topplista/Fullständig lista — inget påtvingat onboarding-flöde. |
| Noll kvalificerande "Stadig tillväxt" | Topplista, läget Stadig tillväxt | "Inga aktier med stadig tillväxt just nu." Det här meddelandet skiljer uttryckligen noll kvalificerande aktier från att en tom lista är en bugg — vissa dagar kvalificerar ingenting, och UI:t ska säga det rakt ut istället för att visa en tom lista. |
| Inga matchande resultat | Fullständig lista (sökning/filter ger tillsammans noll rader) | "Inga resultat för dessa filter." med en synlig "rensa filter"-åtgärd. Skiljer sig från Topplistans "Noll kvalificerande Stadig tillväxt"-tillstånd — det här handlar om en användarstyrd sök-/filterkombination, inte om att datan i sig inte har något att säga. |
| Inloggningsfel | Inloggning | "Fel användarnamn eller lösenord." Visas inline under formuläret på den omladdade sidan (fullständig sidladdning — klassiskt formulär-post-och-återvisning, inte AJAX; inloggning sker för sällan, ~månadsvis, för att motivera annat), inget utelåsningsmeddelande (en användare, inget mål för säkerhetshärdning). |
| Sessionen har gått ut | Alla autentiserade ytor | Omdirigering till Inloggning med "Sessionen har gått ut. Logga in igen." Sessionen har en fast 30-dagars TTL från inloggning (inte inaktivitetsbaserad), så det här borde vara sällsynt — ungefär en månatlig ominloggning, inte en daglig. |
| Datahämtning misslyckades | Topplista, Fullständig lista, Aktiedetalj | "Kunde inte hämta senaste data. [Försök igen]" i stället för skelett/innehåll. Minimalt med avsikt — det här är ett personligt lågriskverktyg, inte en verksamhetskritisk app, så en enkel återförsöksåtgärd räcker; inga diagnostikdetaljer, inget auto-återförsök-med-backoff-meddelande visas för Stefan. |

## Interaktionsprimitiver

- Beteendet för radtryck-till-navigering och Watchlist star-växling specificeras i Komponentmönster (Leaderboard row, Watchlist star).
- Range picker på Aktiedetalj är en segmenterad kontroll (Dag/Vecka/30d/90d/År), tumanpassad för mobil — ett bekräftat beslut, inte en fri kalenderväljare, eftersom de fem granulariteterna är fasta och få till antalet.
- Uppdatering av Topplista: bekräftad pull-to-refresh-gest på mobil; en synlig manuell "uppdatera"-funktion plus en "senast uppdaterad"-tidsstämpel på laptop. Eftersom den underliggande datan kommer från ett cron-jobb en gång per dygn snarare än ett live-flöde, hämtar en uppdatering den senast beräknade ögonblicksbilden istället för att pollning sker kontinuerligt.
- Minsta tryckmålsstorlek: ~44px golv (standard mobilkonvention) för Watchlist star, flikfältets objekt och växlarsegment, eftersom dessa sitter tätt tillsammans i radlayouten och feltryckningar (till exempel stjärna kontra radnavigering) skulle vara aktivt irriterande på en datatät lista.
- Ingen dragning, ingen svep-för-att-radera, inga long-press-funktioner någonstans — allt nås med en enda tryckning, i linje med ett litet, lågkromat personligt verktyg.

## Tillgänglighetsgolv

Bara förnuftiga standardvärden — det här är ett enda personligt verktyg, inte ett efterlevnadsmål, och memloggen är tydlig med att inga särskilda syn-/motorikanpassningar ingår i omfattningen. Att hålla det här avsnittet kort är i sig det rätta valet:

- WCAG AA-liknande kontrast för text och badgar — sikta på 4,5:1 för dämpad-på-yta-kombinationer (till exempel `{colors.text-muted}` på `{colors.bg-surface}` / `{colors.nohist-bg}`, används för badge-text i varje rad). Ett löst riktmärke att bedöma på ögonmått, inte en formell granskning.
- Läsbara basstorlekar — inget under `{typography.label}` (9px) används för något handlingsbart, bara för badge-metadata.
- Tydliga, tillräckligt utspridda tryckmål (se Interaktionsprimitiver) så att Watchlist star och radnavigering inte konkurrerar med varandra på en telefon.
- Ingen dedikerad skärmläsargenomgång, inget reducerat rörelseläge, inget krav på tangentbordsnavigering — verkligen utanför scope enligt memloggens egen ramverk, inte ett förbiseende.

## Responsivitet och plattform

Två referensbredder användes direkt vid designen av den valda mockupen ([`mockups/leaderboard-hero.html`](mockups/leaderboard-hero.html)): en 390px telefonram (primär, mobile-first) och en ~900px laptop-breddram som visar omflödet. Beteende:

| Viewport | Beteende |
|---|---|
| Mobil (~390px och uppåt, primärt mål) | Enkolumnig kortlista. Source switcher och Ranking-mode toggle staplas som två separata kontrollrader i full bredd under skärmens header. Sparklines är kompakta (52×22). Streak-/Spike-badgar radbryts under radnamnet. |
| Laptop (~900px och bredare) | Rader flödar om till ett brett kort med explicita rutnätskolumner — star / rank / namn / ägare / trend / delta / flagga får var sitt dedikerat utrymme istället för att staplas under namnet. Sparklines ungefär dubbleras i bredd (130×30) eftersom det finns plats att visa den riktiga formen. Source switcher och Ranking-mode toggle flyttar till en gemensam header-rad bredvid sidtiteln istället för att staplas under den. |

Beteende i surfplatteintervallet (~600–900px): interpolerar mot den mobila enkolumnslayouten tills laptop-omflödets rutnät faktiskt har plats att andas, snarare än att introducera en tredje distinkt layout — standardpraxis, ingen skräddarsydd surfplattedesign (bekräftat; inte en enhet Stefan använder för det här).

## Nyckelflöden

### Flöde 0 — Att logga in (Stefan, första användning eller efter en sessionsutgång)

1. Stefan öppnar appen utan giltig session och landar på **Inloggning** — bara ett användarnamnsfält, ett lösenordsfält och en skicka-åtgärd.
2. Han skriver in sitt användarnamn och lösenord och skickar.
3. Vid lyckad inloggning etablerar appen den långlivade token och landar direkt på **Topplista** — samma landningsvy som Flöde 1 tar vid från.

Felväg: fel inloggningsuppgifter håller kvar honom på Inloggning med "Fel användarnamn eller lösenord." inline under formuläret på den omladdade sidan (se Tillståndsmönster) — sidan laddas om, inget utelåsning; han försöker igen direkt därifrån.

### Flöde 1 — Morgonkaffekollen (Stefan, telefon, 08:14)

1. Stefan öppnar appen. Sessionen består — ingen inloggningsskärm, rakt till **Topplista**.
2. Topplista laddas med Source switcher på **Avanza** och Ranking-mode toggle på **Flest ägare** (standard-landningsläget) — topp 10-aktier efter dagens ägarantal, varje rad visar sitt dag-till-dag-delta och Sparkline.
3. Han lägger märke till Evolution på plats 3 med ett starkt +2,3 %-delta och en **⚡ spike badge** precis bredvid sin Streak badge — ett stort hopp, men badgen säger redan åt honom att inte lita på det rakt av.
4. Skeptisk av vana växlar han Ranking-mode toggle till **Stadig tillväxt** för att sanity-checka vem som faktiskt bygger uthållig momentum istället för att bara spika idag. Evolution faller ur den omordnade listan; Investor B och Atlas Copco A — som redan visade långa streaks och rena uppåtgående sparklines även i vyn Flest ägare — stiger till toppen istället.
5. Han trycker sig in på **Atlas Copco A:s Aktiedetalj**-sida.
6. På Aktiedetalj använder han Range picker för att vidga från standardvyn Dag ut genom 30d, 90d och slutligen År, och ser trendlinjen hålla en stadig uppåtgående form vid varje steg istället för att visa ett enda dagshopp — han kastar också en blick på overlayen för båda källorna för att bekräfta att Nordnet visar samma form oberoende.
7. **Klimax:** diagrammet med det längre intervallet avgör saken — tillväxten är verklig, inte en spik som ekar genom ett kort fönster. Stefan trycker på Watchlist star på Atlas Copco A:s header; den fylls guld. Han backar ut till **Topplista**, kastar en sista blick på Evolutions Spike badge, och går vidare utan att bevaka den — badgen sa redan det han behövde veta, och detaljkollen skulle bara bekräfta det.

Felgren: om Stefan öppnar appen och den 30-dagars sessionen har gått ut, ser han Inloggning med "Sessionen har gått ut. Logga in igen.", autentiserar om (se Flöde 0), och landar tillbaka på Topplista precis där steg 2 börjar.

### Flöde 2 — Att gräva förbi topp 10 (Stefan, laptop, helgresearch)

1. Från Topplista trycker Stefan på "Visa fullständig lista" för att fördjupa sig i **Fullständig lista** — alla ~740 spårade instrument för den aktuella källan (Avanza), sorterade efter fallande ägarantal som standard.
2. Han växlar Source switcher från **Avanza** till **Nordnet**; Fullständig lista hämtar och rankar om för Nordnets siffror — samma instrument, en annan ordning, aldrig sammanslaget med Avanzas antal.
3. Han skriver "volvo" i sökningen för att smalna av de ~740 raderna ner till en handfull Volvo-listningar.
4. Han ändrar sorteringskontrollen till %-förändring, för att se vilken Volvo-listning som rörde sig mest idag snarare än vilken som bara har flest ägare.
5. Han slår på filtret **Stadig tillväxt** för att begränsa den avsmalnade listan till namn med uthållig trendkvalitet, inte bara en dagsrörelse.
6. **Klimax:** en Volvo-listning överlever alla filter — stadig tillväxt, inte bara en dagsrörelse — och han trycker sig in på dess Aktiedetalj för att bekräfta innan han bestämmer om den är värd att bevaka.

### Flöde 3 — Att kolla Bevakningslistan (Stefan, telefon, senare samma kväll)

1. Stefan öppnar fliken **Bevakningslista** direkt från flikfältet — ingen omväg via Topplista.
2. Han ser bara sina bevakade namn: Atlas Copco A (bevakad tidigare i Flöde 1) tillsammans med några andra han har fäst under tidigare dagar, var och en fortfarande visar sin egen Streak badge, delta och Sparkline precis som den skulle på Topplista.
3. **Utdelning:** Atlas Copco A:s Streak badge har tickat upp ytterligare en dag sedan i morse — snabbkoll-vyn gör precis vad den är till för, bekräftar fortsatt momentum utan att behöva vada tillbaka genom Topplistans eller Fullständig listas brus.

## Öppna punkter

Inga kvarstående. Alla fem luckor som flaggades under utkastarbetet bekräftades direkt med Stefan: fast 30-dagars sessions-TTL, segmenterad Range picker, pull-to-refresh + manuell/tidsstämpel på laptop, ~44px tryckmålsgolv, och standard (ej skräddarsydd) surfplatteomflöde.
