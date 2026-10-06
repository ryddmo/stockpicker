---
name: stockpicker
status: final
sources: []
updated: 2026-10-06
description: Personligt enanvändarverktyg för att följa trender i ägarantal för svenska/nordiska aktier, hämtat från Avanza och Nordnet (aldrig sammanslaget). "Fintech Precision" — en återhållsam, hög-konsekvens fintech-stil; inga pillerformer, inga lekfulla accenter.
colors:
  bg-app: '#F2F4F5'
  bg-surface: '#FFFFFF'
  text-primary: '#152126'
  text-secondary: '#526168'
  text-muted: '#68777E'
  border: '#D8DEE1'
  accent: '#146C94'
  accent-tint: '#E7F2F7'
  positive: '#0B7F52'
  positive-tint: '#E7F6F0'
  negative: '#C93832'
  negative-tint: '#FBEDEC'
  spike: '#8C5100'
  spike-tint: '#FFF3D8'
  neutral-tint: '#E8ECEE'
  star-filled: '#A86100'
  star-empty: '#B7C0C4'
typography:
  heading:
    fontFamily: "'Sora', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif"
    fontWeight: '600'
  body:
    fontFamily: "'Manrope', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif"
    weights: [400, 500, 600]
  mono:
    fontFamily: "'JetBrains Mono', ui-monospace, 'SFMono-Regular', Consolas, monospace"
    fontWeight: '500'
    usage: 'Siffror med mening: ägarantal, delta, periodprocent, y-axelns tick-etiketter — font-variant-numeric: tabular-nums.'
  h1:
    font: '{typography.heading}'
    fontSize: 24px
    lineHeight: 30px
  h2:
    font: '{typography.heading}'
    fontSize: 20px
    lineHeight: 28px
  subtitle:
    font: '{typography.body}'
    fontSize: 13px
    fontWeight: '400'
    color: '{colors.text-secondary}'
  tabLabel:
    font: '{typography.body}'
    fontSize: 13px
    fontWeight: '600'
  name:
    font: '{typography.body}'
    fontSize: 14px
    fontWeight: '600'
  stat:
    font: '{typography.mono}'
    fontSize: 14px
  badge:
    font: '{typography.body}'
    fontSize: 11px
    fontWeight: '600'
  periodPctLabel:
    font: '{typography.body}'
    fontSize: 10px
    fontWeight: '600'
    color: '{colors.text-muted}'
rounded:
  sm: 4px
  md: 6px
  lg: 8px
spacing:
  '1': 4px
  '2': 8px
  '3': 12px
  '4': 16px
  '5': 24px
  '6': 32px
  '7': 48px
  '8': 64px
shadow:
  page: '0 12px 32px rgba(21,33,38,0.08)'
  range-active: '0 1px 2px rgba(21,33,38,0.12)'
  toast: '0 4px 16px rgba(21,33,38,0.24)'
