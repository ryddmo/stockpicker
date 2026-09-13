---
name: stockpicker
status: final
sources: []
updated: 2026-09-12
description: Personligt enanvändarverktyg för att följa trender i ägarantal för svenska/nordiska aktier, hämtat från Avanza och Nordnet (aldrig sammanslaget). Modern fintech-visuell stil hållen ärlig — spikar uppmärksammas, firas inte.
colors:
  bg-app: '#F5F6FA'
  bg-surface: '#FFFFFF'
  border: '#E4E7EC'
  row-border: '#EEF0F5'
  control-bg: '#ECEEF3'
  text-primary: '#101323'
  text-secondary: '#667085'
  text-muted: '#98A2B3'
  brand: '#5B4FE9'
  brand-tint: '#EEEDFD'
  positive: '#12B76A'
  positive-tint: '#E7F9F0'
  negative: '#F04438'
  negative-tint: '#FEECEB'
  spike-bg: '#FEF0C7'
  spike-text: '#B54708'
  star-filled: '#F5A623'
  star-empty: '#D0D5DD'
  nohist-bg: '#F2F4F7'
typography:
  wordmark:
    fontFamily: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif
    fontSize: 12px
    fontWeight: '800'
    letterSpacing: 0.06em
  h1:
    fontFamily: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif
    fontSize: 22px
    fontWeight: '800'
  subtitle:
    fontFamily: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif
    fontSize: 12.5px
    fontWeight: '400'
  body:
    fontFamily: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif
    fontSize: 13.5px
    fontWeight: '700'
  stat:
    fontFamily: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif
    fontSize: 13.5px
    fontWeight: '800'
  label:
    fontFamily: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif
    fontSize: 9px
    fontWeight: '800'
rounded:
  sm: 6px
  md: 16px
  lg: 20px
  full: 9999px
spacing:
  '1': 4px
  '2': 8px
  '3': 12px
  '4': 16px
  '5': 18px
  '6': 24px
  gutter: 18px
  card-gap: 8px
  card-padding: 12px
  row-internal-gap: 10px
shadow:
  page: '0 12px 40px rgba(16,19,31,0.08)'
  tab-active: '0 1px 3px rgba(16,19,31,0.12)'
components:
  leaderboard-row:
    background: '{colors.bg-surface}'
    border: '1px solid {colors.row-border}'
    radius: '{rounded.md}'
    padding: '10px {spacing.card-padding}'
    gap: '{spacing.row-internal-gap}'
  streak-badge:
    background: '{colors.brand-tint}'
    text: '{colors.brand}'
    radius: '{rounded.full}'
    fontSize: '{typography.label.fontSize}'
    fontWeight: '{typography.label.fontWeight}'
  spike-badge:
    background: '{colors.spike-bg}'
    text: '{colors.spike-text}'
    radius: '{rounded.full}'
    fontSize: '{typography.label.fontSize}'
    fontWeight: '{typography.label.fontWeight}'
  nohist-badge:
    background: '{colors.nohist-bg}'
    text: '{colors.text-muted}'
    radius: '{rounded.full}'
    fontSize: '{typography.label.fontSize}'
    fontWeight: '{typography.label.fontWeight}'
  sparkline:
    stroke-neutral: '{colors.brand}'
    stroke-positive: '{colors.positive}'
    stroke-negative: '{colors.negative}'
    stroke-spike: '{colors.spike-text}'
    stroke-nohistory: '{colors.text-muted}'
    stroke-nohistory-dasharray: '2 3'
    strokeWidth-mobile: 2px
    strokeWidth-wide: 2.5px
    width-mobile: 52px
    width-wide: 130px
    height-mobile: 22px
    height-wide: 30px
  trend-overlay:
    width-mobile: 100%
    width-wide: 100%
    height-mobile: 160px
    height-wide: 220px
    primary-strokeWidth-mobile: '{components.sparkline.strokeWidth-mobile}'
    primary-strokeWidth-wide: '{components.sparkline.strokeWidth-wide}'
    secondary-stroke: '{colors.text-secondary}'
    secondary-strokeWidth-mobile: 2px
    secondary-strokeWidth-wide: 2.5px
    secondary-dasharray: '4 3'
    legend-swatch-size: 8px
    legend-fontSize: '{typography.label.fontSize}'
    legend-fontWeight: '{typography.label.fontWeight}'
    legend-text: '{colors.text-secondary}'
  source-tab:
    container-background: '{colors.control-bg}'
    container-radius: '{rounded.full}'
    tab-inactive-text: '{colors.text-secondary}'
    tab-active-background: '{colors.text-primary}'
    tab-active-text: '{colors.bg-surface}'
    radius: '{rounded.full}'
  ranking-mode-toggle:
    container-background: '{colors.control-bg}'
    container-radius: '{rounded.full}'
    tab-inactive-text: '{colors.text-secondary}'
    tab-active-background: '{colors.bg-surface}'
    tab-active-text: '{colors.brand}'
    tab-active-shadow: '{shadow.tab-active}'
    radius: '{rounded.full}'
  range-picker:
    container-background: '{colors.control-bg}'
    container-radius: '{rounded.full}'
    segment-inactive-text: '{colors.text-secondary}'
    segment-active-background: '{colors.bg-surface}'
    segment-active-text: '{colors.brand}'
    segment-active-shadow: '{shadow.tab-active}'
    radius: '{rounded.full}'
  watchlist-star:
    filled: '{colors.star-filled}'
    empty: '{colors.star-empty}'
    fontSize: 15px
    glyph-filled: '★'
    glyph-empty: '☆'
  delta-chip-positive:
    background: '{colors.positive-tint}'
    text: '{colors.positive}'
    radius: '{rounded.sm}'
    fontSize: 9.5px
    fontWeight: '800'
  delta-chip-negative:
    background: '{colors.negative-tint}'
    text: '{colors.negative}'
    radius: '{rounded.sm}'
    fontSize: 9.5px
    fontWeight: '800'
