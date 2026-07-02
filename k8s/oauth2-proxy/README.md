# oauth2-proxy (shared authentication layer)

## Overview
[oauth2-proxy](https://oauth2-proxy.github.io/oauth2-proxy/) is the cluster's
shared authentication layer (Issue #11). It sits in front of tools that have no
authentication of their own — Prometheus, Alertmanager — or whose auth we would
rather not expose directly (Grafana). Instead of teaching every tool OIDC, those
tools delegate their authentication to this one proxy, which speaks OIDC to the
cluster-wide **Dex** (which federates GitHub and only admits the
`T-CLO-902-KubeQuest` org).

Headlamp and Argo CD are **not** behind this proxy: they speak OIDC to Dex
natively (see `k8s/headlamp/README.md` and `argocd/README.md`). oauth2-proxy is
for the tools that can't.

> Nothing sits behind the proxy yet. This ticket ships the auth layer **ready to
> use**; the first protected tool (Prometheus/Grafana) wires itself in later via
> the annotations documented below.

## How it protects a tool (nginx forward-auth)
ingress-nginx supports `auth_request`: before routing a request to a tool, it
fires a sub-request to oauth2-proxy. If there's no valid session, the proxy
returns 401 and nginx bounces the user to Dex; once a session cookie is present
the proxy returns 202 and nginx lets the request through.

```
User → nginx ─auth_request→ oauth2-proxy ─OIDC→ Dex → GitHub (org-restricted)
            └─ 202 + cookie ─────────────────→ tool (prometheus, grafana, ...)
```

The proxy serves the OIDC dance on its own host,
**https://auth.kubequest.epitech.beer** (`/oauth2/start`, `/oauth2/callback`,
`/oauth2/auth`).

## Protecting a future tool
Add these annotations to that tool's **own** Ingress — no change to oauth2-proxy
itself:

```yaml
metadata:
  annotations:
    nginx.ingress.kubernetes.io/auth-url: "https://auth.kubequest.epitech.beer/oauth2/auth"
    nginx.ingress.kubernetes.io/auth-signin: "https://auth.kubequest.epitech.beer/oauth2/start?rd=$scheme://$host$escaped_request_uri"
```

> The `rd` parameter must carry `$scheme://$host`, not just the escaped path:
> the proxy lives on its own sub-domain, so a path-only `rd` would redirect
> the user to `auth.kubequest.epitech.beer/<path>` after login instead of
> back to the protected tool. (Found the hard way when Vault became the first
> real consumer — see `k8s/vault/ingress.yaml`.)

That's the whole opt-in. Because the session cookie is scoped to the parent
domain (`--cookie-domain=.kubequest.epitech.beer`), one login covers every
`*.kubequest.epitech.beer` tool — true SSO across sub-domains. Each protected
host needs the usual CoreDNS `hosts` entry for its cert-manager HTTP-01
self-check (see `k8s/cert-manager/README.md`).

## Authorization
`--email-domain=*` lets through anyone Dex authenticates. That is **not** an open
door: Dex's GitHub connector already restricts login to members of the
`T-CLO-902-KubeQuest` org, so org membership is the access boundary. Tighten to a
specific team later with `--allowed-groups` if needed — but note Dex's GitHub
`groups` claim has been unreliable on this cluster (see `k8s/oidc-rbac/README.md`).

## Design: vendored manifest
Like Dex, Argo CD, ingress-nginx and Headlamp, oauth2-proxy is installed from a
manifest checked into the repo (`k8s/oauth2-proxy/oauth2-proxy.yaml`), rendered
with `helm template` — never `helm install` (no Helm release state on the
cluster). Every change is a reviewable diff alongside its source values
(`helm/oauth2-proxy/values.yaml`).

### Regenerating the manifest
```sh
helm repo add oauth2-proxy https://oauth2-proxy.github.io/manifests
helm repo update oauth2-proxy
helm template oauth2-proxy oauth2-proxy/oauth2-proxy \
  --version <CHART_VERSION> \
  --namespace oauth2-proxy \
  --values helm/oauth2-proxy/values.yaml \
  > k8s/oauth2-proxy/oauth2-proxy.yaml
```
Pinned chart version: **10.7.0** (app version 7.15.3). Bump it here and in the
command above, then commit the regenerated manifest with the values change.

## Exposure & TLS
The `Service` stays `ClusterIP` (port 4180, plain HTTP); traffic reaches the
proxy through a standalone `Ingress` (`k8s/oauth2-proxy/ingress.yaml`) on the
`nginx` IngressClass, with TLS issued by cert-manager on the `letsencrypt-prod`
issuer (validated on `letsencrypt-staging` first). The HTTP-01 self-check needs a
CoreDNS `hosts` entry for `auth.kubequest.epitech.beer` — see
`k8s/cert-manager/README.md`.

## Secrets (created by hand, kept out of Git)
The proxy reads its credentials from a hand-created `oauth2-proxy` Secret in the
`oauth2-proxy` namespace — the single source of truth:

```sh
# Cookie secret must be 16, 24 or 32 bytes (AES); generate 32 bytes:
#   openssl rand -base64 32 | tr -d '\n'
kubectl -n oauth2-proxy create secret generic oauth2-proxy \
  --from-literal=client-id=oauth2-proxy \
  --from-literal=client-secret=<secret> \
  --from-literal=cookie-secret=<32-byte-base64>
```

`client-secret` must equal the `oauth2-proxy` value of the `dex-clients` Secret
on the Dex side (see `k8s/dex/README.md`). The session cookie is **Secure** and
**HttpOnly** (chart defaults), so it never travels over plain HTTP nor is
readable from JavaScript.

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/oauth2-proxy/application.yaml`
is picked up by the root app-of-apps and synced automatically. No manual
`kubectl apply` — but the `oauth2-proxy` Secret above must exist in the namespace
before the pod can start, and the `oauth2-proxy` key must be present in the
`dex-clients` Secret before Dex will accept the client.
