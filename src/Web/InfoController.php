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
 * copied faithfully from DerivedMetricsRepository::topByTrendQuality()'s
 * real rule (not currently declining, flat included, AND not spiking;
 * ordered by streak length then owner count, no period; spec-5-3,
 * spec-topplista-steady-no-period), not restated from memory or the epic's
 * prose.
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
        $homeHref = self::e(self::homeHref($source));
        $plusDaysChip = LeaderboardController::plusDaysChipHtml(16, 18, 1035);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Information — stockpicker</title>
        <link rel="stylesheet" href="/assets/app.css">
        <link rel="icon" type="image/png" href="/assets/favicon.png">
        </head>
        <body>
        <div class="page">
          <div class="nav-strip">
            <a class="wordmark" href="{$homeHref}"><img src="/assets/stockpicker-logo-horizontal.png" alt="Stockpicker" class="wordmark-logo"></a>
            {$tabBar}
          </div>
          <header class="page-header">
            <h1>Information</h1>
            <p class="subtitle">Vad sidorna visar, vad symbolerna betyder och exakt vad som räknas som stadig tillväxt och plusdagar.</p>
          </header>
          <main class="content">
            <section>
              <h2>Topplista</h2>
              <p>Startsidan. Visar en topplista med instrument rankade efter hur många dagar under en period ägarantalet har stått still eller ökat ("Plusdagar"), efter antal ägare ("Flest ägare") eller efter hur länge ägarantalet har ökat i följd utan att spika, med flest ägare som tiebreak ("Stadig tillväxt"). Plusdagar är standardläget. Överst finns tre rader, likadana i alla tre lägen. På första raden väljer du källa (Alla, Avanza eller Nordnet) och rankningsläge. På andra raden väljer du period (Vecka, Månad, 3 mån eller År); perioden delas mellan alla lägen. Flest ägare och Stadig tillväxt rankar båda utan att perioden spelar någon roll, så där är periodraden nedtonad och går inte att klicka på, men vald period står kvar markerad till nästa läge. På tredje raden väljer du marknad: Alla, LC, MC, SC eller First North. Topp tio räknas inom vald marknad, inte som ett filter på den vanliga topp tio. Period och marknad följer med när du byter läge eller källa, och "Visa fullständig lista" öppnar med samma marknad. Med "Dölj blankade", som finns i alla tre lägen, döljs aktier som är blankade till minst 5 % enligt Finansinspektionens senaste lista (samma villkor som Blankad-symbolen nedan); topp tio fylls då på underifrån. Topplista kommer ihåg din senaste vy (källa, läge, period, marknad, "Dölj spikar" och "Dölj blankade") i en kaka i webbläsaren, tills du gör ett annat val. Därför kommer du tillbaka till samma vy från de andra sidorna och nästa gång du öppnar sidan. Första gången, eller om kakan saknas, öppnas Plusdagar med Månad, Alla och Avanza.</p>
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
                  <span class="symbol-example" aria-hidden="true"><span class="badge badge--short">Blankad 15,8 %</span></span>
                  <span class="symbol-text"><strong>Blankad X %</strong> — bolagets sammanlagda korta positioner enligt Finansinspektionens blankningsregister, när de är minst 5 % av aktierna. Gäller bolaget, inte en enskild aktieserie, så A- och B-aktier får samma värde, och samma värde visas oavsett källa. Bara Finansinspektionens senaste lista räknas; ett bolag som inte längre finns med där får ingen symbol. På Aktiedetalj står även datumet för Finansinspektionens senaste rapporterade position, till exempel "(FI 2 okt)".</span>
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
              <p>Rankningsläget "Stadig tillväxt" på Topplista, och filtret med samma namn på Fullständig lista, kvalificerar en aktie om den inte just nu minskar — senaste dagens förändring måste vara noll eller positiv (en helt ny notering utan en föregående dag räknas också in) — och inte just nu spikar. En "flat" dag (ingen förändring) kvalificerar alltså precis som en uppgångsdag; bara en nedgång diskvalificerar. På Topplista sorteras de kvalificerade aktierna efter längst pågående uppgångssvit först, sedan flest ägare som tiebreak — ingen period är inblandad, så periodraden är nedtonad och overksam i det här läget, precis som i Flest ägare. Spikande aktier utesluts alltid, så här finns ingen "Dölj spikar"-växlare.</p>
              <p>Med andra ord: instrumentet får inte ha en pågående nedgång (samma gräns som Delta-chipens röda färg), och får inte samtidigt vara flaggat som en spik (samma villkor som Spike-symbolen ovan). Ett instrument med lång uppgångssvit men en kraftig engångsökning den senaste dagen räknas alltså inte som stadig tillväxt — det visas inte alls i det läget, oavsett hur lång sviten är. Två instrument som båda är flat (ingen pågående svit) särskiljs bara på antal ägare.</p>
            </section>
            <section>
              <h2>Plusdagar</h2>
              <p>Rankningsläget "Plusdagar" på Topplista räknar, för vald period (<strong>Vecka</strong>, <strong>Månad</strong>, <strong>3 mån</strong> eller <strong>År</strong> — 7, 30, 90 respektive 365 kalenderdagar bakåt från källans senaste dag; standard är Månad), hur många dagar ägarantalet var oförändrat eller högre än föregående lagrade dag. En dag räknas bara om föregående lagrade dag ligger högst fem dagar bakåt.</p>
              <p>Bara aktier med netto fler ägare under perioden rankas: nya ägare räknas som ägarantalet i dag minus ägarantalet den senast lagrade dagen på eller före periodens start (för en nyintroducerad aktie: dess första dag i perioden). Står ägarantalet still hela perioden, eller har det minskat netto, visas aktien inte. De som kvalificerar sorteras efter flest plusdagar (antal, inte andel), därefter flest nya ägare.</p>
              <p>Varje rad visar <span aria-hidden="true">{$plusDaysChip}</span> — 16 plusdagar av 18 dagar med jämförbar data, och 1 035 nya ägare under perioden. Nämnaren visas alltid, så att en ny aktie på "5/5" inte förväxlas med en som hållit i sig hela månaden. Till skillnad från Stadig tillväxt försvinner en aktie inte på grund av en enda dålig dag; dagens förändring syns fortfarande i delta-chipet.</p>
              <p>Spikande aktier ingår som standard. Med "Dölj spikar" döljs aktier vars senaste dag är flaggad som spik (samma villkor som Spike-symbolen ovan). Spikvärden kräver 30 lagrade dagar, så fram till dess ändrar växlaren ingenting. "Dölj blankade" till höger döljer på samma sätt aktier som är blankade till minst 5 % (se Blankad-symbolen ovan). Båda valen sparas i kakan tillsammans med resten av vyn. För 3 mån och År visas ingen rankning förrän källans historik täcker hela perioden.</p>
            </section>
          </main>
        </div>
        </body>
        </html>

        HTML;
    }

    /**
     * Shared by tabBarHtml()'s Topplista tab and the wordmark/home link
     * (render()) — per the design handbook's 2026-10-06 wordmark-is-a-link
     * decision ("samma mål och URL-regler som flikfältets Topplista-flik").
     */
    private static function homeHref(string $source): string
    {
        return '/' . ($source === NormalizedRow::SOURCE_NORDNET ? '?source=nordnet' : '');
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