components:
  row:
    background: '{colors.bg-surface}'
    border: '1px solid {colors.border}'
    radius: '{rounded.lg}'
    padding: '{spacing.2} {spacing.3}'
    gap: '{spacing.3}'
  pill-track:
    background: '{colors.neutral-tint}'
    radius: '{rounded.md}'
    padding: 3px
    gap: 2px
    tab-minHeight: 32px
    tab-padding: '{spacing.1} {spacing.3}'
    tab-radius: '{rounded.sm}'
    tab-font: '{typography.tabLabel}'
    tab-inactive-text: '{colors.text-secondary}'
  tab-bar:
    extends: '{components.pill-track}'
    active-background: '{colors.text-primary}'
    active-text: '{colors.bg-surface}'
  source-tab:
    extends: '{components.pill-track}'
    active-background: '{colors.text-primary}'
    active-text: '{colors.bg-surface}'
  range-picker:
    extends: '{components.pill-track}'
    active-background: '{colors.bg-surface}'
    active-text: '{colors.accent}'
    active-shadow: '{shadow.range-active}'
  ranking-toggle:
    border: '1px solid {colors.border}'
    radius: '{rounded.md}'
    tab-minHeight: 32px
    tab-radius: 0
    active-background: '{colors.accent-tint}'
    active-text: '{colors.accent}'
  filter-toggle:
    border: '1px solid {colors.border}'
    radius: '{rounded.md}'
    minHeight: 32px
    padding: '6px {spacing.3}'
    background: '{colors.bg-surface}'
    text: '{colors.text-secondary}'
    box-size: 12px
    box-radius: 3px
    box-border-unchecked: '{colors.text-muted}'
    active-border: '{colors.accent}'
    active-text: '{colors.accent}'
    active-background: '{colors.accent-tint}'
    active-box-background: '{colors.accent}'
    active-box-glyph: '✓'
  spike-toggle:
    minHeight: 38px
    padding: '0 {spacing.2}'
    font: '{typography.body}'
    fontSize: 13px
    fontWeight: '500'
    text: '{colors.text-secondary}'
    active-text: '{colors.text-primary}'
    glyph-unchecked: '☐'
    glyph-checked: '☑'
  badge:
    radius: '{rounded.sm}'
    padding: '2px 6px'
    font: '{typography.badge}'
  badge-streak:
    extends: '{components.badge}'
    background: '{colors.accent-tint}'
    text: '{colors.accent}'
  badge-spike:
    extends: '{components.badge}'
    background: '{colors.spike-tint}'
    text: '{colors.spike}'
  badge-nohist:
    extends: '{components.badge}'
    background: '{colors.neutral-tint}'
    text: '{colors.text-secondary}'
  badge-short:
    extends: '{components.badge}'
    background: '{colors.negative-tint}'
    text: '{colors.negative}'
    status: 'Implementerad 2026-10-06. Egen CSS-regel, inte längre delad med badge-nohist — se Komponenter → Blankningsbadge.'
  delta-chip:
    radius: '{rounded.sm}'
    padding: '2px 6px'
    fontSize: 11px
  sparkline:
    stroke-positive: '{colors.positive}'
    stroke-negative: '{colors.negative}'
    stroke-neutral: '{colors.accent}'
    stroke-spike: '{colors.spike}'
    stroke-nohistory: '{colors.text-muted}'
    stroke-nohistory-dasharray: '2 3'
    strokeWidth: 2px
    width-mobile: 52px
    height-mobile: 22px
    width-wide: 130px
    height-wide: 30px
  trend-overlay:
    width: 100%
    height: 220px
    primary-strokeWidth: 2.5px
    secondary-stroke: '{colors.text-secondary}'
    secondary-strokeWidth: 2px
    secondary-dasharray: '4 4'
    y-axis-width: 34px
    legend-swatch: '14px x 3px, {rounded.sm}-ish 2px'
  watchlist-star:
    filled: '{colors.star-filled}'
    empty: '{colors.star-empty}'
    fontSize: 18px
    tapTarget: '44px x 44px'
    glyph-filled: '★'
    glyph-empty: '☆'
  focus-ring:
    width: 2px
    color: '{colors.accent}'
    offset: 2px
---

## Varumärke och stil

Stockpicker är ett personligt instrument, inte en produkt. En användare, ett syfte: se genom en dag-till-dag-uppgång och avgöra om den är verklig. UI:ts uppgift är att göra frågan spik-eller-trend besvarbar i en snabb blick, och att säga rakt ut när den ännu inte kan besvaras ("inte tillräckligt med historik än" slår ett falskt diagram varje gång). Ingen brådskande copy, ingen gamifierad streak-jakt, ingen "möjlighets"-inramning.

