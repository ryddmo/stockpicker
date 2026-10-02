<?php

declare(strict_types=1);

namespace Stockpicker\Web;

use Stockpicker\Adapter\NormalizedRow;

/**
 * spec-5-3 — renders the authenticated `/info` page: a static,
 * non-technical Swedish explanation of what each page shows (Topplista/
 * Fullständig lista/Bevakningslista/Aktiedetalj), what each symbol means
 * (🔥 Streak, ⚡ Spike, ☆/★ Watchlist star, Delta chip), and exactly what
 * qualifies an instrument for "Stadig tillväxt" and how it is ordered —
 * copied faithfully from DerivedMetricsRepository::
 * topByTrendQualityForPeriod()'s real rule (up_streak >= 1 AND not spiking,
 * ordered by the chosen period's % growth, default Månad; spec-5-3,
 * spec-stadig-tillvaxt-period), not restated from memory or the epic's prose.
 *
 * Pure content: no constructor, no repository, no PDO — render() is a
 * plain static function so the route can call it with zero setup (Code
 * Map). The persistent tab bar is reused verbatim from
 * WatchlistController::tabBarHtml()/StockDetailController::tabBarHtml()
 * (Stories 4.3-4.5's already-accepted duplication convention); `$source`
 * is threaded through from the route's `?source=` query param (review
 * round, iteration 1) so a Nordnet-source user's selection survives a
 * round trip through this page, the same as every other tab bar copy.
 * 'topplista' is always the active tab, same precedent as Aktiedetalj
 * (reachable only via another page's action, never a tab of its own).
 */