---

## Varumärke och stil

Stockpicker är ett personligt instrument, inte en produkt. En användare, ett syfte: se genom en dag-till-dag-uppgång och avgöra om den är verklig. Den visuella stilen lånar fintech-världens självklara sätt att hantera siffror och rundade, självsäkra kort, men inget av fintechs övertalningskonst — ingen brådskande copy, ingen gamifierad streak-jakt, ingen "möjlighets"-inramning. UI:ts uppgift är att göra frågan spik-eller-trend besvarbar i en snabb blick, och att säga rakt ut när den ännu inte kan besvaras ("inte tillräckligt med historik än" slår ett falskt diagram varje gång).

Konkret: en accentfärg ({colors.brand}) som bara används på det som faktiskt är poängen — det aktiva rankningsläget, wordmarken, streak-badgen. Allt annat hålls i en sval, tyst gråskala-på-vitt-palett, så att de två färger som *faktiskt* bär mening — {colors.positive} och {colors.negative} — är omisskännliga i samma sekund de dyker upp. Spike-badgar får en egen bärnstensfärg, medvetet varken röd eller grön, eftersom en spik ännu inte är en dom.

## Inspiration och antimönster

Tre riktningar renderades och jämfördes som fullständiga mockuper av Topplistans hero-skärm innan den här valdes:

- **Clean terminal** (förkastad) — för tät och kalkylbladslik för en skärm som ska scannas på några sekunder över morgonkaffet, inte frågas mot som ett datagrid.
- **Calm editorial** (förkastad) — för mjuk och journallik; en personlig-ekonomi-dagboksstil underspelar den "gå och verifiera det här"-skepsis som verktyget ska förmedla.
- **Modern fintech** (vald) — rundade kort, sparklines inline per rad, en djärv accentfärg använd sparsamt för deltan/spikflaggor, samtida konsumentapp-stil, ljust tema, diagramfokuserad. Hållen ärlig genom att fintechs vanliga övertalningslager har skalats bort (se Varumärke och stil ovan).

Den här anti-hype-hållningen är medveten, inte ett förbiseende: varumärket finns för att svara på "är det här verkligt," inte för att få Stefan att känna sig bra över en siffra.

## Färger

