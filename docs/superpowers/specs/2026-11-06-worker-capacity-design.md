# Worker fleet: capacity, reliability and 4K

Status: Phases 0–1 approved for implementation · 2026-11-06. Phases 2–3 are revised by "Revision 1" below and get re-specified before they are planned.

## Revision 2: Phase 1 trimmed to what production showed (2026-11-06)

Phases 0–1 were built in full, then measured against production before shipping. The measurement covers 1,500 videos completed 10-25 → 11-06, plus 30 days of failures.

**Measured:**
- **Quality.** No video came out below its target because of the planner. A cut-short per-title probe keeps the top anchor, which scores ≥ target+1.
- **Bytes.** 331 of 1,373 1080p renditions (24%), almost all on epyc, came out ≈35% heavier than needed: 7–10% of all video bytes, about 68–94 GB per 1,500 videos.
- **Failures.** 5 videos were reaped in 30 days, all retried by hand, none lost:
  - 2 on the 11-03 Redis-flap night;
  - 1 was 17621;
  - 2 had no known cause.
- **Idle encoders.** ≈11% of every day (≈2.5 h of the fleet).
- **4K.** None of the 1,500 sources was 4K.

**Ships now:**
- Phase 0: the logs and the node sampler.
- 1a: the interruption-safe fan-out.
- 1d: the claim key the reaper waits on, and unique locks read with `isLocked()`; the heavy-batch wait is left out.
- From 1g: only the `failed()` claim guard.

**Parked** on the local branch `park/worker-capacity-phase-1-full`, built and tested:
- 1b, checkpointed preparation, with its retry policy and side jobs dispatched only when missing;
- 1c, ffmpeg as the OOM victim;
- 1e, resumable packaging;
- 1f, the big-source fetch;
- the heavy-batch reaper wait.

Each returns when 0a–0e's logs show the failure it prevents.

**Next:** the two Phase 2 items that the measurement says pay:
- probes that are not cut short (bytes);
- admission that prepares the next video while others encode, plus pinned pools (idle time).

## Revision 1: owner decisions after review (2026-11-06)

The owner reviewed this draft and set three directions. The goal is a fleet that scales: a new node of any shape joins without anyone tuning it, and nothing is adjusted per video.

**R1. The planner stays on every worker node.**
- No dedicated planning node.
- Every worker keeps draining `orchestration`, and whichever node is free takes the next prep, as today.

**R2. Every encode runs inside an encode slot, probes included.**
- The per-title samples, `QualityBitrateProbe` and `RenditionPreflight` become jobs on probe queues.
- Each slot listens to its probe queue before its chunk queue, so a new video's probes never wait behind another video's chunks.
- The prep itself becomes light (download, mirror, analysis, window plan, fan-out) and re-entrant:
  - it dispatches a probe round and ends;
  - the round's batch callback re-dispatches it;
  - it then decides, steps up if needed, plans and fans out.
- Why: today a prep's probes use RAM and CPU outside any slot. That is what forces per-node probe concurrency, the heavy-prep lock and per-node measurements before 4K. It also lets a loaded node cut per-title short.
- Short 4K encodes allocate ≈9 GB however few frames they write, so the preflight and the bitrate probe move too.

**R3. Slots are sized automatically at boot from the node's hardware. No per-node env lines.**
- **Total slots come from the CPU:** about one per 5 threads at lp4. Measured: ≈5 cores per encode; 6×lp4 ≈ 8×lp4 on the 9950x; 40×lp4 is the epyc optimum.
- **4K slots come from the RAM:** at most 2 per node, from measured weights:
  - light ≈2.5 GB average share;
  - 4K ≈9.3 GB at 3840×1606 and ≈12 GB extrapolated for full 2160p (RSS ≈ 0.5 + 1.43 GB/Mpx at lp4).
- **Expected results:**
  - epyc: ≈35–36 light + 2 heavy;
  - 9950x: 4 light + 2 heavy;
  - a 4-core box: 0 encode slots, so it only plans and packages.

  Both full nodes sit inside the mixes benched without OOM.
- 4K slots drain `[heavy, light]`, so the CPU stays busy when no 4K is queued.
- Env lines remain only as overrides. A panel view or override is later and optional.

**What this replaces:**
- 2b (epyc env lines) and 3a (opt-in `HEAVY_WORKER_PROCESSES`) → R3.
- 2c (probe concurrency and the deadline budget) → R2.
- 3c (one heavy prep at a time) and the deferred "heavy-prep placement" runs → R2.
- E6 and E7, G5's "measured env lines", and Calls 1, 2 and 10.

**What stays:**
- Phase 0 and Phase 1 as written. 1b's step checkpoints are the re-entrant skeleton R2 builds on; R2 later replaces only its probe step.
- 2a, 2d, 3b, 3d, Phase 4 and Phase 5.

