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
 * real rule (up_streak >= 1 AND not spiking, ordered by up_streak DESC;
 * Code Map, spec-5-3), not restated from memory or the epic's prose.
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
        $css = self::css();

        return <<<HTML
        <!DOCTYPE html>
        <html lang="sv">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Information — stockpicker</title>
        <style>{$css}</style>
        </head>
        <body>
        <div class="page">
          {$tabBar}
          <header class="page-header">
            <div class="wordmark">STOCKPICKER</div>
            <h1>Information</h1>
            <p class="subtitle">Vad sidorna visar, vad symbolerna betyder och exakt vad som räknas som stadig tillväxt.</p>
          </header>
          <main class="content">
            <section>
              <h2>Topplista</h2>
              <p>Startsidan. Visar en topplista med instrument rankade efter antal ägare ("Flest ägare") eller efter hur länge ägarantalet har ökat i följd utan avbrott ("Stadig tillväxt"). Du väljer källa (Avanza eller Nordnet) och rankningsläge högst upp på sidan.</p>
            </section>
            <section>
              <h2>Fullständig lista</h2>
              <p>Nås via länken "Visa fullständig lista" längst ned på Topplista. Visar alla aktiva instrument för vald källa, med sökfält, sortering och filter (stadig tillväxt, spik, bevakade, marknad) — till skillnad från Topplista, som bara visar de tio översta i vald rankning.</p>
            </section>
            <section>
              <h2>Bevakningslista</h2>
              <p>Din personliga lista över instrument du har stjärnmärkt. Samma radutseende som Topplista, men utan sökfält, sortering och filter — en bevakningslista är liten till sin natur. Nås via fliken "Bevakningslista" högst upp på varje sida.</p>
            </section>
            <section>
              <h2>Aktiedetalj</h2>
              <p>Nås genom att klicka på ett instrument i någon av listorna. Visar instrumentets namn, en graf över ägarantalet över tid för både Avanza och Nordnet (vald källa som heldragen linje, den andra källan alltid streckad), ett intervallval (Dag/Vecka/30d/90d/År), samt en länk till aktiens egen sida på Avanza (öppnas i en ny flik).</p>
            </section>
            <section>
              <h2>Symboler</h2>
              <ul class="symbol-list">
                <li><span class="symbol" aria-hidden="true">🔥</span> <strong>Streak</strong> — antal dagar i rad som ägarantalet har ökat, utan avbrott. Visas som "🔥 Xd". Finns ingen pågående uppgångssvit visas "flat" istället.</li>
                <li><span class="symbol" aria-hidden="true">⚡</span> <strong>Spike</strong> — ägarantalet har ökat ovanligt mycket på en enda dag. Flaggas alltid, döljs aldrig, så att en kraftig engångsökning inte förväxlas med en äkta trend.</li>
                <li><span class="symbol" aria-hidden="true">☆ / ★</span> <strong>Bevakningsstjärna</strong> — ☆ betyder att instrumentet inte är bevakat, ★ att det är stjärnmärkt. Klicka på stjärnan för att lägga till eller ta bort instrumentet från Bevakningslistan.</li>
                <li><strong>Delta-chip</strong> — förändringen i ägarantal sedan föregående dag, alltid som antal och procent tillsammans (till exempel "+412 · 0,9 %"). Visas inte när det saknas en jämförbar föregående dag.</li>
              </ul>
            </section>
            <section>
              <h2>Stadig tillväxt</h2>
              <p>Rankningsläget "Stadig tillväxt" på Topplista, och filtret med samma namn på Fullständig lista, kvalificerar om aktien har minst 1 dags obruten uppgångssvit och inte just nu spikar; sorteras med längst svit först.</p>
              <p>Med andra ord: instrumentet måste ha en pågående uppgångssvit (samma villkor som Streak-symbolen ovan), och får inte samtidigt vara flaggat som en spik (samma villkor som Spike-symbolen ovan). Ett instrument med lång uppgångssvit men en kraftig engångsökning den senaste dagen räknas alltså inte som stadig tillväxt — det visas inte alls i det läget, oavsett hur lång sviten är. Instrument utan pågående uppgångssvit (0 dagar eller okänt) kvalificerar aldrig. De instrument som kvalificerar sorteras med längst uppgångssvit högst upp.</p>
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

    private static function css(): string
    {
        return <<<'CSS'
        :root {
          --bg-app: #F5F6FA;
          --bg-surface: #FFFFFF;
          --border: #E4E7EC;
          --row-border: #EEF0F5;
          --control-bg: #ECEEF3;
          --text-primary: #101323;
          --text-secondary: #667085;
          --text-muted: #98A2B3;
          --brand: #5B4FE9;
          --brand-tint: #EEEDFD;
        }
        * { box-sizing: border-box; }
        body {
          margin: 0;
          background: var(--bg-app);
          color: var(--text-primary);
          font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        }
        .page { max-width: 720px; margin: 0 auto; padding: 18px; }
        .tab-bar {
          display: inline-flex; background: var(--control-bg); border-radius: 9999px; padding: 3px; gap: 2px;
          margin: 0 0 14px;
        }
        .wordmark {
          font-size: 12px; font-weight: 800; letter-spacing: 0.06em;
          color: var(--brand); text-transform: uppercase;
        }
        .tab {
          padding: 8px 14px; border-radius: 9999px; text-decoration: none;
          font-size: 13px; font-weight: 700; color: var(--text-secondary);
        }
        .tab-bar .tab--active { background: var(--text-primary); color: var(--bg-surface); }
        h1 { font-size: 22px; font-weight: 800; margin: 4px 0 2px; }
        .subtitle { font-size: 12.5px; color: var(--text-secondary); margin: 0 0 18px; }
        .content { display: flex; flex-direction: column; gap: 16px; }
        section {
          background: var(--bg-surface); border: 1px solid var(--row-border);
          border-radius: 16px; padding: 14px 16px;
        }
        h2 { font-size: 15px; font-weight: 800; margin: 0 0 8px; }
        p { font-size: 13.5px; line-height: 1.5; color: var(--text-primary); margin: 0 0 8px; }
        p:last-child { margin-bottom: 0; }
        .symbol-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
        .symbol-list li { font-size: 13.5px; line-height: 1.5; color: var(--text-primary); }
        .symbol { display: inline-block; min-width: 1.4em; }
        @media (min-width: 900px) {
          .page { max-width: 960px; box-shadow: 0 12px 40px rgba(16,19,31,0.08); border-radius: 20px; background: var(--bg-app); }
        }
        CSS;
    }
}