**Riktningsbyte 2026-09-27 (viktigt att känna till):** den ursprungliga riktningen här var "Modern fintech" — rundade kort, helt pillerformade kontroller (`border-radius: 9999px`), en lila-blå accent (`#5B4FE9`). Den 27 september ersattes den, i den riktiga koden, av Lovables handbok **"Fintech Precision"**: samma anti-hype-hållning och samma semantiska färglogik, men en strammare visuell exekvering — återhållsamma hörnradier (4/6/8px, aldrig 9999px), en tealfärgad accent (`#146C94`), och två egna typsnitt (Sora/Manrope) istället för systemfontstacken. Den här filen beskrev fram till idag (2026-10-06) bara den gamla riktningen och hade tappat kontakt med koden i nästan två veckor — se `docs/lovable-ux-review-brief.md` för det granskningsunderlag som drev omarbetningen, och minnesanteckningen `lovable-design-handbook-applied.md` för vad som faktiskt kördes (P0 "konsekvens och åtkomst" + P1 "skannbarhet"; P2 "förklaring och förfining" är fortfarande öppet). Den bakomliggande varumärkeskänslan — ett ärligt, oglamoröst verktyg — är densamma i båda riktningarna; det som bytte var exekveringen, inte avsikten.

Accentfärgen (`{colors.accent}`) används bara på det som faktiskt är poängen — aktiv Range picker/Periodväljare, Ranking-/sort-toggle, Streak badge, fokusringen. Allt annat hålls i en sval, tyst gråskala-på-vitt-palett, så att de två färger som *faktiskt* bär mening — `{colors.positive}` och `{colors.negative}` — är omisskännliga i samma sekund de dyker upp. Spike-badgar får en egen bärnstensfärg, medvetet varken röd eller grön, eftersom en spik ännu inte är en dom.

**Öppen spänning, inte dold:** Lovable-paletten gav Star (`{colors.star-filled}`, `#A86100`) och Spike (`{colors.spike}`, `#8C5100`) nästan samma bärnstensbruna ton — i den gamla paletten var stjärnan medvetet den enda varma färgen och delade den aldrig med något annat. Det är inte rättat här eftersom det är ett genuint kvarvarande spänningsmoment i den riktiga appen, inte ett dokumentationsfel; flagga det om du gör vidare visuellt arbete i närheten av badges eller stjärnan.

**Medvetet undantag, beslutat och implementerat 2026-10-06:** `{colors.negative}` ska utökas från två till tre utlösare — nedåtgående delta, nedåttrendande Sparkline, och Blankningsbadgen (`{components.badge-short}`) — eftersom den delade tysta grå badgen drunknade bland de andra gråa kontrollerna och chipsen i Topplista-headern. Se Komponenter → Blankningsbadge för statusen.

## Färger