- **Appbakgrund (`{colors.bg-app}`)** — sval ljusgrå, aldrig ren vit. Håller kortytor (`{colors.bg-surface}`) visuellt åtskilda utan en kant runt varje sida.
- **Ytvit (`{colors.bg-surface}`)** — Leaderboard row-rader, den breda vyns ram. Aldrig appbakgrunden själv — de två måste förbli urskiljbara.
- **Kant (`{colors.border}`) / Radkant (`{colors.row-border}`)** — bara hårfina avgränsare. `{colors.row-border}` är en aning ljusare, reserverad för sömmen kort-mot-kort där en rad ligger på appbakgrunden; `{colors.border}` är till strukturella avdelare som fotnotslinjen och den breda vyns kolumnrubriklinje. Används aldrig för att ge visuell tyngd.
- **Kontrollbakgrund (`{colors.control-bg}`)** — den pillerformade banan bakom Source switcher och Ranking-mode toggle. Finns bara som en behållare för en växlare; används aldrig som en fristående yta.
- **Text primär (`{colors.text-primary}`)** — nästan svart, inte helt svart. Radnamn, rubriksiffror, den aktiva Source switcherns egen bakgrund (inverterad — se Komponenter). Reserverad för innehåll som svarar på "vad" och "hur många," inte för dekoration.
- **Text sekundär (`{colors.text-secondary}`)** och **text dämpad (`{colors.text-muted}`)** — sekundär är till för underrubriker och inaktiva flikettiketter; dämpad är till för innehåll med platshållartyngd (badge-texten "ingen trend än", fotnotens totalantal). Använd aldrig dämpad för något som användaren behöver agera på.
- **Brand (`{colors.brand}`) / Brand tint (`{colors.brand-tint}`)** — den enda kromatiska accentfärgen som inte är en domfärg. Wordmark, aktiv Ranking-mode toggle-flik, Streak badge. Används aldrig för deltavärden — en streak och ett delta svarar på olika frågor och får aldrig dela färg.
- **Positiv (`{colors.positive}`) / Positiv tint (`{colors.positive-tint}`)** — bara för uppåtgående deltan och uppåttrendande Sparklines. Det här är den enda färg som får antyda "bra," så den får aldrig förekomma dekorativt.
- **Negativ (`{colors.negative}`) / Negativ tint (`{colors.negative-tint}`)** — bara för nedåtgående deltan och nedåttrendande Sparklines. Symmetrisk partner till positiv; samma disciplin.
- **Spike (`{colors.spike-bg}` / `{colors.spike-text}`)** — bärnstensfärgad, inte röd eller grön, och medvetet så: en Spike badge är en flagga att gå och verifiera, inte en dom om huruvida rörelsen är bra eller dålig. En rad som spikar kan fortfarande visa en positiv Delta chip i grönt *och* en Spike badge i bärnsten samtidigt — det är poängen, inte en bugg.
- **Star (`{colors.star-filled}` guld / `{colors.star-empty}` grå)** — bara för Watchlist star-växlaren. Det här är den enda varma, icke-dömande färgen i paletten; återanvänds aldrig någon annanstans.
- **No-history (`{colors.nohist-bg}`)** — ihopparad med `{colors.text-muted}`-text. Används både för fallback-badgen "flat" (ingen pågående streak) och det genuina läget "inte tillräckligt med historik än." Medvetet den minst visuellt intressanta badgen i systemet — otillräcklig data ska aldrig konkurrera om uppmärksamhet med en riktig signal.

Undvik: gradienter, inflygande "success"-toaster, all röd/grön-användning utanför deltan och sparkline-linjer, samt firande färg för streaks — en Streak badge är informativ, inte en belöning.

## Typografi

Systemfont-stack genomgående (`-apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif`) — inget eget webbtypsnitt. Det här är ett personligt verktyg; plattformens egen rendering är rätt val, inte en kompromiss.

- `{typography.wordmark}` — versaler, utspärrad, brand-färgad. Förekommer en gång per skärm, uppe till vänster. Används aldrig till något annat än själva "Stockpicker"-märket.
- `{typography.h1}` — bara skärmtitel ("Topplista," "Bevakningslista," en akties namn på dess detaljsida). En per skärm.
- `{typography.subtitle}` — den enda förklarande raden under en skärmtitel (till exempel, "Ägarantal, rankade. Spikar uppmärksammas, döljs inte."). Sätter den skeptiska tonen i text, inte bara i badge-färg.
- `{typography.body}` — radnamn och allt primärt listinnehåll. Tillräckligt fet för att snabbt scanna en lista på 10–740 rader.
- `{typography.stat}` — själva ägarantalssiffran. Något tyngre vikt än `{typography.body}` i samma storlek — siffran är anledningen till att raden finns.
- `{typography.label}` — varje badge och rankningssiffra. Medvetet liten (9px) och genomgående versaler, fet vikt så att badgar läses som metadata, aldrig som rubriker som konkurrerar med radnamnet.

Ingen display-/hero-typografi någonstans — den här produkten har ingen marknadsföringsyta, bara arbetsskärmar.

## Layout och mellanrum

Basskala: `{spacing.1}`–`{spacing.6}` (4/8/12/16/18/24px), plus namngivna tokens för de former som återkommer konstant: `{spacing.gutter}` (18px sidmarginaler på varje skärm), `{spacing.card-gap}` (8px mellan Leaderboard row-rader), `{spacing.card-padding}` (12px inre radpadding), `{spacing.row-internal-gap}` (10px mellan en rads celler för rank/star/namn/sparkline/stat).