**Order:**
1. Phases 0–1.
2. 2a and 2d.
3. R3 for light slots.
4. R2.
5. Heavy slots and 3b, before any template above 1080p.

The memory weights are confirmed against 0e's samples before 4K is enabled.

## Summary for the owner

**Problem 1: not enough capacity.**
- Last week 1,000–1,300 videos arrived per day; the fleet finishes 200–230.
- The backlog is 6,587 videos and grows by about 800 a day.
- Making today's machines work better gains +15–25%, but demand is 2.8–5.6× what the fleet delivers.
- Closing it is a trade only you can make (see "Owner decisions"): a faster encoder preset (about 2× output for files ~5–10% bigger, to be confirmed by a test), a GPU, a cheaper ladder, or less demand.

**Problem 2: the pipeline wastes about a quarter of the hardware and can lose videos.**
- **Lost videos.** A long preparation can be killed halfway and leave the video stuck; a week later it is deleted with its source (video 17621).
- **4K.** Every 4K encode runs out of memory: it needs 9–14 GB, and a slot assumes 3.
- **Idle encoders.** They sit idle 10–13% of every day, waiting for downloads and probes.
- **Oversized files.** The quality probe is cut short too often: 24% of 1080p files are ~30% bigger than needed (45% of the EPYC's main-template 1080p).
- **Unchecked 720p.** Nobody has checked the 720p rung, and 45% of animation 720p sits at CRF 48–52.

**The plan.** Six small phases. Each switches off with an env line or a redeploy.

| Phase | What | Why | Effort | Risk | How we'll know |
|---|---|---|---|---|---|
| 0 See it | Log timeouts, transfer speeds and prep steps; sample node memory | 17621 left no trace; every gate needs a baseline | 1–2 days | none | new log lines; a 48 h sample report |
| 1 Nothing stuck | Recoverable fan-out; checkpointed preps; ffmpeg as the OOM victim; a reaper that waits for queued work; resumable uploads; big-source fetch | No video may fail, and be pruned, for an infrastructure reason | 1–2 weeks | low | 0 empty batches, 0 prep/packaging timeouts; OOM kills hit only ffmpeg and are retried |
| 2 Feed the encoders | Full worker pools; EPYC at 40 × 4 threads; faster probes; prep the next video while others encode | +15–25% videos/day; 1080p files 5–7% smaller | 1–2 weeks | low–medium | idle < 0.5 h/day; completions 226 → 260–280/day; memory ≤ 90% |
| 3 4K, safely | Opt-in 4K slots; a 4K queue; one 4K prep at a time | 4K must not OOM, loop or get stuck once enabled | 1–2 weeks | medium | a real 32 GB 4K film passes with 0 OOM kills |
| 4 Quality you can see | Pin ffmpeg; score a sample of every encode | Quality is assumed, not measured | 2–3 weeks | low | ≥95% of renditions scored; predicted vs measured within ±0.5 VMAF |
| 5 Growth | Admit by live capacity, not node count | Needed before a third node or a GPU | days | low | a new node raises output with no setting changed |

**Needed from you first:**
- Is the surge a one-off import?
- When can each node be drained for 1–4 h?
- What are the iDrive e2 link speeds?

## Intent

There are two problems, and only the second can be solved in code.

- **Capacity.** Demand is 720–930 content-h/day against 150–170 delivered. Utilisation work is worth +15–25%; the rest is the owner's trade.
- **Waste and loss.** Evidence comes from a read-only review of 1,500 recent videos and their prep logs, and from benchmarks of 2026-11-06 (drained nodes, image 2.0.26, SVT-AV1 4.2 at preset 6, 10-bit):
  - **Lost videos.** One 1800 s `PrepareVideoJob` covers download, probes and fan-out.
    - Video 17621, a 109-min 1080p film, was claimed at ≈17:55:15 and logged "Windows planned" at 18:24:17. The timeout fired ≈18:25:15, inside `Bus::batch()->dispatch()`.
    - `PendingBatch::dispatch()` commits the `job_batches` row with `total_jobs=0` before `Batch::add()` pushes, and the SIGALRM handler kills without `report()`.
    - At 18:26:11 the redelivery trusted that row (`PrepareVideoJob.php:128`) and skipped. The reaper failed the video 41 min later, and `videos:prune` deletes it with its source after 7 days.
    - Packaging is a second 1800 s envelope, whose retry re-uploads everything.
  - **Big sources.** Sizes run p50 1.54 GB, p90 3.4, p99 7.71, max 15.04; 4 of 1,500 exceed 10 GB. 17621 fetched ≈7.3 GB in ≈19.5 min (≈6.2 MB/s). At that rate a source above ≈10.9 GB cannot fetch in one attempt and fails after two timeouts: ≈0.3% of videos, 2–3 a day.
  - **Memory.** Every chunk gets a flat 3 GB (`Cpu.php`). Measured peak RSS at lp4:
    - 720p: 1.6–2.0 GB; 1080p: 3.2–3.7 GB; 1080p from a 4K HEVC PQ source: 2.8 GB;
    - 4K (3840×1606): 9.3 GB, or 14.1 GB at lp6.

    An OOM-killed ffmpeg counts toward `maxExceptions=2`, because `causedTermination()` matches only signals 15 and 2.
  - **Starvation.** Admission counts nodes+1 videos from claim to packaged, yet prep (p50 ≈6 min on the 9950x, ≈11 on epyc) lasts as long as encoding.
    - On 993 videos (Nov 1–5), 10.4–12.6% of each day had no chunk work.
    - Horizon with balance `none` targets min(max, max(1, ready jobs)), moving ±1 process every 3 s (`AutoScaler.php:110`). Pools therefore shrink between fan-outs, and one busy process plus one waiting job never starts a second.
  - **Bytes.** Per-title stops on a 420 s clock: on 25% of epyc runs vs 2.4% on the 9950x. 331 of 1,372 1080p renditions (24%) stop on a top anchor that still scores ≥ target+1, leaving them ≈30–35% heavier than needed.
  - **Quality.** Nothing measures what ships, and ffmpeg floats on BtbN `latest` (`Dockerfile:23`).

## Goals

- **G1:** An interruption (fetch, prep, fan-out or packaging) costs a retry, not the video: a prep fails only on its third counted failure, dead attempts included; every timeout and OOM kill is logged.
- **G2:** No OOM failures, 4K and 32 GB sources included. An OOM costs at most one chunk retry, never Horizon, the host or RustFS.
- **G3:** Under 0.5 h/day without chunk work; occupancy ≥90%; completions +15–25%.
- **G4:** Under 5% of renditions truncated.
- **G5:** A new node is safe on defaults and reaches its optimum through measured env lines.
- **G6:** Quality is measured before anything trades it.

## Non-goals

- Closing the gap by scheduling.
- Network topology: CGNAT, Redis/MariaDB placement, chunk-store distribution, off-LAN nodes (later).
- New VMAF targets.
- Kubernetes, per-task cgroups, a RAM ledger, panel UI.
- 4K throughput (see "Deferred").

## Anti-goals (failure even if it "works")

- A video FAILED, and later pruned, for an infrastructure reason.
- Silent quality loss.
- RAM safety bought with idle cores.
- Admission that reads Horizon's autoscaled `processes`.
- An unstated deploy order.
- A hand-back that the dispatcher re-claims every tick.

## Constraints

- **House rules (`CLAUDE.md`):**
  - Logic lives in services.
  - Jobs take primitives and state their retry policy with a reason.
  - No new dependencies, and no Dockerfile/CI/compose edits without the owner's OK.
  - Pint and tests pass; everything is in English.
- **CI** runs on SQLite with no Redis. Tests use `Queue/Bus/Process::fake` and `Carbon::setTestNow`, fake jobs that carry a payload (`FakeJob::maxTries()` is null), and a fake `FleetCapacity` bound in `TestCase`.
- **Deploys:**
  - Only the API host (H) migrates; workers (W) never do.
  - This design needs no migration.
  - New job properties sit outside the constructor, with defaults.
  - State that H and W share lives in the Redis cache.
  - `then()` closures stay self-contained.
- **Settings:** per-node values go in the panel's env field, `config/nuke.php` and `.env.example`. Fleet-wide values are code constants.
- **Invariants:**
  - The prep timeout (1800) stays below `REDIS_QUEUE_RETRY_AFTER` (1850).
  - A node runs at most 3 preps (its pinned `orchestration` pool), with probe concurrency sized so 3 fit.

## Decisions

### Decided with the owner

- Topology is later.
- RustFS gets no RAM reserve.
- `nice` is dropped: it cut probe time by only 10%.
- 4K must not OOM; enabling a template above 1080p is the owner's call.
- The targets: all CPU and RAM in use, nothing stuck, mixed nodes, good quality.

### Decided by evidence

| # | Decision | Evidence | Rejected |
|---|---|---|---|
| E1 | Capacity is the owner's decision | All scheduling fixes together: +15–25%; demand is 2.8× output (14-day mean, 467 content-h/day) to 5.6× (last week, up to 930) | Treating hardware use as the cure |
| E2 | Delete empty batch rows (no age threshold) and fan out only the missing queues. Complete only when no row is unfinished and every expected queue has a finished, uncancelled batch with jobs | `add()` is one DB transaction around one Redis MULTI (`Batch.php:185-193`, `RedisQueue.php:371`). Jobs from the EXEC-before-COMMIT sliver skip once the row is gone (`belongsToFinishedRun()`). Attempts never overlap | A gate that ignores empty siblings, which packages before the missing queue exists |
| E3 | Admit while preparing < nodes+1 and in flight < factor×(nodes+1) | Peak concurrent preps are unchanged; factor 1 = today | Lookahead in jobs × minutes |
| E4 | Pin the pools | `AutoScaler` source | — |
| E5 | Preps and side jobs share the 3-process `orchestration` pool | A surviving node runs all nodes+1 preps, as today | A per-node prep bound: with node-counted admission it reaps preps during outages |
| E6 | Probe concurrency ships with the deadline budget | Loaded c8: epyc 330–365 → 120–142 s, 9950x 292 → 121 s, no chunk loss. A longer budget alone: −12% | A clock-free search (simulated only) |
| E7 | Opt-in static heavy slots | No benched mix OOMed: epyc 36+2×4K at 101/123 GB, 9950x 6+2×4K at 38/60. Share per process is 2.0 GB (epyc, 40 processes) vs 3.0 GB (9950x, 8) | A RAM ledger; one sizing formula |
| E8 | Releases are free; failures and crashes are counted by the framework | `#[CountCrashesAsExceptions]` in Laravel 13.35 | A hand-made crash counter |
| E9 | No 4K cap beyond big sources in flight | Uploads record `file_size` (`OnVideoUploadedService.php:101`) but not resolution | A job-side cap: two readers, hand-back loops |
| E10 | A 4K rung costs only on 4K sources | `filterVariants()` never upscales; 0 of the last 1,500 sources were above 1080p | Treating it as a fleet-wide cost |

### Calls made in this design that the owner should check

1. **Roles.** No per-node prep roles: roles are env lines, not panel toggles.
2. **4K capacity.** Opt-in per node, from a measurement.
3. **The reaper waits longer.** Up to 6 h for a pending prep, 3 h for a held sidecar or packaging lock, 48 h for heavy batches. Lost jobs surface later than today's 41 min.
4. **Prep retries.** 12 h of retries with 3 counted failures (crashes included), where today a prep gets 2 exceptions.
5. **Packaging.** It resumes an interrupted upload up to 3 times.
6. **Big sources.** At most 2 videos above the fetch threshold are in flight.
7. **Per-title.** It gets the attempt's real time. CRFs move up where probes were cut short (1080p bytes −5–7%), with the same VMAF targets.
8. **Pinned pools.** About 48 idle Horizon workers hold Redis connections over Tailscale.
9. **Factor 2.** HIGH chunks can wait behind ~2 more videos.
10. **EPYC.** 40 × lp4, with probe concurrency from measured env lines.
11. **Big-source delays.** A deploy that kills a running fetch delays that video by the `redis-long` `retry_after` (up to ≈4 h).

## Design

**Checks:**
- **Occupancy:** `SELECT date, SUM(value)/(86400*<slots>) FROM usage_daily WHERE metric='encoding_cpu' GROUP BY date`.
- **No-batch hours:**
  ```sql
  SELECT SUM(GREATEST(created_at-prev_end,0))/3600 FROM (SELECT created_at,
   MAX(COALESCE(finished_at,UNIX_TIMESTAMP())) OVER (ORDER BY created_at
   ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) prev_end FROM job_batches
   WHERE name LIKE 'encode video %' AND created_at>=UNIX_TIMESTAMP()-86400) t;
  ```
- **Truncated:** the top anchor in `meta.per_title.anchors` scores ≥ target+1, with fewer than 4 anchors and the top CRF below the maximum. The log text "out of probe time" stays verbatim.
- **Memory:** anon and `oom_kill` from the 0e sampler.

### Phase 0: See it (1–2 days; W; either order)

- **0a.** A `JobTimedOut` listener logs `Queue job timed out` {class, queue, uuid, attempts}. It sits in a try/catch, since it runs inside the SIGALRM handler.
- **0b.** `Source fetch started {bytes}` and `Source fetched {from, bytes, s, attempt}` bracket `ensureLocalSource()`. `exists()` becomes `size()`, the same HEAD request.
- **0c.** `Preparation planned` gains `claim_wait_s` (now − payload `pushedAt`), `probe_s`, `plan_s`, `fanout_s`, `jobs` and `attempt`.
- **0d.** `syncToPrimary()` logs `Package synced {bytes, s, concurrent}`.
- **0e. Node sampler** (builder task; no new process or dependency).
  - Any worker job that beats the heartbeat also reads `memory.stat` anon, `memory.events` `oom_kill`, `/proc/loadavg` and, node-wide, `/proc/meminfo` `MemAvailable` (the cgroup's anon misses RustFS and anything else on the host), at most every 30 s per node (gated by a Redis `SET NX EX 30`).
  - It stores them in a per-node, per-minute Redis hash that keeps maxima, with a 7-day TTL.
  - `videos:dispatch` adds `LLEN` and the Horizon process counts.
  - `php artisan fleet:samples --hours=48` reports max and p95 anon (% of RAM), `oom_kill` deltas, load, LLEN, and idle minutes per node.

### Phase 1: Nothing stuck, OOM contained (1–2 weeks)

**1a. Interruption-safe fan-out** (W, either order)
- The guard skips only when streams exist and every queue they route to has a batch with `total_jobs>0`.
- `fanOut()` deletes empty rows with a warning, then fans out only the missing queues. It touches only their streams (`discardStaleChunks()`, progress, output status) and leaves `chunk_count`.
- The `then()` gate captures the expected queues and completes only when no row is unfinished and each queue has a finished batch with jobs and `cancelled_at IS NULL`.
- Tests: a 17621 replay; a partial two-queue fan-out; the gate waiting.
- Verify this is always 0: `SELECT COUNT(*) FROM job_batches WHERE name LIKE 'encode video %' AND total_jobs=0 AND finished_at IS NULL AND created_at<UNIX_TIMESTAMP()-600`.

**1b. Checkpointed preparation** (W, either order)
- **Deadline:** attempt start + `$timeout` − 60 s.
- **Steps:** stream creation; per rendition, per-title, QBP and preflight; the window plan; the fan-out.
- **Rule:** the first step of an attempt always runs. Each later step runs only if its estimate fits; otherwise the job calls `release(10)` and logs `Preparation released: <step> would not fit`. The next attempt resumes, because the mirror, the streams and the probe results persist.
- **Estimates:** an earlier rendition's `meta.encode_rate`, scaled by pixels and concurrency. Before that, 300 s (≤1080p) or 900 s (4K). Fan-out: 60 s + 0.15 s per job (822 jobs took ≈58 s).
- **Fallback:** when the job cannot release (an old payload without `retryUntil`, or when less than one attempt plus the release delay remains before it), it runs as today, with per-title capped at 420 s. A release that landed past `retryUntil` would fail at its pop.
- **Side jobs** (thumbnail, storyboard, sidecar) are dispatched only when their output is missing.

**Retry policy.** `#[CountCrashesAsExceptions]` (`Worker.php:352, 647, 753`; `Queue.php:184`) works like this:
- Each attempt sets a `job-processing:{uuid}` marker and forgets it in `finally`.
- A leftover marker (an attempt that never finished) counts toward `maxExceptions`.
- Timeouts count through the SIGALRM handler.
- A `release()` finishes cleanly and counts for nothing.

| Job | Attempts | `maxExceptions` | What counts | Why |
|---|---|---|---|---|
| PrepareVideoJob | `retryUntil()` 12 h (`$tries` ignored) | 3, with the attribute | exceptions, timeouts, attempts that died; releases never count | Releases are planned checkpoints; 3 = two real failures + one crash |
| ProcessChunkJob | `$tries=5` | 2, no attribute | exceptions, timeouts; OOM releases and crash redeliveries spend only `$tries` | A lost node kills ~40 chunks at once; counting crashes would fail videos over one outage |
| PackageVideoJob | `$tries=5` | 2, no attribute | exceptions, timeouts; resume releases spend `$tries` (≤3) | Idempotent; `uniqueFor` 3 h covers 5 attempts of 1,800 s |

Either deploy order is safe. Every field is written into the payload by the pushing host's class, and the job releases only if its payload carries `retryUntil`. Old-H payloads (`maxTries=5`) therefore run as today, and old workers honour the new fields because they are core Laravel.

Tests: a fake clock and `encode_rate`, a release vs the fallback, side jobs skipped. Verify: PrepareVideoJob timeouts are 0/week.

**1c. ffmpeg as the OOM victim** (W, either order)
- Chunks run `echo 1000 >/proc/self/oom_score_adj; exec ffmpeg …`, probes 500. No privilege is needed to raise one's own score.
- In `ProcessChunkJob::encode()`, an exit 137 that our timeout didn't cause logs `Chunk ffmpeg killed by SIGKILL (likely OOM)`, calls `release(30)` and returns at once, because an exception after a release still counts.
- On the last attempt it throws `Chunk OOM-killed N times` instead.
- A SIGKILLed preflight or QBP releases the prep rather than throwing. A probe SIGKILLed twice in the same step throws instead, so a deterministic probe OOM cannot release for 12 h.
- Kill switch: `CHUNK_OOM_SCORE_ADJ=0`.
- Verify: kernel kills name only ffmpeg.

**1d. The reaper waits for work the queue still owes** (W → H; rollback H → W)
- **The key `prep-pending:{video}`.** Its value is a claim token.
  - The dispatcher writes it at claim, with a 6 h TTL.
  - The job carries the token in `public ?string $claim = null`, declared outside the constructor, and refreshes the key on every heartbeat.
  - The job deletes the key only on a clean exit (fanned out or handed back) or in `failed()`. A crashed or timed-out attempt keeps it, so the redelivery wait (1,850 s plus the queue) stays covered.
  - A node hop (`handedToCapableNode()` builds a fresh job) copies `$claim` into the new job and keeps the key.
- **`isWaitingOnTheQueue()`** is also true while:
  - that key exists;
  - the sidecar or packaging job holds its unique lock, read with `isLocked()`, never acquired;
  - a heavy-queue batch is pending, for up to 48 h.

**1e. Resumable packaging** (W, either order)
- The `m4s` and download-zone passes use `s5cmd sync --size-only` (v2.3.0). Segment keys never change, so a retry skips what already landed.
- The sync's timeout is the time left − 60 s. On expiry, `release(60)` and return, while attempts ≤ `$tries` − 2.
- If 0d shows syncs over 900 s, packaging moves to 1f's connection. In that case raise `uniqueFor` and the reaper's lock wait above `$tries` × the new timeout, since 3 h only covers 1,800 s attempts.

**1f. Big-source fetch** (H, routing off → W → routing on; rollback: routing off → drain `fetch` → W → H)
- **Routing.** The dispatcher sends sources whose original `file_size` exceeds `BIG_SOURCE_FETCH_GB` to a `FetchSourceJob`. The threshold is what 0b's p10 rate moves in 20 min; at 17621's rate, ≈7 GB, about 1% of sources. Build this unless 0b shows a rate far above that. A `file_size` of 0 is unknown (the webhook stores 0 when the event carries no size, `VideoWebhookController.php:45`): the dispatcher HEADs the object before routing.
- **The job** runs on a `redis-long` connection whose `retry_after` is above a size-scaled timeout (bytes ÷ 2 MB/s, at most 4 h), with one pinned `fetch` process per worker. It mirrors the source to `chunks`, keeps the prep key fresh, then dispatches the prep, which copies the source over the LAN.
- **Cap.** The dispatcher alone admits at most `BIG_SOURCES_IN_FLIGHT` (default 2) such videos. That bounds the home link and the mirror disk, and serves as a 4K stopgap.
- **Retries.** `FetchSourceJob` states its own retry policy like every other job; its `failed()` hands the video back to PENDING. A big source's prep key outlives the `redis-long` `retry_after` plus one fetch, so the reaper never fails a video whose fetch is being redelivered.

**1g. (Optional) Requeue lost preps** (H, flag `REAP_REQUEUE`, after 1d)
- A stale video with no batch and no key goes back to PENDING, at most twice, instead of FAILED.
- A late job whose token no longer matches the key exits, so two preps never race.
- `failed()` acts only while the key holds the job's token, or the payload has none. Otherwise a late job that is already past `retryUntil` or `maxExceptions` at its pop would fail a video that was requeued meanwhile (`markAsFailed()` accepts PENDING).
- Ship it if more than ~1/week is reaped (two were in one Redis-flap hour on 11-03).

### Phase 2: Feed the encoders (1–2 weeks; this order; ≥2 days between steps)

**2a. Pin the pools** (W, either order)
- Set `minProcesses = maxProcesses` on video, gpu, orchestration (3) and packaging (2).
- This removes ≈3–4% lost to decay and ramp-up, and ends serialised preps.
- Cost: ~48 idle Redis pollers, as during any fan-out.

**2b. epyc at 40 × lp4** (env `VIDEO_WORKER_PROCESSES=40`, `VIDEO_FFMPEG_THREADS=4`)
- Bench: 531–538 vs 481–505 Mpix/s, at 79–84 vs 100–103 GB, so RAM falls.
- First, M5 runs one 4K HDR source tone-mapped to 1080p, filling all 40 slots (one video's chunks run back to back). SDR decode of 4K measured only 2.8 GB; tone-map RSS is unmeasured.
- If that run exceeds 90% anon, a code rule sends tone-mapped renditions of sources above 1080p to the heavy queue (3b), which then needs heavy slots on that node.
- Gate: anon ≤90% and `oom_kill` 0 over 48 h. Rollback: delete the lines.

**2c. Probes that fit** (W, either order)
- `PER_TITLE_ANCHOR_BUDGET=deadline` (1b) replaces the 420 s budget; `MAX_EXTRA_ANCHORS=2` stays.
- `Cpu::probeConcurrency()` defaults from the RAM the chunk pool leaves:
  - c2 where `Cpu.php` is RAM-bound or the process count comes from env (epyc);
  - c4 where it is CPU-bound with ≥48 GB (the 9950x);
  - higher only via `PROBE_CONCURRENCY`, set from an M5 run of 3 concurrent preps on that node.
- Probes of renditions above 2,304,000 px run at c4, the ≈16 GB this plan budgets for a 4K prep, whatever `PROBE_CONCURRENCY` says.
- A node at the c2 default keeps `PER_TITLE_ANCHOR_BUDGET=420` until its `PROBE_CONCURRENCY` line is set or 2d ships: c2 plus the deadline budget alone is the −12% case of E6.
- Each extra 1080p sample costs ≈1.0 GB (epyc) or ≈1.8 GB (9950x); lp6 samples ≈1.4× lp4.
- Expected: truncation 24% → <5% (the deadline alone); 1080p bytes −5–7%; epyc prep p50 10.8 → ≈6 min at c8.
- Gate: anon ≤90% and `oom_kill` 0.
- Rollback: `PROBE_CONCURRENCY=2`, `PER_TITLE_ANCHOR_BUDGET=420` (today).

**2d. Split admission** (H, after 2a–2c)
- "Preparing" = DOWNLOADING, or RUNNING with no batch where `total_jobs>0`.
- Admit while preparing < nodes+1 and in flight (UPLOADING included) < `DISPATCH_IN_FLIGHT_FACTOR`×(nodes+1).
- `fleetIsFull()` also stops when preparing is full, so no tick walks the 6.6k backlog.
- Ship at factor 1, then 2; extend `DispatchConcurrencyTest`.
- Verify:
  - no-batch hours ≈2.5–3 → <0.5/day;
  - occupancy ≥90%;
  - completions 226 → 260–280/day;
  - anon ≤90% and `oom_kill` 0;
  - reaper failures flat.
- Rollback: factor 1.

Phase 2 total: **+15–25%** (≈190–210 content-h/day).

### Phase 3: 4K, safe when the owner enables it (inert until a template above 1080p exists)

**3a. Opt-in heavy slots** (W, either order)
- `HEAVY_WORKER_PROCESSES` defaults to 0, so light sizing stays as today.
- It is set together with `VIDEO_WORKER_PROCESSES` (which counts light processes only), from an M5/M6 run of the exact mix: busy heavy chunks, one 4K prep and two light preps.
- By the bench, epyc 36+2 fits only with every probe at c2, and the 9950x 6+2 reaches ≈57 GB (94%) with two c4 preps, so it needs c2 or 6+1.
- `PROBE_CONCURRENCY` is re-set in the same change, from the same run.
- A pinned `heavy-worker` drains `[heavy, light]`.

**3b. Heavy queue and drainer check** (H → W)
- `Stream::encodeQueue()` sends CPU renditions above 2,304,000 pixels (a code constant) to `video-processing-heavy`. Vertical 1080×1920 stays light.
- `FleetCapacity` answers "does a running supervisor drain this queue?" from Horizon's registry. Draining supervisors leave it at once (`Supervisor::terminate()` → `forget()`). An unreadable registry answers yes.
- The dispatcher skips PENDING videos whose streams route to an undrained queue.
- The job checks before every fan-out. On failure it hands the video back to PENDING and increments `prep-handbacks:{video}` (1 h). After 3 hand-backs, the dispatcher skips the video for 30 min.

**3c. One heavy prep at a time** (W, either order)
- Each heavy rendition's per-title, QBP and preflight run under `Cache::lock('heavy-prep', estimate+120)`.
- The job waits in-process with `->block(min(timeLeft − estimate, 600))`. Waiters already hold prep slots, and 2d caps them at two.
- The holder refreshes the lock on the heartbeat and releases it in `finally`.
- The job releases itself only on `LockTimeoutException`, so a 32 GB source is never re-copied just to wait.
- A skipped per-title logs a warning and keeps the template CRF.
- Tests of the heavy lock use `Sleep::fake(syncWithCarbon: true)`: `Lock::block()` times out by `Carbon::now()`, so a frozen clock would spin forever.

**3d. Acceptance.** One real 32 GB 4K film runs end to end over the production uplink, under light load. Pass criteria:
- anon ≤90% and `oom_kill` 0;
- heavy chunks only on the heavy queue;
- `Package synced` within budget.

Only then is a 2160p template enabled.

Rollback: disable the template → drain the heavy queue → W → H.

### Phase 4: Quality you can see (2–3 weeks)

- **4a.** Pin `FFMPEG_BUILD_TAG`/`ID` in release builds, with the owner's OK. Verify: identical `ffmpeg -version` across images.
- **4b. Audit** (W, flag `QUALITY_AUDIT`).
  - **What:** after `reportDone()`, if ≥120 s of the chunk timeout remain, 6 spread chunks per rendition get native VMAF (`n_subsample`), CAMBI, and display VMAF on lower rungs.
  - **Flow:** `{chunksDir}/{stream}.audit/` → `streams.meta.quality_audit` → `videos:quality-report`.
  - **Cost:** ≈1.5% CPU; never fails a chunk.
  - **Verify:** ≥95% coverage; median measured−predicted within ±0.5; p10 ≥ target−2; CAMBI ≤5.
- **4c (later).** A prior-centred top-rung search, built only once 4b validates the current one.

### Phase 5: Growth (before a third node or any GPU; H; env falls back to 2d)

Admission by live capacity through `FleetCapacity`:
- preparing < Σ `maxProcesses` of running `orchestration` supervisors;
- queued work (pending jobs × `encode_rate` × window) < N minutes of live capacity.

Verify: occupancy ≥90% after adding a node.

### Deploy and rollback order

| Steps | Deploy | Rollback |
|---|---|---|
| 0a–0e, 1a–1c, 1e, 2a, 2c, 3a, 3c, 4b | W, either order | either |
| 1d | W → H | H → W |
| 1f | H (routing off) → W → on | off → drain `fetch` → W → H |
| 1g, 2d, 5 | H after prerequisites | env |
| 3b | H → W | template off → drain heavy → W → H |

### Deferred until K4 and M6 (known limitations)

- **Heavy-aware admission.** A 4K burst can hold the in-flight slots for hours (≈2.6 h per 2-hour film); only 1f's cap bounds it.
- **Heavy-prep placement.** A heavy prep can land on any node that drains `orchestration`, beside light preps and a full light pool. Before a 2160p template is enabled, M5 runs one heavy prep beside two light preps and a full light pool on every such node, gated on anon ≤90% and `oom_kill` 0. By the bench the 9950x needs `PROBE_CONCURRENCY=2` for it (≈55 GB, 92% of 60, at c4).
- **Sizing for new node shapes.** Heavy slots stay opt-in until then.
- **Pausing 4K.** Disable the template, not only `HEAVY_WORKER_PROCESSES`, or blind 4K preps park along with their mirrors.

## Owner decisions (the capacity gap)

| | Gain | Cost | Gate |
|---|---|---|---|
| **K1 Fast tier:** SVT preset 8 at 1080p, 9–10 at 720p, same targets, for the backlog/LOW | ×1.8–3 per content-hour (the repo's 0.45 is a sizing guess; decode, I/O and VMAF don't shrink); 430–540 content-h/day at ~85% of arrivals | +5–10% bytes (older-SVT public data), ≈+58 MB per 1080p view-hour | M3 |
| **K2 GPU:** one Arc B580 (~$249) | +230–900 content-h/day (28.9–31.4× aggregate; resolution unknown) | Bytes unknown; needs Phase 5 and 3–5 preps per GPU | M7 |
| **K3 720p** | Coupled to the top CRF: CRF ≥48 falls 12 → 5% (live), 45 → 23% (animation). Dropped: ×1.6 capacity (36–40% of chunk time) | Coupled: +15–23% low-rung bytes. Dropped: no ABR fallback | M4 |
| **K4 2160p rung** | Serves 4K masters | 3–3.8× ladder pixels per 4K title; the deferred limits | Phase 3 + M6 |
| **K5 Policy** | Pinned ffmpeg; auto-retry with `video.error` only when terminal; `*-high` queues | An infra edit; an API contract change | — |

Link ceiling: 1,000 videos/day needs ≈172 Mbps down and ≈67 up, sustained (mean source 1.86 GB, output 0.73 GB).

## Measurements still needed

| | What | Cost | Unlocks |
|---|---|---|---|
| M1 | 48 h of the 0e sampler; occupancy; no-batch hours | free | every gate |
| M2 | `Source fetched` and `Package synced` by size | 1 week of logs | 1f threshold, 1e gate |
| M3 | 20–30 titles × presets 6/7/8 (720p 6/8/10) at matched VMAF: bytes, fps, CAMBI, eye check | ~4 h on a drained node | K1 |
| M4 | 40 shipped titles: native/display VMAF and CAMBI; the owner views the worst 10 at 720p; edge bytes per rung | ~2 CPU-h + 30 min | K3 |
| M5 | Drained epyc and 9950x: 40×lp4 + 3 preps at c8; a tone-mapped 4K HDR source filling the pool; heavy + one 4K prep + two light preps; true 2160p | ~2 h per node | 2b, 2c, 3a |
| M6 | A 32 GB 4K film end to end (3d) | ~3 h | K4 |
| M7 | M3's titles on av1_qsv vs SVT presets 6 and 8 | one GPU-day | K2 |

## Open questions only the owner can answer (ranked)

1. Is the surge a finite catalogue import (93% of titles are SxxEyy)? How much is left? This decides whether K1/K2 are mandatory.
2. When can each node be drained for M5 (≈2 h each) and M3 (≈4 h)? This sets when Phase 2 finishes.
3. What are the link speeds to iDrive e2, down and up?
4. What does a delivered TB cost against a CPU-hour or a new node?
5. Is 720p watched, and does it look acceptable at CRF 48–52 on a TV?
6. Will 4K sources come? How many, and when?
7. Will you test a B580?
8. What is the next node, and when? This times Phase 5.
9. May release builds pin ffmpeg (a Dockerfile/CI edit)?
10. Can API consumers accept `video.error` only after automatic retries?

## Left to the builder

- Constants: delays, seeds and reserves.
- Key, flag and log names.
- Where the guard and the estimates live.
- Audit aggregation.
- The details of a partial re-fan-out within 1a's rules.