final class InfoController
{
    public static function render(string $source = ''): string
    {
        $source = $source === NormalizedRow::SOURCE_NORDNET
            ? NormalizedRow::SOURCE_NORDNET
            : NormalizedRow::SOURCE_AVANZA;

        $tabBar = self::tabBarHtml($source);
        $plusDaysChip = LeaderboardController::plusDaysChipHtml(16, 18, 1035);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Information — stockpicker</title>
        <link rel="stylesheet" href="/assets/app.css">
        </head>
        <body>
        <div class="page">
          <div class="nav-strip">
            <div class="wordmark">Stockpicker</div>
            {$tabBar}
          </div>
          <header class="page-header">
            <h1>Information</h1>
            <p class="subtitle">Vad sidorna visar, vad symbolerna betyder och exakt vad som räknas som stadig tillväxt och plusdagar.</p>
          </header>
          <main class="content">
            <section>
              <h2>Topplista</h2>
              <p>Startsidan. Visar en topplista med instrument rankade efter antal ägare ("Flest ägare"), efter hur mycket ägarantalet har ökat under en period bland aktier som just nu ökar i följd utan att spika ("Stadig tillväxt") eller efter hur många dagar under en period ägarantalet har stått still eller ökat ("Plusdagar"). Överst finns tre rader, likadana i alla tre lägen. På första raden väljer du källa (Alla, Avanza eller Nordnet) och rankningsläge. På andra raden väljer du period (Vecka, Månad, 3 mån eller År); perioden delas mellan alla lägen. Flest ägare rankar alltid efter dagens ägarantal, så där är periodraden nedtonad och går inte att klicka på, men vald period står kvar markerad till nästa läge. På tredje raden väljer du marknad: Alla, LC, MC, SC eller First North. Topp tio räknas inom vald marknad, inte som ett filter på den vanliga topp tio. Period och marknad följer med när du byter läge eller källa, och "Visa fullständig lista" öppnar med samma marknad. Valen sparas inte: ett nytt besök börjar på Månad och Alla.</p>
              <p>Under varje rad visas ägarantalets procentuella förändring över fyra perioder: <strong>Vecka</strong>, <strong>Månad</strong>, <strong>3 mån</strong> och <strong>År</strong> (7, 30, 90 respektive 365 kalenderdagar). Varje period jämförs mot den senast lagrade dagen på eller före startdagen, så helger och helgdagar hanteras automatiskt. Ligger den dagen mer än fem dagar före startdagen, eller finns ingen så gammal data ännu, visas "–" i stället för en missvisande siffra.</p>
            </section>
            <section>
              <h2>Fullständig lista</h2>
              <p>Nås via länken "Visa fullständig lista" längst ned på Topplista. Visar alla aktiva instrument för vald källa, med sökfält, sortering och filter (stadig tillväxt, spik, bevakade, marknad) — till skillnad från Topplista, som bara visar de tio översta i vald rankning och marknad.</p>
            </section>
            <section>
              <h2>Bevakningslista</h2>
              <p>Din personliga lista över instrument du har stjärnmärkt. Samma radutseende som Topplista, men utan sökfält, sortering och filter — en bevakningslista är liten till sin natur. Nås via fliken "Bevakningslista" högst upp på varje sida.</p>
            </section>
            <section>
              <h2>Aktiedetalj</h2>
              <p>Nås genom att klicka på ett instrument i någon av listorna. Visar instrumentets namn, en graf över ägarantalet över tid för både Avanza och Nordnet (vald källa som heldragen linje, den andra källan alltid streckad), ett intervallval (Dag/Vecka/Månad/3 mån/År — Dag visar de två senaste handelsdagarna, övriga intervall de senaste 7, 30, 90 respektive 365 kalenderdagarna), samt en länk till aktiens egen sida på Avanza (öppnas i en ny flik).</p>
            </section>
            <section>
              <h2>Symboler</h2>
              <p>Varje symbol nedan visas precis som den faktiskt ser ut i listorna.</p>
              <ul class="symbol-list">
                <li>
                  <span class="symbol-example" aria-hidden="true"><span class="badge badge--streak">🔥 8d</span></span>
                  <span class="symbol-text"><strong>Streak</strong> — antal dagar i rad som ägarantalet har ökat, utan avbrott. Visas som "🔥 Xd". Finns ingen pågående uppgångssvit visas <span class="badge badge--nohist" aria-hidden="true">flat</span> istället.</span>
                </li>
                <li>
                  <span class="symbol-example" aria-hidden="true"><span class="badge badge--spike">⚡ spike</span></span>
                  <span class="symbol-text"><strong>Spike</strong> — ägarantalet har ökat ovanligt mycket på en enda dag. Flaggas alltid, döljs aldrig, så att en kraftig engångsökning inte förväxlas med en äkta trend.</span>
                </li>
                <li>
                  <span class="symbol-example" aria-hidden="true"><span class="star star--empty">☆</span><span class="star star--filled">★</span></span>
                  <span class="symbol-text"><strong>Bevakningsstjärna</strong> — ☆ betyder att instrumentet inte är bevakat, ★ att det är stjärnmärkt. Klicka på stjärnan för att lägga till eller ta bort instrumentet från Bevakningslistan.</span>
                </li>
                <li>
                  <span class="symbol-example" aria-hidden="true"><span class="delta-chip delta-chip--positive">+412 · 0,9 %</span></span>
                  <span class="symbol-text"><strong>Delta-chip</strong> — förändringen i ägarantal sedan föregående handelsdag (på måndagar jämfört med fredagen), alltid som antal och procent tillsammans. Visas inte när föregående lagrade dag ligger mer än fem dagar bakåt.</span>
                </li>
                <li>
                  <span class="symbol-example symbol-example--trend" aria-hidden="true">
                    <svg class="sparkline" viewBox="0 0 52 22" preserveAspectRatio="none"><polyline class="sparkline-line--positive" points="0,18 17,12 35,8 52,2" /></svg>
                    <svg class="sparkline" viewBox="0 0 52 22" preserveAspectRatio="none"><polyline class="sparkline-line--nohistory" points="0,11 17,13 35,9 52,11" /></svg>
                  </span>
                  <span class="symbol-text"><strong>Trend</strong> — linjegraf över ägarantalet de senaste 30 dagarna, färgkodad grön (upp), röd (ned), gul (spik) eller streckad grå (otillräcklig historik, färre än 7 lagrade dagar).</span>
                </li>
              </ul>
            </section>
            <section>
              <h2>Stadig tillväxt</h2>
              <p>Rankningsläget "Stadig tillväxt" på Topplista, och filtret med samma namn på Fullständig lista, kvalificerar om aktien har minst 1 dags obruten uppgångssvit och inte just nu spikar. På Topplista sorteras de kvalificerade aktierna efter ägarantalets procentuella ökning under vald period (<strong>Vecka</strong>, <strong>Månad</strong>, <strong>3 mån</strong> eller <strong>År</strong>; standard är Månad), med störst ökning först. Aktier som saknar procentsats för perioden (till exempel nyligen noterade) hamnar sist, ordnade efter längst svit. Spikande aktier utesluts alltid, så här finns ingen "Dölj spikar"-växlare. Ingen rankning visas förrän källans historik täcker hela perioden.</p>
              <p>Med andra ord: instrumentet måste ha en pågående uppgångssvit (samma villkor som Streak-symbolen ovan), och får inte samtidigt vara flaggat som en spik (samma villkor som Spike-symbolen ovan). Ett instrument med lång uppgångssvit men en kraftig engångsökning den senaste dagen räknas alltså inte som stadig tillväxt — det visas inte alls i det läget, oavsett hur lång sviten är. Instrument utan pågående uppgångssvit (0 dagar eller okänt) kvalificerar aldrig. På Topplista avgör sedan periodens procentuella ökning ordningen; svitens längd används bara när två instrument har samma ökning och för instrument som saknar procentsats för perioden.</p>
            </section>
            <section>
              <h2>Plusdagar</h2>
              <p>Rankningsläget "Plusdagar" på Topplista räknar, för vald period (<strong>Vecka</strong>, <strong>Månad</strong>, <strong>3 mån</strong> eller <strong>År</strong> — 7, 30, 90 respektive 365 kalenderdagar bakåt från källans senaste dag; standard är Månad), hur många dagar ägarantalet var oförändrat eller högre än föregående lagrade dag. En dag räknas bara om föregående lagrade dag ligger högst fem dagar bakåt.</p>
              <p>Bara aktier med netto fler ägare under perioden rankas: nya ägare räknas som ägarantalet i dag minus ägarantalet den senast lagrade dagen på eller före periodens start (för en nyintroducerad aktie: dess första dag i perioden). Står ägarantalet still hela perioden, eller har det minskat netto, visas aktien inte. De som kvalificerar sorteras efter flest plusdagar (antal, inte andel), därefter flest nya ägare.</p>
              <p>Varje rad visar <span aria-hidden="true">{$plusDaysChip}</span> — 16 plusdagar av 18 dagar med jämförbar data, och 1 035 nya ägare under perioden. Nämnaren visas alltid, så att en ny aktie på "5/5" inte förväxlas med en som hållit i sig hela månaden. Till skillnad från Stadig tillväxt försvinner en aktie inte på grund av en enda dålig dag; dagens förändring syns fortfarande i delta-chipet.</p>
              <p>Spikande aktier ingår som standard. Med "Dölj spikar" döljs aktier vars senaste dag är flaggad som spik (samma villkor som Spike-symbolen ovan). Spikvärden kräver 30 lagrade dagar, så fram till dess ändrar växlaren ingenting. För 3 mån och År visas ingen rankning förrän källans historik täcker hela perioden.</p>
            </section>
          </main>
        </div>
        </body>
        </html>

        HTML;
    }

    private static function tabBarHtml(string $source): string
    {
        $suffix = $source === NormalizedRow::SOURCE_NORDNET ? '?source=nordnet' : '';
        $topplistaHref = self::e('/' . $suffix);
        $watchlistHref = self::e('/watchlist' . $suffix);

        return <<<HTML
        <div class="tab-bar" role="tablist" aria-label="Sidor">
          <a class="tab tab--active" href="{$topplistaHref}">Topplista</a>
          <a class="tab" href="{$watchlistHref}">Bevakningslista</a>
        </div>
        HTML;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