På mobil är layouten enkolumnig: kort i full bredd, kant-till-kant förutom `{spacing.gutter}`. Vid laptop-bredd flödar samma innehåll om till ett explicit kolumnrutnät (rank / star / namn / ägare / trend / delta / flagga) inuti ett brett kort — se Responsivitet och plattform i EXPERIENCE.md för det exakta brytpunktsbeteendet; den här filen specificerar bara de tokens som båda layouterna delar.

## Elevation och djup

Elevation används sparsamt och betyder bara "det här är valt" eller "det här är en självständig yta," aldrig som generisk dekoration:

- Leaderboard row-rader är platta — bara `{colors.row-border}`-hårlinje, ingen skugga. En lista på upp till 740 rader har inte råd med skugg-brus per rad.
- Den aktiva fliken inuti Ranking-mode toggle (och det aktiva segmentet i Range picker, som återanvänder samma aktiva-tillstånd-familj) får en mjuk lyftning (`{shadow.tab-active}`) för att visa valt tillstånd utan att bara förlita sig på färg — Source switcherns aktiva flik inverterar istället till en solid mörk piller, ingen skugga, så att de två växlarna inte konkurrerar visuellt.
- Den breda vy-behållaren (laptop) får en enda ambient skugga (`{shadow.page}`) för att lyfta hela topplistan från den omgivande sidramen — det här är den enda "sidnivå"-skuggan i systemet.

## Former

Hörnradien skalar med hur kontrollartad en form är:

- `{rounded.sm}` (6px) — Delta chip. Liten, rektangulär, informationstät; en helt rundad piller skulle få en tvåordsstatistik att se ut som en knapp.
- `{rounded.md}` (16px) — Leaderboard row-kort. Mjuk nog för att kännas tryckbar, inte så rund att den läses som en knapp.
- `{rounded.lg}` (20px) — den yttre breda vy-ramen vid laptop-bredd. Den enskilt största ytan får den enskilt största radien.
- `{rounded.full}` (9999px) — Source switcher, Ranking-mode toggle, Range picker, samt Streak/Spike/No-history-badgar. Full rundning är reserverad för sådant som verkligen är valbart eller informativa taggar — det är formens sätt att säga "det här är en kontroll eller en etikett, inte innehåll."

## Komponenter

Visuell referens: [`mockups/leaderboard-hero.html`](mockups/leaderboard-hero.html) (Topplista) och [`mockups/stock-detail.html`](mockups/stock-detail.html) (Aktiedetalj, inklusive Trend overlay nedan). Den här filens tokens vinner vid all konflikt med en mockup.

