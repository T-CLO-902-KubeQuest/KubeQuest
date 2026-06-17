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

## Probes

- **readinessProbe** — HTTP GET `/` on port 80, starts after 10 s, checked every 5 s. Prevents traffic from reaching pods that are not ready.
- **livenessProbe** — HTTP GET `/` on port 80, starts after 30 s, checked every 10 s. Restarts pods that become unresponsive.

## Secrets

Sensitive values (`APP_KEY`, `DB_PASSWORD`) are stored in a Kubernetes `Secret` and injected via `secretKeyRef`. They are **never** hardcoded in `values.yaml` — both are required at install time via `--set`.
