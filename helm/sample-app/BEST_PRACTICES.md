# Kubernetes Best Practices — sample-app

## Labels

All resources use the standard `app.kubernetes.io/*` label set:

| Label | Value |
|---|---|
| `app.kubernetes.io/name` | `sample-app` |
| `app.kubernetes.io/instance` | release name |
| `app.kubernetes.io/version` | app version from `Chart.yaml` |
| `app.kubernetes.io/managed-by` | `Helm` |

## Replicas & anti-affinity

`replicaCount` defaults to **2** to ensure availability.  
A `podAntiAffinity` rule (`preferredDuringSchedulingIgnoredDuringExecution`) spreads pods across worker nodes using `kubernetes.io/hostname` as topology key.

## Resource limits & requests

Every container declares `resources.requests` and `resources.limits`:

| | CPU | Memory |
|---|---|---|
| requests | 100m | 128Mi |
| limits | 500m | 512Mi |

Adjust in `values.yaml` to match the target node capacity.

## Deployment strategy (Argo Rollouts canary)

The chart ships an Argo Rollouts `Rollout` instead of a plain `Deployment`. A new
release is rolled out as a **canary**: one pod (`setWeight: 50` of `replicaCount: 2`)
runs the new version while a background `AnalysisRun` checks the canary
pod-readiness ratio (`templates/analysistemplate.yaml`, no Prometheus required).

- If all canary pods become Ready → the canary is **promoted** to 100% → Rollout `Healthy`.
- If the canary never becomes Ready (broken image / failing `/up`) → the
  `AnalysisRun` fails → the Rollout **aborts and rolls back** to the previous
  stable ReplicaSet automatically.

Tune `canary.steps`, `canary.analysis`, and `progressDeadlineSeconds` in
`values.yaml`. `progressDeadlineSeconds` must stay greater than the sum of the
canary pauses, otherwise a slow-but-healthy canary is wrongly marked Degraded.

The Argo Rollouts controller (CRDs) must be installed first; it is deployed by
`argocd/apps/argo-rollouts/application.yaml` in sync-wave `-1`.

## Probes

- **readinessProbe** — HTTP GET `/up` on port 80, starts after 10 s, checked every 5 s.
  Laravel's native health endpoint, extended (in `AppServiceProvider`) to verify
  the database is reachable, so readiness reflects **real application state**, not
  just "the process is alive". A canary whose DB check fails never becomes Ready
  and is rolled back.
- **livenessProbe** — HTTP GET `/` on port 80, starts after 30 s, checked every 10 s.
  Kept on `/` (not `/up`) on purpose: tying liveness to the DB would restart every
  pod in a cascade if MySQL blips.

### Demo hook

`app.forceUnhealthy: true` makes `/up` return 500 (via the `APP_FORCE_UNHEALTHY`
env var), so a healthy image can be made to fail readiness on demand to
demonstrate the automatic canary rollback — no deliberately broken image needed.

## Security context

The container runs with `runAsNonRoot: false`. This is a deliberate exception: the
Apache process in the image binds port 80, a privileged port that requires root.
`fsGroup: 33` (`www-data`) is set so mounted volumes remain group-accessible.

> If the workload is later switched to a non-privileged listening port (> 1024),
> set `runAsNonRoot: true` to restore the recommended hardening.

## Secrets

Sensitive values (`APP_KEY`, `DB_PASSWORD`) are stored in a Kubernetes `Secret` and injected via `secretKeyRef`. They are **never** hardcoded in `values.yaml` — both are required at install time via `--set`.

> Note: values passed with `--set` are persisted in the Helm release (visible via
> `helm get values`). For production, prefer an externally-managed `Secret`
> (e.g. External Secrets Operator) and reference it instead of supplying the
> values to Helm directly.