- **Leaderboard row** (`{components.leaderboard-row}`) — rankningssiffra, Watchlist star, namn + badge-rad, Sparkline, ägarantal + Delta chip, vänster till höger. Namnet trunkeras med en ellips innan något annat ger vika.
- **Streak badge** (`{components.streak-badge}`) — "🔥 {n}d" på `{colors.brand-tint}`. Visas bara när den pågående uppgångs-streaken är ≥ 1 dag; ersätts av No-history badgens "flat"-variant när streaken är 0. Se EXPERIENCE.md:s Komponentmönster för det exakta utlösarvillkoret — den här posten är visuell kontext, inte en andra sanningskälla.
- **Spike badge** (`{components.spike-badge}`) — "⚡ spike" på `{colors.spike-bg}`. Kan visas tillsammans med en Streak badge på samma rad (en aktie kan både vara mitt i en streak och spika samtidigt) — de två är oberoende signaler och får aldrig slås ihop till en badge. Se EXPERIENCE.md:s Komponentmönster för det exakta utlösarvillkoret — den här posten är visuell kontext, inte en andra sanningskälla.
- **No-history badge** (`{components.nohist-badge}`) — återanvänds för två olika meddelanden: en kort "flat"-etikett (raden har ingen pågående uppgångs-streak) och den längre etiketten "{n}d spårade · ingen trend än" (för få rader för en trendlinje). Båda delar samma tysta, dämpade visuella behandling. Se EXPERIENCE.md:s Tillståndsmönster för de exakta utlösarvillkoren — den här posten är visuell kontext, inte en andra sanningskälla.
- **Sparkline** (`{components.sparkline}`) — linjefärgen är kontextuell, inte dekorativ: `{colors.positive}` vid uppåttrend, `{colors.negative}` vid nedåttrend, `{colors.spike-text}` när raden är flaggad som spikande, `{colors.text-muted}` med streckad linje när det inte finns tillräckligt med historik för att rita en riktig trend. Ungefär dubbleras i bredd vid laptop-brytpunkten eftersom det då finns plats att visa den faktiska formen.
- **Trend overlay** (`{components.trend-overlay}`, bara Aktiedetalj) — ritar båda källornas trendlinjer i samma diagram utan att låta dem smälta ihop till en signal; till skillnad från Sparkline (en kompakt blicksignal inuti en rad) är det här skärmens primära innehåll. **Dimensioner:** full kortbredd, `{components.trend-overlay.height-mobile}` hög på mobil och `{components.trend-overlay.height-wide}` på laptop — tillräckligt hög för att frågan "är det här en riktig trend" faktiskt ska kunna bedömas med ögat, vilket är hela anledningen till att Aktiedetalj finns. **Primär linje:** den aktuellt aktiva källan i Source switcher, med sparklinens kontextuella linjefärgslogik och linjebredd (`{components.trend-overlay.primary-strokeWidth-mobile}`) oförändrad, bara skalad till den större ytan. **Sekundär linje:** den andra källan, alltid renderad i en fast, icke-kontextuell `{colors.text-secondary}` med streckad linje (`{components.trend-overlay.secondary-dasharray}`) oavsett om den trendar upp eller ner — streckmönstret svarar på "vilken källa," Sparklinens egen färglogik svarar på "är det här bra," och de två frågorna måste förbli visuellt separerbara. **Legend:** en liten rad under diagrammet parar ihop varje linjestil med sitt källnamn ("Avanza" / "Nordnet") med `{typography.label}`-storlek så att det inte finns någon tvetydighet om vilken linje som är vilken.
- **Source switcher** (`{components.source-tab}`) — tvåvägsväxlare, Avanza / Nordnet, som bor i en `{colors.control-bg}`-piller. Aktiv flik inverterar till en solid `{colors.text-primary}`-bakgrund — medvetet den högsta-kontrast-växlaren i UI:t, eftersom att få källan fel (Avanza-data avläst som Nordnet-data) är det enda misstag den här appen inte får göra tyst.
- **Ranking-mode toggle** (`{components.ranking-mode-toggle}`) — "Flest ägare" / "Stadig tillväxt," samma pillerfamilj som Source switcher men ett visuellt distinkt aktivt tillstånd (vit flik + brand-färgad text + mjuk skugga, jämfört med Source switcherns solidmörka inversion) så att de två växlarna aldrig förväxlas trots att de sitter i samma header-rad vid laptop-bredd.
- **Range picker** (`{components.range-picker}`, bara Aktiedetalj) — Dag / Vecka / 30d / 90d / År, en femsegmentskontroll. Samma pillerfamilj och aktiva-tillstånd-behandling som Ranking-mode toggle (vitt aktivt segment + brand-färgad text + `{shadow.tab-active}`), inte Source switcherns högkontrast-inversion — att välja ett intervall är en lågriskändring av vyn, inte ett beslut om dataidentitet, så det konkurrerar inte om UI:ts högsta kontrastbehandling. Fem segment (inte fyra) eftersom 30d/90d mappar direkt mot backendens egna förberäknade `sma_30`/`sma_90`-fönster istället för en godtycklig "månads"-hink — Range pickerns gränser matchar exakt de fönster som trendlinjen faktiskt är utjämnad över.
- **Watchlist star** (`{components.watchlist-star}`) — fylld guldglyf eller tom konturglyf, ett tryckmål oberoende av resten av raden.
- **Delta chip** (`{components.delta-chip-positive}` / `{components.delta-chip-negative}`) — antal och procent tillsammans ("+412 · 0,9 %"), alltid båda, aldrig bara en — ett rått antal utan procent (eller tvärtom) svarar inte på "är det här en stor grej för just den här aktien."

## Gör och gör inte

| Gör | Gör inte |
|---|---|
| Spendera `{colors.brand}` bara på wordmark, aktiv Ranking-mode toggle-flik och Streak badge | Använd brand-lila till deltan, spikar eller stjärnan — varje signal behåller sin egen färg |
| Låt en Spike badge och en Streak badge samexistera på samma rad | Slå ihop spike + streak till en enda kombinerad badge — de svarar på olika frågor |
| Visa antal och procent tillsammans i varje Delta chip | Visa en ensam procent eller ett ensamt antal |
| Använd den dämpade No-history badge-behandlingen för både "flat" och "otillräcklig data" | Hitta på en separat larmande färg för "otillräcklig data" — det är ett faktum om datamognad, inte ett problem |
| Håll rader platta, bara hårlinje | Lägg till skuggor per rad — listan kan vara 740 rader djup |
| Reservera den ambienta sidnivå-skuggan för den enda breda vy-behållaren | Lägg till skuggor på enskilda kontroller eller badgar |
