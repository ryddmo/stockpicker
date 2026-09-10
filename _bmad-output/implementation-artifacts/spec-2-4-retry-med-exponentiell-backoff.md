---
title: 'Story 2.4: Retry med exponentiell backoff'
type: 'feature'
created: '2026-09-10'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: '0f6765bd803d310e33597f15eb1290ef5df83eda'
context:
  - _bmad-output/implementation-artifacts/epic-2-context.md
  - _bmad-output/implementation-artifacts/spec-1-7-work-queue-och-tidsboxad-fetchrunner.md
  - _bmad-output/implementation-artifacts/spec-2-3-delfeltolerans-och-fellogg-per-instrument.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** On a `Transient` from a source, `FetchRunner` today does exactly one thing —
reopen the whole job to `pending` — and the only retry anywhere is the adapters' immediate,
no-delay `withOneRetry()` around `fetch()`. A source that is briefly throttled or flaky
therefore contributes nothing useful that slice, and a `429` gets the same instant re-hammer
as a `503`. The `Transient` exception carries no status, so nothing can tell a rate-limit
from a server error.

**Approach:** Move retry ownership up into `FetchRunner` (superseding the adapter
`withOneRetry()` for the fetch path). Per source per job, retry `fetch()` on `Transient` with
exponential backoff up to a cap and up to a max attempt count, all bounded by the slice
timebox; if it still fails the job is left `pending` for the next cron pass (no permanent
give-up, no `work_queue` change). Distinguish `429` from other transients: a `429` widens
that retry's backoff and halves the in-memory call rate for that source for the rest of the
slice. External calls stay strictly serial. Per-source retry/rate-limit counts join the
`by_source` tally and the `slice complete` log line.

## Boundaries & Constraints