- **Appbakgrund (`{colors.bg-app}`)** — sval ljusgrå, aldrig ren vit. Håller kortytor (`{colors.bg-surface}`) visuellt åtskilda utan en kant runt varje sida. Vid laptop-bredd (≥900px) får själva sidramen samma `{colors.bg-app}`-ton plus `{shadow.page}` — ett kort som "flyter" på en likfärgad omgivning, bara skuggan skiljer dem åt.
- **Ytvit (`{colors.bg-surface}`)** — rader, kort, Info-sektioner, inloggningskortet. Aldrig appbakgrunden själv.
- **Kant (`{colors.border}`)** — en enda kanttoken numera (den gamla uppdelningen i kant/radkant är borta). Används till radramar, kort, inmatningsfält och Ranking-/sort-togglens ytterram. Används aldrig för att ge visuell tyngd.
- **Neutral tint (`{colors.neutral-tint}`)** — den ljusgrå banan bakom Tab-bar/Source switcher/Range picker, bakgrunden för neutrala badges (flat, Plusdagar-chip, "Blankad" idag) och för neutrala Delta chips. Finns bara som en tyst behållarfärg; används aldrig som en framträdande yta.
- **Text primär (`{colors.text-primary}`)** — nästan svart, inte helt svart. Radnamn, rubriker, den aktiva Tab-bar-/Source switcher-flikens egen bakgrund (inverterad). Reserverad för innehåll som svarar på "vad" och "hur många."
- **Text sekundär (`{colors.text-secondary}`)** och **text dämpad (`{colors.text-muted}`)** — sekundär är till för underrubriker, inaktiva flikettiketter och radhuvuden (desktop); dämpad är till för genuint perifert innehåll (otillräcklig-historik-text, period-pct-etiketter). Mätt och medvetet val under P2-tillgänglighetsgranskningen (se `app.css`): radhuvudet ligger direkt på `{colors.bg-app}` utan ett kort bakom sig, där `{colors.text-muted}` föll under WCAG AA:s 4,5:1 för den textstorleken — `{colors.text-secondary}` används där istället.
- **Accent (`{colors.accent}`) / Accent tint (`{colors.accent-tint}`)** — den enda kromatiska accentfärgen som inte är en domfärg. Aktivt segment i Range picker/Periodväljare, aktiv Ranking-/sort-toggle, Streak badge, fokusringen överallt. Används aldrig för deltavärden — en streak och ett delta svarar på olika frågor och får aldrig dela färg.
- **Positiv (`{colors.positive}`) / Positiv tint (`{colors.positive-tint}`)** — bara för uppåtgående deltan, uppåttrendande Sparklines/Trend overlay-linjer och positiva periodprocent. Den enda färg som får antyda "bra."
- **Negativ (`{colors.negative}`) / Negativ tint (`{colors.negative-tint}`)** — nedåtgående deltan, nedåttrendande Sparklines/Trend overlay-linjer, negativa periodprocent, och (beslutat och implementerat 2026-10-06, se Varumärke och stil) Blankningsbadge.
- **Spike (`{colors.spike}` / `{colors.spike-tint}`)** — bärnstensfärgad, inte röd eller grön, medvetet så: en Spike badge är en flagga att gå och verifiera, inte en dom. En rad som spikar kan fortfarande visa en positiv Delta chip i grönt *och* en Spike badge i bärnsten samtidigt.
- **Star (`{colors.star-filled}` / `{colors.star-empty}`)** — bara för Watchlist star. Se "Öppen spänning" ovan — ligger numera nära Spike-tonen, vilket den gamla paletten explicit undvek.
- **Neutral/no-history-badges** — "flat" (ingen pågående streak), Plusdagar-chip och (idag, se ovan) Blankningsbadgen delar `{colors.neutral-tint}` + `{colors.text-secondary}`. Medvetet den minst visuellt intressanta badge-ytan i systemet.

Undvik: gradienter, pillerformad (9999px) rundning var som helst, all röd/grön-användning utanför delta/sparkline/trendlinje, samt firande färg för streaks.

## Typografi

Två självhostade webbtypsnitt plus ett monospace, inget längre på systemfontstacken:

- `{typography.heading}` (**Sora**, 600) — `{typography.h1}` (skärmtitel, 24/30px) och `{typography.h2}` (Info-sidans sektionsrubriker, 20/28px). Bara en Sora-vikt är självhostad (600); det finns ingen lättare headingvikt att falla tillbaka på.
- `{typography.body}` (**Manrope**, 400/500/600) — brödtext, radnamn (`{typography.name}`, 14px/600), flik-/kontrolletiketter (`{typography.tabLabel}`, 13px/600), underrubrik (`{typography.subtitle}`, 13px/400), badges (`{typography.badge}`, 11px/600), period-pct-etiketter (`{typography.periodPctLabel}`, 10px/600).
- `{typography.mono}` (**JetBrains Mono**, 500) — siffror med mening, inte bara text som råkar vara siffror: ägarantal (`{typography.stat}`, 14px), delta-chip, periodprocent-värden, Aktiedetaljens y-axel-etiketter. `font-variant-numeric: tabular-nums` så kolumner av tal linjerar. Det här är nytt sedan 27 september — den gamla riktningen hade inget separat monospace-register för tal.

