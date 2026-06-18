# Dex (OIDC provider)

## Overview
[Dex](https://dexidp.io/) is the cluster's application-level OIDC provider
(Issue #10). Cluster services — Headlamp, Grafana, Argo CD, ... — delegate their
login to Dex, which in turn **federates GitHub** as the upstream identity
provider. The Kubernetes API server is also wired to Dex as an OIDC token
validator (see `k8s/oidc-rbac/` and the `control-plane` Ansible role), so tokens
minted via Dex are accepted by the API too — bound read-only to the GitHub org.

This is the single, cluster-wide Dex the Argo CD install intentionally leaves
out of its own bundle (see `argocd/README.md` → "Minimal footprint"). Wire Argo
CD's SSO to it later via `oidc.config` in `argocd-cm`.

## Design: vendored manifest
Like the Cilium CNI, Argo CD, ingress-nginx and Headlamp, Dex is installed from a
manifest checked into the repo (`k8s/dex/dex.yaml`), rendered with `helm
template` — never `helm install` (no Helm release state on the cluster). Every
change is a reviewable diff alongside its source values (`helm/dex/values.yaml`).

### Regenerating the manifest
```sh
helm repo add dex https://charts.dexidp.io
helm repo update dex
helm template dex dex/dex \
  --version <CHART_VERSION> \
  --namespace dex \
  --values helm/dex/values.yaml \
  > k8s/dex/dex.yaml
```
Pinned chart version: **0.24.1** (app version 2.44.0). Bump it here and in the
command above, then commit the regenerated manifest with the values change.

## Exposure & TLS
The `Service` stays `ClusterIP` (port 5556, plain HTTP); real traffic reaches Dex
through a standalone `Ingress` (`k8s/dex/ingress.yaml`) on the `nginx`
IngressClass, with TLS issued by cert-manager. As with Argo CD and Headlamp, the
Ingress runs on the `letsencrypt-prod` issuer; the chain was first validated on
`letsencrypt-staging` before switching. The HTTP-01 self-check needs a CoreDNS
`hosts` entry for this host — see `k8s/cert-manager/README.md`.

Target host: **https://dex.kubequest.epitech.beer** — this MUST match
`config.issuer` in the values and every client's configured issuer URL.

## GitHub OAuth credentials
Dex federates GitHub via a GitHub OAuth App. Its credentials are **not** in Git;
they come from a Secret created once in the `dex` namespace:
```sh
kubectl -n dex create secret generic dex-github \
  --from-literal=clientID=<id> \
  --from-literal=clientSecret=<secret>
```
The OAuth App's *Authorization callback URL* must be
`https://dex.kubequest.epitech.beer/callback`.

Login is restricted to members of the **`T-CLO-902-KubeQuest`** GitHub org
(`connectors[].config.orgs` in the values); their teams are surfaced as OIDC
groups to clients that request the `groups` scope.

## Clients
Each service that logs in through Dex is declared as a `staticClient` in
`helm/dex/values.yaml` (Grafana, Argo CD, Headlamp, oauth2-proxy). Client secrets
are **not** in Git: each entry uses `secretEnv` (dex >= 2.35.0) to read its secret
from an env var, fed by a hand-created `dex-clients` Secret:
```sh
kubectl -n dex create secret generic dex-clients \
  --from-literal=argocd=<secret> \
  --from-literal=headlamp=<secret> \
  --from-literal=grafana=<secret> \
  --from-literal=oauth2-proxy=<secret>
```
The `oauth2-proxy` client is the shared auth layer (Issue #11) that fronts tools
without their own login (Prometheus, Grafana, ...); see
`k8s/oauth2-proxy/README.md`.
Each value must match the client secret configured in the corresponding app
(e.g. `argocd` here == the OIDC client secret in `argocd-secret`). A client's
`redirectURIs` must exactly match what the app sends.

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/dex/application.yaml` is
picked up by the root app-of-apps and synced automatically. No manual
`kubectl apply` — but the `dex-github` Secret above must exist in the namespace
before the GitHub connector can start.