**Always:**
- The retry loop lives in `FetchRunner`, per source per job. On `Transient` from
  `adapters[$source]->fetch()`: sleep `backoff(attempt)` via the injected `$sleep` seam, then
  call `fetch()` again, up to `retry.max_attempts` total attempts. `backoff(n)` is
  `retry.backoff_base * 2^(n-1)` s, clamped to `retry.backoff_max`. A retry is **skipped**
  (loop ends → the job's final `Transient`) when `elapsed + next backoff >= timeboxSeconds` —
  the slice budget always wins.
- **Settings (decision, 2026-09-10):** three new `settings` keys, unseeded, read via
  `FetchRunner::numericSetting()` (positive-or-default — needs `DEFAULTS` entries):
  `retry.max_attempts` = **3**, `retry.backoff_base` = **1.0** s, `retry.backoff_max` =
  **20.0** s. The `429` backoff multiplier (**×4**) and the rate-reduction floor
  (**`base_rate / 8`**, i.e. ≤ 3 halvings) are hard-coded `FetchRunner` constants — structural,
  not operator knobs.
- A still-`Transient` source after the loop behaves exactly as Story 2.3 left it:
  `$sawTransient` → the whole job is reopened to `pending`; the other source's written row
  stays; job-state precedence (`Transient` → `pending`; else `NotFound`/`SchemaMismatch` →
  `failed`; else → `done`) unchanged. No permanent `failed`, no `work_queue` change, no
  cross-slice attempt memory — the next cron pass re-claims and retries from scratch.
- A `429` reaches the `FetchRunner` seam as `RateLimited extends Transient` (decision,
  2026-09-10 — the adapter layer maps status `429` → `RateLimited`, everything else transient
  → plain `Transient`; no branching on a raw HTTP status). On a `RateLimited` from a source:
  (a) that retry sleeps `min(backoff_max, backoff(attempt) * 4)`; (b) the in-memory
  `$rate[$source]` is halved (larger same-source spacing), floored at `base_rate / 8`, for the
  **rest of the slice** — runtime only, never written to `settings`; `settings.rate.<source>`
  is the unchanged base each slice restarts from. `Retry-After` response headers are not read.
- External calls stay strictly serial — one `fetch()` (retries included) in flight at a time.
  The same-source spacing sleep (`1 / rate.<source>`) still applies, now reading the
  possibly-reduced runtime rate.
- The adapters' `fetch()` drops the `withOneRetry()` wrap around `fetchDatapoint()` — it
  throws `Transient` on the first failure so `FetchRunner` owns every fetch retry.
  `withOneRetry()` / `HandlesTransientHttp` stay for `resolveId()` and the universe-adapter
  paths (out of scope).
- `FetchRunnerResult.bySource` gains `retried` (retries taken for that source this slice) and
  `rate_limited` (429s seen); the `slice complete` `info` line carries them. `transient` still
  counts a source whose retries were all exhausted (one per job).

**Never:**
- No `work_queue` schema change, persisted attempt counter, permanent job give-up, or
  cross-slice backoff state. No parallel/bulk source calls.
- No change to `withOneRetry()` for `resolveId()` / `AvanzaUniverseAdapter` / `SourceIdResolver`,
  to `OwnerCountRepository` / `NormalizedRow` / the queue state values / the per-job
  `ingest_run` `ok_count`/`fail_count` semantics, or the `by_source` persistence (Story 2.6).
- No alarm / notification (Story 2.6). No response-header handling beyond the `429` mechanism.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Behavior | Error Handling |
|----------|--------------|-------------------|----------------|
| Transient then success | source `fetch()` throws `Transient` once, returns a row on retry | one backoff sleep, row upserted, job → `done`; `by_source.<src>` = {ok:1, retried:1} | `warning` on the transient attempt |
| Transient past the cap | source throws `Transient` on every attempt (`retry.max_attempts`) | `max_attempts - 1` backoff sleeps, job reopened `pending`; `by_source.<src>` = {transient:1, retried:max_attempts-1} | one `warning` per failed attempt |
| Timebox squeezes retries | source keeps failing; `elapsed + next backoff >= timeboxSeconds` | retry loop ends early (fewer than max attempts), job reopened `pending`; remaining jobs still timebox-reopened at the loop top | `warning` per attempt made |
| 429 then success | source throws a rate-limit transient once, then returns a row | a widened backoff sleep, `$rate[src]` halved for the rest of the slice, row upserted; `by_source.<src>` = {ok:1, retried:1, rate_limited:1} | `warning` naming the rate limit |
| 429 lowers rate for later jobs | job 1 source 429s once then ok; job 2 same source ok | job 2's same-source spacing uses the reduced rate (larger `$sleep` value) | N/A |
| Both sources transient for a job | avanza + nordnet each `Transient` past the cap | each retried to the cap, job reopened `pending`, no row; `by_source` transient:1 + retried counts each | `warning` per attempt per source |
| NotFound / SchemaMismatch | unchanged from Story 2.3 | no retry (not a `Transient`), bucket + `warning` as today, job → `failed` | one `warning` |
| Clean slice | all sources return first try | zero backoff sleeps, all `done`; `retried`/`rate_limited` all 0 | N/A |

</frozen-after-approval>

## Code Map

- `src/Pipeline/FetchRunner.php:147-194` — the per-source loop. Wrap `fetch()` + `++ok` +
  `upsert` in an attempt loop (see Design Notes). `catch (RateLimited)` before
  `catch (Transient)`: `++rate_limited`, halve `$rate[$source]` (floored at `base/8`), widened
  backoff; plain `Transient` → plain backoff. `++retried` per retry taken, `warning` per
  failed attempt; on the last attempt fall through to the existing `$sawTransient = true` +
  `++transient`. Each `($this->sleep)(…)` gated by `$start` / `$timeboxSeconds`. Leave the
  `:196-205` job-state block and `:206-218` `\Throwable` arm untouched.
- `src/Pipeline/FetchRunner.php:96-99` — `$rate` is already a mutable local; keep a
  `$baseRate` copy for the `/8` floor. `:50-55` `DEFAULTS` — add `retry.max_attempts` (3),
  `retry.backoff_base` (1.0), `retry.backoff_max` (20.0); the ×4 429 factor and `/8` floor are
  `FetchRunner` consts. `:220-240` — add `retried` / `rate_limited` to bucket init + the
  `slice complete` line.
- `src/Pipeline/FetchRunnerResult.php` — extend the `bySource` shape docblock (`retried`,
  `rate_limited`).
- `src/Error/RateLimited.php` — **new**, `final class RateLimited extends Transient`.
  `src/Error/Transient.php` docblock updated.
- `src/Adapter/HandlesTransientHttp.php:29-52` `requestJson()` — `$status === 429` → throw
  `RateLimited`; `>= 500` / `ConnectException` stay `Transient`. No `Retry-After` reading.
- `src/Adapter/AvanzaAdapter.php:59` / `NordnetAdapter.php:60` — `fetch()` drops the
  `withOneRetry()` wrap around `fetchDatapoint()`; the `catch (AdapterError) { warn; throw; }`
  stays. `resolveId()` keeps `withOneRetry`.
- `tests/Support/FakeSourceAdapter.php` — no change (a queued `[Transient, Transient, row]` is
  consumed one-per-attempt); may add a `RateLimited` throwable to fixtures.
- `tests/Pipeline/FetchRunnerTest.php` — new matrix cases; `$this->waits` already captures the
  ordered sleep durations — assert the backoff values. Update
  `testTransientReopensTheJobAndTheRunnerContinues` (retries before reopening).
- `tests/Adapter/AvanzaAdapterTest.php` / `NordnetAdapterTest.php` —
  `testFetchThrowsTransientWhenBothAttemptsFail` becomes "throws on the first failure".
- `public_html/index.php` `/cron/work` — unchanged. `docs/deploy.md` — document `retry.*` +
  the 429 rate-reduction.

## Tasks & Acceptance

**Execution:**
- [x] `src/Error/RateLimited.php` -- new `final class RateLimited extends Transient` -- lets `FetchRunner` tell a 429 from a 5xx without touching a raw HTTP status (AD-2).
- [x] `src/Adapter/HandlesTransientHttp.php` -- `requestJson()` throws `RateLimited` for a `429`, `Transient` for connect errors / `5xx` -- the 429 signal reaches the runner.
- [x] `src/Adapter/AvanzaAdapter.php`, `src/Adapter/NordnetAdapter.php` -- `fetch()` no longer wraps `fetchDatapoint()` in `withOneRetry()` -- `FetchRunner` owns every fetch retry (supersedes FR9's one-shot adapter retry).
- [x] `src/Pipeline/FetchRunner.php` -- per-source retry loop with exponential backoff (`retry.backoff_base * 2^(n-1)`, capped `retry.backoff_max`, ≤ `retry.max_attempts`), each sleep gated by the slice timebox; a `429` widens that backoff and halves the runtime `$rate[$source]` (floored) for the rest of the slice; `retried` / `rate_limited` per-source counts; a still-transient source still reopens the whole job (unchanged) -- the story.
- [x] `src/Pipeline/FetchRunnerResult.php` -- `bySource` buckets gain `retried`, `rate_limited` -- the counts travel to the caller + the `slice complete` line.
- [x] `tests/Pipeline/FetchRunnerTest.php` -- one case per I/O & Edge-Case Matrix row (Transient-then-ok, past-the-cap, timebox-squeezed, 429-then-ok, 429-lowers-later-spacing, both-sources-transient), assert `$this->waits` backoff values; regression cases stay green -- edge-case coverage.
- [x] `tests/Adapter/AvanzaAdapterTest.php`, `tests/Adapter/NordnetAdapterTest.php` -- `fetch()` now throws `Transient` on the first failure (was two attempts) -- adapter contract follows the retry move.
- [x] `docs/deploy.md` -- `retry.max_attempts` / `retry.backoff_base` / `retry.backoff_max` settings + the 429 rate-reduction note -- operator setup.

**Acceptance Criteria:**
- Given a source `fetch()` that throws `Transient` then returns a row, when `FetchRunner` processes the job within the timebox, then it retries after an exponential-backoff sleep and the row is stored; if every attempt up to `retry.max_attempts` throws `Transient`, the job is left `pending` for the next cron pass (never permanently `failed`).
- Given a `429` from a source during a slice, when it is received, then that retry's backoff is longer than the plain exponential step and the source's call rate is reduced (larger same-source spacing) for the remainder of the slice, starting again from `settings.rate.<source>` on the next slice.
- Given `FetchRunner` making external calls, then they are strictly serial — one `fetch()` (retries included) in flight at a time.
- Given `composer test`, then the full suite is green with no regressions and the per-job `ingest_run` `ok_count` / `fail_count` semantics are unchanged.

## Implementation Notes

- **2026-09-10 — implemented.**
  - `src/Error/RateLimited.php` — `final class RateLimited extends Transient`. `Transient`
    dropped `final` (docblock updated) so the subtype is possible; the pipeline still
    catches `Transient` and every existing `catch (Transient)` (incl. `withOneRetry()` and
    `AvanzaUniverseAdapter`) transparently keeps catching a 429 as before.
  - `src/Adapter/HandlesTransientHttp.php` — `requestJson()` splits the old
    `$status === 429 || $status >= 500` arm: `429 → RateLimited`, `>= 500 → Transient`.
    No `Retry-After` reading. `AvanzaUniverseAdapter` is unchanged and unaffected (its
    `withOneRetry()` catches the `RateLimited` as a `Transient`).
  - `src/Adapter/{Avanza,Nordnet}Adapter.php` — `fetch()` calls `fetchDatapoint()`
    directly (no `withOneRetry()` wrap); the `catch (AdapterError) { warn; throw; }` stays.
    `resolveId()` keeps `withOneRetry()`.
  - `src/Pipeline/FetchRunner.php` — the per-source `fetch()`/`++ok`/`upsert` is wrapped in
    a `while (true)` attempt loop. `catch (RateLimited)` before `catch (Transient)`:
    `++rate_limited`, `$rate[$source] = max($baseRate/8, $rate/2)`, `$wait = min(backoff_max,
    backoff(n) * 4)`; plain `catch (Transient)` → `$wait = min(backoff_max, backoff(n))`.
    `catch (NotFound | SchemaMismatch)` is unchanged and `break`s (not retryable). Shared
    give-up test: `$attempt >= $maxAttempts || (microtime(true) - $start) + $wait >=
    $timeboxSeconds` → `$sawTransient = true; ++transient; break`; else `($this->sleep)($wait);
    ++retried`. `$baseRate = $rate` copy kept for the `/8` floor; the runtime `$rate` halving
    persists across jobs for the slice. New `DEFAULTS`: `retry.max_attempts` 3,
    `retry.backoff_base` 1.0, `retry.backoff_max` 20.0 (read via `intSetting`/`floatSetting`
    → `numericSetting`, positive-or-default). `RATE_LIMIT_BACKOFF_FACTOR` (4.0) and
    `RATE_LIMIT_RATE_FLOOR_DIVISOR` (8.0) are hard-coded consts. `backoff(int, float)` is a
    new private helper. The `:196-205` job-state block and `:206-218` `\Throwable` arm are
    untouched — a raw non-`AdapterError` throw still propagates out of the loop to the outer
    `catch` and fails just that job.
  - `src/Pipeline/FetchRunnerResult.php` — each `bySource` bucket gains `retried` and
    `rate_limited` (docblock updated). Both flow into the `slice complete` info line and the
    `/cron/work` `by_source` JSON with no code change at those sites.
  - Tests: 5 new `FetchRunnerTest` cases (Transient-then-ok, past-the-cap, timebox-squeezed,
    429-then-ok, 429-lowers-later-spacing) asserting `$this->waits` backoff/spacing values;
    every existing transient case updated to queue one throwable per attempt (the fake
    consumes one-per-attempt now); a `bucket()` helper for the 6-key `assertSame` literals.
    `testTransientReopensTheJobAndTheRunnerContinues` now queues 3 nordnet Transients.
    `Avanza/NordnetAdapterTest` — the two `testFetch…RetriesOnce…` / `…BothAttemptsFail`
    fetch tests collapsed to one `testFetchThrowsTransientOnTheFirstFailure` (asserts one
    attempt). `FrontControllerIntegrationTest` + `EndToEndSmokeTest` updated for the 6-key
    bucket and the pass-1 retry (3 Transients, 4 nordnet calls total).
- **Verification:** `composer test` — 220 tests / 954 assertions green (DB up). `php -l`
  clean on all changed `src/` files. `composer validate --strict` clean. `withOneRetry`
  grep: only `resolveId()` in the two owner adapters.

  - **2026-09-10 — review pass 1 patches (P1-P8):** `max(1, retry.max_attempts)`; the
    same-source spacing sleep is now timebox-gated (skipped when it would overrun); retry
    logging reworked — the "retrying" warning fires only when a retry will actually happen,
    a distinct `fetchrunner: source exhausted fetch retries, job reopened` warning at
    give-up, `error => $e::class` on the transient/rate-limited lines; adapter tests gained
    `testFetchThrowsRateLimitedOnA429`; `FetchRunnerTest` gained the rate-floor plateau,
    the reduced-rate-spacing-skip, the 1-retry-then-timebox, and the
    Transient-then-NotFound-→-`failed` cases.
  - **Verification:** `composer test` — 225 tests / 982 assertions green (from 217). `php -l`
    clean. `withOneRetry` on the owner adapters appears only in `resolveId()`.

## Spec Change Log

## Review Triage Log

Review pass 1 (2026-09-10) — blind-hunter, edge-case-hunter, verification-gap. No `bad_spec` /
`intent_gap`; all findings are implementation-level.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| P1 | No test that HTTP `429` → `RateLimited` at the adapter layer — the `FetchRunner` 429 tests hand-feed `new RateLimited()` through the fake, and the adapter `testFetch…` cases only queue a `503`. A regression collapsing `requestJson()` back to `if ($status === 429 \|\| $status >= 500) throw new Transient` ships green and kills the whole 429 mechanism (plain backoff, no rate cut). | medium | patch | verification-gap (pre-verified) + blind. Fix: `testFetchThrowsRateLimitedOnA429` in `AvanzaAdapterTest` + `NordnetAdapterTest` (`queue([new Response(429)])`, assert `RateLimited`, also `assertInstanceOf(Transient::class, …)` to pin the subtype for `withOneRetry`). |
| P2 | No test exercises repeated 429s driving the runtime rate to the `base_rate / 8` floor, nor a "429 on every attempt exhausts the cap" path. `max(base/8, …)` is never the winning term in any test. | medium | patch | verification-gap (pre-verified) + blind. Fix: a `FetchRunnerTest` case — one source 429s across ≥4 jobs; assert same-source spacing plateaus at `1/(base_rate/8)` (16.0 s at defaults); plus a "429 every attempt → job reopened, `rate_limited`≥1, `transient`=1". |
| P3 | The "timebox squeezes retries" test (`testTheTimeboxCutsTheRetryLoopShortBeforeTheCap`) sets `backoff_base` so large that **zero** retries occur — the matrix row ("fewer than max attempts", i.e. ≥1 retry then cut off) is not covered. | low | patch | blind. Fix: tune it so exactly 1 retry is taken then the next backoff would overrun (assert `waits` count 1, `retried`=1, job `pending`). |
| P4 | The retry `warning` ("…job will be retried") fires on **every** failed attempt including the last, and the give-up branch (`$sawTransient`, `++transient`, `break`) logs nothing — an operator cannot tell "one blip, recovered" from "source down, 3 attempts burned, job reopened". Log field sets also differ across the three catch arms (`RateLimited` has `new_rate` not `error`; `Transient` has neither `error`; `NotFound` has `error` not `attempt`). | medium | patch | blind ×3. Fix: emit the per-attempt "retrying" `warning` only when a retry will actually happen (after the give-up check); add a distinct `warning` at the give-up point (`source`, `isin`, `attempts`, `run_date`); add `error => $e::class` to the transient/rate-limited warnings. Assert the give-up line fires once in a cap-exhaustion test. |
| P5 | After a 429 the reduced runtime rate makes the same-source **spacing** sleep as large as `1/(base_rate/8)` (16 s at defaults, more with a lower `settings.rate.<source>`), and that sleep — unlike the backoff sleeps — is **not** gated by `elapsed + wait >= timeboxSeconds`, so it can overrun the slice. | medium | patch | blind + edge-case-hunter + verification-gap. Fix: gate the spacing sleep too — skip it when `(microtime(true) - $start) + $spacing >= $timeboxSeconds` (the job-loop-top timebox check then reopens the remaining jobs). Add a test. |
| P6 | `Transient` on attempt 1 (retry taken) then `NotFound`/`SchemaMismatch` on a later attempt for the same source → the job goes to `failed`, not `pending` (`$sawTransient` is set only in the give-up branch, so a retried-then-hard-failed source is not "still transient"). | low | patch | edge-case-hunter. Spec-consistent — the job-state precedence keys on the source's **final** classification, and a genuine `NotFound` after a network blip should not be retried forever. Fix: a pinning test (Transient→NotFound same source → `failed`, `retried`=1, `transient`=0) with a comment that this is deliberate. |
| P7 | `retry.max_attempts` set to a fraction in `(0,1)` (e.g. `0.5`) passes `numericSetting()` (positive) then `intSetting()` truncates to `0` with no "unusable setting" warning; the loop still makes 1 attempt but takes no retries. | low | patch | edge-case-hunter. Fix (light): `$maxAttempts = max(1, $this->intSetting('retry.max_attempts'))` — clarifies intent; the `while (true)` loop is already safe. |
| P8 | Now-unused `use` imports may remain in `AvanzaAdapterTest` / `NordnetAdapterTest` after the two-attempt fetch tests were collapsed; the new tests should use the existing `queue()` / `assertQueueDrained()` idiom. | low | patch | blind (housekeeping). Fix: drop dead imports, align the new 429 tests with the existing fixture helpers. |
| — | First retry backoff (`backoff(1)` = 1.0 s) is shorter than the steady-state same-source spacing (2.0 s at `rate 0.5`), so a retry can re-hit a source faster than the throttle. | low | reject | The backoff grows past the throttle by the second retry; the source has just failed, so politeness matters less than convergence; flooring the schedule at `1/rate` would complicate the tested backoff values for negligible gain. Deliberate: in-loop retries follow the backoff schedule. |
| — | With degenerate settings (`backoff_base >= backoff_max`, or a very high `max_attempts`), `min(backoff_max, backoff(n) * 4)` and `min(backoff_max, backoff(n))` both collapse to `backoff_max`, so a 429 gets the same backoff as a plain transient. | low | reject | edge-case-hunter (confidence low). With the shipped defaults (base 1, max 20, 3 attempts) the ×4 always applies (4/8/16 s < 20). Clamping both to a shared max is correct; degenerate settings are the operator's problem. |
| — | `sprint-status.yaml` (`in-progress`) vs spec (`in-review`, boxes `[x]`) disagree. | — | reject | Expected mid-workflow state — the sprint file syncs to `review` at step-05. |

**Patches:** P1–P8 → re-engaged implementation subagent. No `bad_spec` / `intent_gap`;
`review_loop_iteration` stays 0.

## Design Notes

Retry loop shape inside the per-source block (pseudocode; `$deadlineElapsed = microtime(true)
- $start`):

```php
$attempt = 0;
while (true) {
    ++$attempt;
    try {
        $row = $this->adapters[$source]->fetch($instrument);
        ++$bySource[$source]['ok'];
        if ($this->ownerCounts->upsert($row, $job->runDate)) { ++$rowsWritten; }
        break;                                   // success
    } catch (RateLimited $e) {
        ++$bySource[$source]['rate_limited'];
        $rate[$source] = max($baseRate[$source] / 8, $rate[$source] / 2);
        $wait = min($backoffMax, $this->backoff($attempt) * $rlFactor);
        // fall through to the shared retry/break decision with $wait
    } catch (Transient $e) {
        $wait = min($backoffMax, $this->backoff($attempt));
    }
    // shared: log warning; decide retry vs give up
    if ($attempt >= $maxAttempts || $deadlineElapsed() + $wait >= $timeboxSeconds) {
        $sawTransient = true;
        ++$bySource[$source]['transient'];
        break;
    }
    ($this->sleep)($wait);
    ++$bySource[$source]['retried'];
}
```

`backoff($n) = $backoffBase * (2 ** ($n - 1))`. The same-source *spacing* sleep (`1 / $rate`)
stays where it is at the top of the source block and simply reads the possibly-reduced
`$rate[$source]`. Serial-by-construction is unchanged — this is all inside the existing
single-threaded `foreach`.

## Verification

**Commands:**
- `composer test -- --filter 'FetchRunnerTest|AvanzaAdapterTest|NordnetAdapterTest'` -- new + updated cases green.
- `composer test` -- full suite green, no regressions.
- `php -l src/Pipeline/FetchRunner.php` -- no syntax errors.

**Manual checks:**
- Grep `src/Adapter/{Avanza,Nordnet}Adapter.php` — `withOneRetry` appears only in `resolveId()`,
  not `fetch()`.
- `tests/EndToEndSmokeTest.php` still passes (its `Transient` scenario now sees a retry first).
