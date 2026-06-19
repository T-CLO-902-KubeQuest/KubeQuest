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
- **Logs.** Prometheus is a *metrics* system (numeric time series) — it does not
  collect logs. Logs need a separate stack (**Loki** + Promtail/Alloy, or EFK).
  Loki is the natural companion (same Grafana) and is left as a follow-up.
- **Full control-plane metrics.** `kube-apiserver` and `kubelet` are scraped
  out-of-the-box. `kube-scheduler`, `kube-controller-manager` and `etcd` bind
  their `/metrics` to `127.0.0.1` by default and are **not** scraped. Exposing
  them requires `bind-address: 0.0.0.0` (+ etcd `listen-metrics-urls`) in the
  control-plane static pods, i.e. a deliberate change to the Ansible
  `control-plane` role (kubeadm v1beta4 on k8s 1.33). Deferred to its own ticket.
- **Grafana / Alertmanager.** Disabled in `values.yaml`. Visualization is a
  separate ticket that *consumes* these metrics; no alerting is requested.

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
```

The heavy components (Prometheus, Prometheus Operator, kube-state-metrics) carry
`nodeSelector: workload=monitoring` in `values.yaml`. **`node-exporter` is the
exception**: it is a DaemonSet with a broad toleration so it runs on *every* node
(including the tainted master) to report each node's metrics — restricting it to
the monitoring node would lose the other nodes' metrics.

> **Prerequisite:** apply the label **before** Argo CD syncs, otherwise the
> Prometheus pod stays `Pending` (nodeSelector unsatisfied). That is expected,
> not a bug. We use a `nodeSelector` (attract) rather than a taint (reserve) to
> avoid evicting MySQL, which is fine for the criterion.
>
> GitOps evolution: set `--node-labels=workload=monitoring` on the chosen
> worker's `kubeadm join` in the Ansible `workers` role, so the label is
> reproducible if the cluster is recreated.

## Access (not public without auth)
Prometheus is exposed only as a **ClusterIP** Service — no Ingress. The
visualization layer scrapes it in-cluster at
`http://kube-prometheus-stack-prometheus.monitoring.svc:9090`. If a human needs
the UI from outside, route it through the existing **oauth2-proxy + Dex** chain
like the other UIs — never a bare public Ingress.

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/monitoring/application.yaml`
(sync-wave `0`) is picked up by the root app-of-apps and synced automatically. It
uses `ServerSideApply=true` because the operator CRDs exceed the 262144-byte
client-side apply annotation limit (same as cert-manager / argocd). No manual
`kubectl apply`.

The `Application` includes two files:
- `monitoring.yaml` — the rendered stack (operator, Prometheus, exporters, rules).
- `example-app.yaml` — a demonstration fixture (Deployment + Service +
  ServiceMonitor) proving an instrumented app pod is auto-scraped. Replace/extend
  with real instrumentation of the Laravel `sample-app` later.

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