Appen har i praktiken ingen lätt (400-vikt) rubriktext och inget display-/hero-typsnitt — bara arbetsvikter för en arbetsskärm.

## Layout och mellanrum

4pt-skala: `{spacing.1}`–`{spacing.8}` (4/8/12/16/24/32/48/64px) — en jämnare, renare progression än den gamla skalan (som hade udda värden som 18px). Sidpadding är `{spacing.4}` (16px) rakt av; det finns ingen separat namngiven "gutter"-token längre.

Tre brytpunkter (tidigare bara en):

- **<700px** — enkolumn, kontrollerna staplas. Ranking-togglens flikar får tightare padding så tre lägen ryms på en 390px-bredd utan att radbryta.
- **700–899px** — kontrollerna flödar i rad (tvåkolumns-ish), men radlistan är fortfarande staplade kort — den riktiga kolumntabellen kräver 900px:s rutnät.
- **≥900px** — sidan blir ett `{spacing.4}`-paddat kort (`{shadow.page}`, `{rounded.lg}`) på samma `{colors.bg-app}`-bakgrund; radlistan blir ett riktigt kolumnrutnät (rank/namn/trend/ägare/delta, plus periodprocent-kolumnen bara på Topplista) via CSS Grid, med ett eget radhuvud.
- **≥1200px** — sidans maxbredd växer ytterligare (1152px).

## Elevation och djup

- Rader är platta — bara `{colors.border}`-hårlinje, ingen skugga. En lista på upp till ~740 rader har inte råd med skugg-brus per rad.
- Aktivt segment i Range picker/Periodväljare får en mjuk lyftning (`{shadow.range-active}`) — Tab-bar/Source switcherns aktiva flik inverterar istället till en solid mörk bakgrund, ingen skugga, så de två växlarfamiljerna aldrig konkurrerar visuellt.
- Den breda sidramen (≥900px) får `{shadow.page}` — den enda "sidnivå"-skuggan i systemet.
- En tillfällig toast (bevakningsstjärnans felmeddelande) får `{shadow.toast}` — den enda flytande, tillfälliga ytan i appen.
- Fokusringen (`{components.focus-ring}`) är ett outline, inte en skugga, synlig på alla interaktiva element vid tangentbordsnavigering (P0-tillgänglighetskrav från Lovable-handboken; fanns inte alls i den gamla riktningen).

## Former

Hörnradien är medvetet återhållsam sedan 27 september — **ingen pillerformad (9999px) rundning finns längre någonstans i appen.** Det var ett uttryckligt brott med den gamla riktningen, inte en glidning:

- `{rounded.sm}` (4px) — flikar inuti en pill-track, badges, Delta chip, filter-toggle-kryssrutans egen ruta (avrundad ytterligare till ~3px).
- `{rounded.md}` (6px) — pill-track-behållare (Tab-bar/Source switcher/Range picker/Marknadsfilter), Ranking-/sort-toggle, filter-toggle, inmatningsfält, knappar.
- `{rounded.lg}` (8px) — rader/kort, Aktiedetaljens diagramkort, Info-sektioner, inloggningskortet, den breda sidramen.

## Komponenter

**Status:** den här sektionen beskriver den riktiga, körande appen (`public_html/assets/app.css`), rekonstruerad 2026-10-06 efter att ha legat efter koden i nästan två veckor (se Varumärke och stil). Där ett beslut är fattat men inte kodat står det uttryckligen.

