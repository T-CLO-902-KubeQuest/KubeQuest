# Monitoring — kube-prometheus-stack

## Overview
The cluster needs a metrics stack that scrapes the Kubernetes components (nodes,
kubelet, control-plane) **and** the deployed applications that expose a metrics
endpoint. These metrics feed the visualization layer and, later, autoscaling for
the final demo.

We use **kube-prometheus-stack** (the `prometheus-community` chart) rather than a
bare Prometheus, because it ships the **Prometheus Operator** and its
`ServiceMonitor` / `PodMonitor` CRDs. An application declares its scrape target
by committing a `ServiceMonitor` next to itself — Prometheus discovers it
automatically, no central config edit. Same operator/CRD spirit as cert-manager.

## What gets collected
Four distinct layers — only the last one needs a per-app object:

| Signal | Collector | Per-app object? |
|---|---|---|
| Node metrics (CPU, RAM, disk, net of each VM) | `node-exporter` (DaemonSet, every node) | no — automatic |
| K8s object state (every pod's phase, restarts, deployments, replicas, requests…) | `kube-state-metrics` | no — automatic |
| Per-container usage (`container_cpu_*`, `container_memory_*`) | `kubelet` / cAdvisor | no — automatic |
| Application custom metrics (e.g. an app's HTTP request count) | `ServiceMonitor` + app exposes `/metrics` | yes — opt-in |

So **every pod and node is already monitored** by kube-state-metrics +
node-exporter + kubelet. A `ServiceMonitor` is only for metrics an *application*
emits itself.

### Out of scope
- **Full control-plane metrics.** `kube-apiserver` and `kubelet` are scraped
  out-of-the-box. `kube-scheduler`, `kube-controller-manager` and `etcd` bind
  their `/metrics` to `127.0.0.1` by default and are **not** scraped. Exposing
  them requires `bind-address: 0.0.0.0` (+ etcd `listen-metrics-urls`) in the
  control-plane static pods, i.e. a deliberate change to the Ansible
  `control-plane` role (kubeadm v1beta4 on k8s 1.33). Deferred to its own ticket.
- **Alertmanager.** Disabled in `values.yaml` — no alerting is requested for now.

## Visualization & logs (Grafana + Loki + Promtail)
Grafana (issue #14) and the log stack (issue #15) ship alongside the metrics:

- **Grafana** is enabled in `values.yaml`, scheduled on the monitoring node, and
  exposed at `https://grafana.kubequest.epitech.beer` (see *Access* below). It is
  authenticated through the cluster's **Dex OIDC** chain (`generic_oauth`), not a
  hardcoded admin password. Datasources (Prometheus + Loki) and the bundled
  Kubernetes dashboards are auto-provisioned via the Grafana sidecar.
- **Loki** (`grafana/loki`, SingleBinary) aggregates logs;
  **Promtail** (`grafana/promtail`, DaemonSet on every node) ships them.
  Both are vendored like the metrics stack — see the regeneration commands in
  `helm/monitoring/loki-values.yaml` and `helm/monitoring/promtail-values.yaml`.
  Retention is bounded to **24h** on `emptyDir`, consistent with the metrics side.
- **`loki-dashboard.yaml`** is a custom dashboard (ConfigMap labelled
  `grafana_dashboard`) with a Loki logs panel, proving logs are queryable from the
  visualization layer per issue #15.

### Secrets (out of Git)
Like MySQL/Dex, Grafana's secrets are created manually and never committed.
Create them in the `monitoring` namespace before Argo CD syncs:

```sh
kubectl create namespace monitoring   # if it does not exist yet

# Fallback admin account (login form is kept enabled as a backup to OIDC):
kubectl create secret generic grafana-admin -n monitoring \
  --from-literal=admin-user=admin \
  --from-literal=admin-password="$(openssl rand -base64 24)"

# OIDC client secret — same value as the `grafana` key of the dex-clients secret.
# The key MUST be named exactly GF_AUTH_GENERIC_OAUTH_CLIENT_SECRET (the chart
# mounts it as an env var, expanded by ${...} in grafana.ini).
kubectl create secret generic grafana-oidc -n monitoring \
  --from-literal=GF_AUTH_GENERIC_OAUTH_CLIENT_SECRET="<same as dex-clients/grafana>"
```

The Dex client `grafana` and its redirectURI are already declared in
`helm/dex/values.yaml` — no Dex change is needed.

## Design: vendored manifest
Like Cilium, Argo CD, cert-manager and MySQL, the stack is installed from a
manifest checked into the repo (`k8s/monitoring/monitoring.yaml`), rendered with
`helm template` — never `helm install` (no Helm release state on the cluster).
Every change is a reviewable diff alongside its source values
(`helm/monitoring/values.yaml`).

### Regenerating the manifest
```sh
helm repo add prometheus-community https://prometheus-community.github.io/helm-charts
helm repo update prometheus-community
helm template kube-prometheus-stack prometheus-community/kube-prometheus-stack \
  --version 86.3.1 \
  --namespace monitoring \
  --include-crds \
  --values helm/monitoring/values.yaml \
  > k8s/monitoring/monitoring.yaml
```
Pinned chart version: **86.3.1**. `--include-crds` is required — without it the
operator starts but Prometheus cannot be created (CRD missing). Keep the release
name `kube-prometheus-stack` stable (it prefixes every resource name). Bump the
version here and in the command, then commit the regenerated manifest with the
values change.

## Retention & resources
The nodes are small (2 vCPU / 4 GB) and the VMs are shut down at night, so the
stack is kept lean:
- **Retention `24h`**, time-based only (`prometheus.prometheusSpec.retention`).
- **Storage: `emptyDir`** (no `storageSpec`). History is lost when the Prometheus
  pod restarts — acceptable for a demo cluster that is powered off nightly, and
  it avoids pinning a `local-path` PVC to a node.
- **Resource caps** on every component (see `values.yaml`), like MySQL.

## Scheduling on the dedicated monitoring node
The acceptance criterion requires the stack to land on a **dedicated monitoring
node**. The topology has no such node by default (1 master + 2 identical
workers), so we designate one worker with a label:

```sh
# Pick the worker that does NOT run mysql-0 (its local-path PVC pins it there):
kubectl get pod -n mysql -o wide        # note the NODE of mysql-0
kubectl label node <other-worker> workload=monitoring
kubectl taint node <other-worker> workload=monitoring:NoSchedule
```

The heavy components (Prometheus, Prometheus Operator, Grafana, Loki,
kube-state-metrics) carry `nodeSelector: workload=monitoring` **and** the
matching toleration in `values.yaml` / `loki-values.yaml`. **`node-exporter` and
`promtail` are the exception**: they are DaemonSets with a broad toleration so
they run on *every* node (including the tainted master) to report each node's
metrics/logs — restricting them to the monitoring node would lose the other
nodes' signals.

> **Prerequisite:** apply the label **before** Argo CD syncs, otherwise the
> Prometheus pod stays `Pending` (nodeSelector unsatisfied). That is expected,
> not a bug.
>
> The node is **reserved** for observability: the `nodeSelector` attracts the
> stack, the `NoSchedule` taint keeps everything else off. `NoSchedule` does not
> evict pods already running there — after tainting, delete any lingering
> non-monitoring pods (their controllers reschedule them elsewhere). Also note
> that Loki's `local-path` PVC pins it to this node anyway, so the reservation
> also formalises that existing constraint.
>
> GitOps evolution: set `--node-labels=workload=monitoring` and
> `--register-with-taints=workload=monitoring:NoSchedule` on the chosen worker's
> kubelet in the Ansible `workers` role, so both are reproducible if the cluster
> is recreated.

## Access (not public without auth)
**Prometheus** stays internal: a **ClusterIP** Service, no Ingress. Grafana
scrapes it in-cluster at
`http://kube-prometheus-stack-prometheus.monitoring.svc:9090`.

**Grafana** is the single human entry point, exposed at
`https://grafana.kubequest.epitech.beer` (`k8s/monitoring/grafana-ingress.yaml`,
`ingressClassName: nginx`, TLS via `letsencrypt-prod`). Unlike the other UIs it
authenticates users itself through Dex (`generic_oauth`) rather than delegating
to oauth2-proxy's nginx `auth_request` — so its Ingress carries no auth-url
annotations. The Dex client `grafana` already exists in `helm/dex/values.yaml`.

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/monitoring/application.yaml`
(sync-wave `0`) is picked up by the root app-of-apps and synced automatically. It
uses `ServerSideApply=true` because the operator CRDs exceed the 262144-byte
client-side apply annotation limit (same as cert-manager / argocd). No manual
`kubectl apply`.

The `Application` includes these files:
- `monitoring.yaml` — the rendered stack (operator, Prometheus, Grafana,
  exporters, rules, bundled dashboards).
- `example-app.yaml` — a demonstration fixture (Deployment + Service +
  ServiceMonitor) proving an instrumented app pod is auto-scraped. Replace/extend
  with real instrumentation of the Laravel `sample-app` later.
- `loki.yaml` / `promtail.yaml` — the rendered log aggregation stack (#15).
- `grafana-ingress.yaml` — exposes Grafana via Ingress (#14).
- `loki-dashboard.yaml` — the custom Loki logs dashboard (#15).

## Verifying the acceptance criteria
After sync (and the node label applied):
```sh
kubectl -n monitoring port-forward svc/kube-prometheus-stack-prometheus 9090
```
In the UI (Status > Targets) or via PromQL:
- `up{job="kubelet"} == 1`      → kubelet metrics scraped (criterion 1)
- `up{job="example-app"} == 1`  → instrumented app pod scraped (criterion 2)

And the stack is scheduled on the dedicated node:
```sh
kubectl -n monitoring get pods -o wide   # Prometheus/operator/KSM on the labeled worker
```
