# Epic 3 Context: Härledda mått

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

On top of the now-real owner-count time series (built in Epics 1–2), this epic adds the
derived metrics that turn raw daily levels into something an analyst can actually read:
day-over-day change, percentage change, moving averages, consecutive-increase streaks,
and a spike/anomaly indicator. These are the foundation for a future analysis layer —
this epic only computes and exposes them, it does not build that layer.

## Stories

- Story 3.1: Deriver — beräkna härledda mått
- Story 3.2: /cron/derive-endpoint
- Story 3.3: Uttag av en akties serie och mått

## Requirements & Constraints

- For every `(isin, source)` pair, compute from the `number_of_owners` series ordered by
  `as_of_date`: `delta_1d`, `pct_1d`, `sma_7`, `sma_30`, `sma_90`, `up_streak` (consecutive
  days with a net increase), and `spike_score` (deviation from the instrument's own trend).
- Metrics are always computed per source (Avanza, Nordnet) and never merged into a
  combined figure — the two sources measure different customer populations and summing
  them is meaningless (same principle already applied to the raw `owner_count_daily`
  data).
- When fewer than 7 data points exist for an `(isin, source)`, metrics that need more
  history return `null` rather than erroring.
- The system runs entirely on Loopia shared hosting: PHP 8.3+ and MariaDB 10.11, no other
  runtime or database available for the computation.
- No component exposes fetched or derived data externally; this remains strictly
  personal-use tooling with no public read surface.
- Data volume stays small (roughly 730,000 fact rows/year), so metric computation must
  stay cheap enough to run within the shared host's memory/time limits.

## Technical Decisions

- **View, not materialized table** (decided 2026-09-11): derived metrics are implemented
  as a MariaDB SQL view using window functions, not a materialized `owner_metrics_daily`
  table. There is no rebuild/refresh logic — `/cron/derive` does not need to trigger any
  materialization step.
- **Deriver never writes facts**: per the single-writer-per-row rule, `Deriver` only reads
  from `owner_count_daily`; it never writes to it, and it is not the owner of any base
  table. `instrument` is owned exclusively by `UniverseSync`; `owner_count_daily` is
  source-partitioned and owned by each source's fetch flow.
- **Pipeline placement**: `Deriver` is a pipeline filter in `src/Pipeline/`, invoked as the
  final step of the nightly chain: `UniverseSync → Enqueue → FetchRunner → Normalizer →
  Deriver`. It follows the same architectural boundary as the rest of the pipeline — no
  direct SQL outside `src/Store/`/the view definition, no HTTP or source-specific logic.
- **`/cron/derive` endpoint**: a thin front-controller route, token-authenticated the same
  way as the other cron endpoints (`hash_equals` against the configured token, 403 on
  missing/wrong token). It is intended to run once the day's `work_queue` has been drained
  by the `/cron/work` loop, completing the nightly sequence
  `/cron/refill → /cron/work (looped) → /cron/derive`.
- **Extraction is a SSH script, not a new endpoint** (decided 2026-09-11): pulling a full
  series-plus-metrics readout for one instrument is done via a `bin/` script run over SSH,
  following the same pattern and output discipline as the existing `bin/show-runs.php`
  script from Epic 1 — no new protected HTTP read endpoint is introduced.
- Conventions to keep consistent with the rest of the codebase: `snake_case`, singular
  table names; ISIN as the natural key everywhere; dates in `Europe/Stockholm` calendar
  terms for `as_of_date`.

## Cross-Story Dependencies

- This epic depends on Epics 1 and 2 having populated `owner_count_daily` with real,
  source-partitioned history — `Deriver` has nothing to compute against otherwise.
- Story 3.1 (the view/computation itself) must exist before Story 3.2 (the cron trigger)
  and Story 3.3 (the extraction script) can be meaningfully implemented or tested.
- Story 3.2 assumes the existing work-queue mechanics from Epic 1/2 (`/cron/work` looping
  until the queue is empty) as its natural trigger point in the nightly sequence.
- Story 3.3 reuses the rendering/output conventions established by Epic 1's
  `bin/show-runs.php`, rather than introducing a new pattern.