- **Rad** (`{components.row}`) — rankningssiffra, Watchlist star, namn + badge-rad, Sparkline, ägarantal + Delta chip, vänster till höger (periodprocent-kolumn också på Topplista). Namnet trunkeras med ellips innan något annat ger vika.
- **Tab-bar / Source switcher** (`{components.tab-bar}` / `{components.source-tab}`) — delar en gemensam "pill-track"-grund (`{components.pill-track}`) med Range picker, men med det högst-kontrast aktiva tillståndet (solid `{colors.text-primary}`-bakgrund, inverterad text) — medvetet, eftersom att få källan fel är det enda misstag appen inte får göra tyst. Topplista: trevägs "Alla / Avanza / Nordnet". Fullständig lista: egen, enklare tvåvägs-instans (Avanza / Nordnet, inget Alla).
- **Range picker / Periodväljare** (`{components.range-picker}`) — samma pill-track-grund, men ett mjukare aktivt tillstånd (vit flik + accentfärgad text + `{shadow.range-active}`) eftersom ett intervallval är en lågriskändring, inte ett beslut om dataidentitet. Aktiedetalj: Dag/Vecka/30d/90d/År. Topplista (Periodväljaren): Vecka/Månad/3 mån/År, nedtonad och overksam i Flest ägare och, sedan 2026-10-06, Stadig tillväxt (segmenten `{colors.text-muted}`, aktivt segment utan fyllning, bara en `{colors.border}`-kontur) — Stadig tillväxt sorterar inte längre på period alls.
- **Ranking-/sort-toggle** (`{components.ranking-toggle}`) — *inte* en pill-track. En egen, visuellt lättare familj: enkel ytterram (`{colors.border}`), fyrkantiga inre flikar (ingen radie), aktivt tillstånd `{colors.accent-tint}`-fyllning + accenttext, ingen skugga. Avsiktligt nedtonad relativt Källa/Period så den läses som ett lättare val, inte ännu en identisk segmentkontroll (se `docs/lovable-ux-review-brief.md`s "avsiktliga avvikelser": anchor-länk-toggles behölls medvetet istället för en `<select>`, för att inte bryta appens stateless-URL-kontrakt).
- **Filter-toggle** (`{components.filter-toggle}`) — en riktig kryssruteformad chip: ytterram, 12px inre ruta (3px radie) med `{colors.text-muted}`-kontur obockad; ikryssad fylls rutan med `{colors.accent}` + vit bock, och hela chipen får `{colors.accent}`-ram och `{colors.accent-tint}`-bakgrund. Används idag av Fullständig listas tre filter (Stadig tillväxt/Spik/Bevakade). **Implementerat (2026-10-06):** Topplistans Spikväxlare och Blankningsväxlare migrerades hit — de använde tidigare en egen, enklare `{components.spike-toggle}` med bokstavliga "☐"/"☑"-glyfer istället för en riktig CSS-ritad ruta, vilket var Stefans ursprungliga klagomål ("checkboxen känns off"). Att återanvända `.filter-toggle` istället för att uppfinna en ny komponent är rätt väg — den finns redan, en rad bort i Fullständig lista.
- **Spike-toggle** (`{components.spike-toggle}`, tidigare implementation, ersatt 2026-10-06 av Filter-toggle) — "☐ Dölj spikar" / "☑ Dölj blankade" som en enkel textlänk med ett `aria-hidden`-glyf-tecken, ingen CSS-ritad ruta. Sitter i Topplistans periodrad: Spikväxlaren bara i Plusdagar, Blankningsväxlaren i alla tre lägen. Se `EXPERIENCE.md` för det fasta tvåradsfacket som löser positionsflytten.
- **Badge-familj** (`{components.badge}`) — `{rounded.sm}` (inte pillerformad längre), 11px/600, padding 2px 6px.
  - **Streak badge** (`{components.badge-streak}`) — "🔥 {n}d" på `{colors.accent-tint}`/`{colors.accent}`. Visas bara när streaken är ≥ 1 dag; ersätts av "flat" (No-history-familjen) när den är 0.
  - **Spike badge** (`{components.badge-spike}`) — "⚡ spike" på `{colors.spike-tint}`/`{colors.spike}`. Kan samexistera med en Streak badge på samma rad — oberoende signaler, slås aldrig ihop.
  - **No-history / flat / Plusdagar-chip** (`{components.badge-nohist}`) — delad tyst grå behandling (`{colors.neutral-tint}`/`{colors.text-secondary}`) för tre olika meddelanden: "flat" (ingen streak), "{n}d spårade · ingen trend än" (för lite historik), och Plusdagar-chipen "15/16 · +1 035" (mono-siffror, annars samma grå).
  - **Blankningsbadge** (`{components.badge-short}`) — "Blankad 15,8 %" sist i badge-raden. **Tidigare:** delade `badge-nohist`s grå rakt av (ingen egen regel). **Implementerat (2026-10-06):** egen röd behandling (`{colors.negative-tint}`/`{colors.negative}`) — se Varumärke och stil för avvägningen mot "rött = bara dagens nedgång".
