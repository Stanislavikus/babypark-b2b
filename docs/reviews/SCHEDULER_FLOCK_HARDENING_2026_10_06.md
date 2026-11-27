# Production Scheduler Overlap Hardening — 2026-10-06

> **STATUS: [Resolved — implementation campaign]**
>
> Base: `origin/develop @ ad1d848ddbe34e8bbf15e44e44758837cf5df1e5`.
>
> Repository merge and production host activation remain separate authorization states.

## Goal

Prevent a degraded database/host from accumulating overlapping
`php artisan schedule:run` bootstrap processes while preserving Laravel's existing
per-task `withoutOverlapping()` protection.

## Current-state evidence

On 2026-10-06 the BabyPark pilot became temporarily unreachable while the Droplet
itself remained active and accepted TCP connections. Kernel evidence showed repeated
global OOM kills. The OOM snapshot contained many concurrent PHP processes, and the
root crontab was:

```cron
* * * * * cd /var/www/babypark-b2b && php artisan schedule:run >> /dev/null 2>&1
```

The application schedule already runs
`reservations:expire->everyMinute()->withoutOverlapping()`. That protects the
scheduled command after Laravel boots; it does not bound the number of concurrent
`schedule:run` bootstrap processes when the host/DB is severely delayed.

## Research-first gate

| Area | util-linux `flock` | Laravel task lock only | systemd timer |
| --- | --- | --- | --- |
| Functionality | PASS | FAIL for bootstrap overlap | PASS |
| License / commercial use | PASS | PASS | PASS |
| Activity / maintenance | PASS | PASS | PASS |
| Upgrade risk | PASS | PASS | PARTIAL |
| Integration | PASS | PASS but insufficient | PARTIAL |
| Failure independence | PASS — lock acquisition is OS/filesystem-level | PARTIAL — Laravel must boot and cache/DB may be degraded | PASS |

Pilot verification:
- `/usr/bin/flock` exists;
- provided by Ubuntu `util-linux 2.40.2`;
- util-linux is free software with no commercial-use/free-tier restriction;
- `flock -n` fails immediately when the exclusive lock is already held.

**Decision: ADAPT the OS-provided `flock`; do not write application lock code.**
A systemd-timer conversion is unnecessary operational churn for this incident.

## Canonical production contract

The repository-owned cron template is
`ops/cron/babypark-scheduler.cron`:

```cron
* * * * * cd /var/www/babypark-b2b && /usr/bin/flock -n /run/lock/babypark-scheduler.lock /usr/bin/php artisan schedule:run --no-interaction >> /dev/null 2>&1
```

Invariants:
- at most one host-level `schedule:run` process may hold the scheduler lock;
- a later minute tick skips immediately when the previous tick is still running;
- Laravel's existing `withoutOverlapping()` remains in place as task-level protection;
- the lock file is outside the Git checkout, so deploy/checkout cannot replace its inode;
- no scheduler state is stored in Product/domain tables;
- this slice does not change reservation semantics.

## Activation and verification

Production activation is a host configuration change and is **not** authorized by
repository merge alone.

When separately authorized:
1. save the current root crontab;
2. replace only the existing BabyPark `schedule:run` line with the canonical line;
3. verify exactly one BabyPark scheduler line exists;
4. acquire `/run/lock/babypark-scheduler.lock` in a harmless probe and confirm a
   second non-blocking `flock` attempt fails;
5. after the next normal tick, confirm no scheduler-process accumulation and no new OOM events.

Rollback:
- restore the previous crontab line from the saved crontab;
- no application/schema/data rollback is required.