- **Delta chip** (`{components.delta-chip}`) — antal och procent tillsammans ("+412 · 0,9 %"), alltid båda. Positiv/negativ/neutral variant, samma tint+text-par som badge-familjen.
- **Sparkline** (`{components.sparkline}`) — kontextuell linjefärg: `{colors.positive}` uppåt, `{colors.negative}` nedåt, `{colors.spike}` vid spik, `{colors.text-muted}` streckad vid för lite historik. 52×22px mobil, 130×30px vid ≥900px (strokeWidth konstant 2px — ingen separat bred vikt längre).
- **Trend overlay** (`{components.trend-overlay}`, bara Aktiedetalj) — ritar båda källornas linjer i samma diagram. **Nytt sedan 27 september:** en vänsterställd y-axel med tick-etiketter (`{typography.mono}`, `{components.trend-overlay.y-axis-width}` bred) — fanns inte i den gamla riktningen. Fast höjd 220px (ingen mobil/bred-delning längre), full bredd. Primärlinje: aktiv källa, 2,5px, samma kontextuella färglogik som Sparkline. Sekundärlinje: andra källan, alltid `{colors.text-secondary}`, streckad ("4 4"), 2px. Legend under diagrammet parar linjestil med källnamn.
- **Watchlist star** (`{components.watchlist-star}`) — fylld/tom glyf, 44×44px tryckmål (oförändrat), oberoende tryckmål från resten av raden.
- **Fokusring** (`{components.focus-ring}`) — `{colors.accent}`, 2px, 2px offset, på alla interaktiva element vid tangentbordsnavigering. Nytt tillskott från Lovable-handbokens P0 ("konsekvens och åtkomst").

## Gör och gör inte

| Gör | Gör inte |
|---|---|
| Spendera `{colors.accent}` bara på aktivt Range picker/Periodväljare-segment, aktiv Ranking-/sort-toggle, Streak badge och fokusringen | Använd accentfärgen till deltavärden, spikar eller stjärnan — varje signal behåller sin egen färg |
| Låt en Spike badge och en Streak badge samexistera på samma rad | Slå ihop spike + streak till en enda kombinerad badge |
| Visa antal och procent tillsammans i varje Delta chip | Visa en ensam procent eller ett ensamt antal |
| Håll `{rounded.sm}`/`{rounded.md}`/`{rounded.lg}` — aldrig pillerformad 9999px-rundning | Återinför full pillerundning "för att det såg snyggare ut förr" — det är ett medvetet, dokumenterat riktningsbyte |
| Håll rader platta, bara hårlinje | Lägg till skuggor per rad — listan kan vara ~740 rader djup |
| Reservera `{shadow.page}` för den enda breda sidramen och `{shadow.toast}` för toasten | Lägg till skuggor på enskilda kontroller eller badges |
| Återanvänd `{components.filter-toggle}` för nya kryssruteformade kontroller | Uppfinn en ny checkbox-stil (som gårdagens nu ersatta `filter-checkbox`-förslag) när en redan finns i koden |
