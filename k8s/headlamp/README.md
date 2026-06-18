# Headlamp Dashboard

## Overview
[Headlamp](https://headlamp.dev/) is the cluster's web dashboard (Issue #9): a
read-only window onto workloads, nodes, events and logs, served as a plain HTTP
app behind the ingress. It talks to the kube-apiserver from inside the cluster
(`config.inCluster`) and authenticates each session with a bearer token the user
provides — so what a user can see/do is bounded by *their* token, not a blanket
dashboard identity.

## Design: vendored manifest
Like the Cilium CNI, Argo CD and ingress-nginx, Headlamp is installed from a
manifest checked into the repo (`k8s/headlamp/headlamp.yaml`), rendered with
`helm template` — never `helm install` (no Helm release state on the cluster).
Every change is a reviewable diff alongside its source values
(`helm/headlamp/values.yaml`).

### Regenerating the manifest
```sh
helm repo add headlamp https://kubernetes-sigs.github.io/headlamp/
helm repo update headlamp
helm template headlamp headlamp/headlamp \
  --version <CHART_VERSION> \
  --namespace headlamp \
  --values helm/headlamp/values.yaml \
  > k8s/headlamp/headlamp.yaml
```
Pinned chart version: **0.43.0** (app version 0.43.0). Bump it here and in the
command above, then commit the regenerated manifest with the values change.

## Exposure & TLS
Headlamp has no special networking needs — no `hostNetwork`, no control-plane
pinning. The `Service` stays `ClusterIP`; real traffic reaches it through a
standalone `Ingress` (`k8s/headlamp/ingress.yaml`) on the `nginx` IngressClass,
with TLS issued by cert-manager. As with Argo CD, the Ingress runs on the
`letsencrypt-prod` issuer; the chain was first validated on `letsencrypt-staging`
before switching. The HTTP-01 self-check needs a CoreDNS `hosts` entry for this
host — see `k8s/cert-manager/README.md`.

Target host: **https://headlamp.kubequest.epitech.beer**

### Resource limits
The cluster is resource-constrained, so the dashboard is capped
(`requests` 50m/64Mi, `limits` 200m/128Mi) rather than left to burst.

## Authentication & RBAC
The chart creates a `ServiceAccount` and a `ClusterRoleBinding` for the pod. It
is bound to the built-in **`view`** ClusterRole (read-only) — see
`clusterRoleName` in `helm/headlamp/values.yaml`. Switch it to `cluster-admin`
only if the dashboard itself must mutate resources.

`unsafeUseServiceAccountToken` is **false**: the UI shows a login screen and runs
each session with the permissions of the token the user pastes. Mint one with:
```sh
kubectl -n headlamp create token headlamp
```
The session's effective permissions are the intersection of that token's RBAC
and the dashboard's reachability — a token from a more privileged ServiceAccount
grants more in the UI.

## OIDC login (Dex / GitHub)
Headlamp also offers OIDC login via the cluster-wide external Dex, which
federates GitHub and only admits the `T-CLO-902-KubeQuest` org. The pod reads
its OIDC config from env vars wired to a `headlamp-oidc` Secret
(`OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_ISSUER_URL`, `OIDC_SCOPES`).

That Secret is **created by hand and kept out of Git** — it is the single source
of truth:
```sh
kubectl -n headlamp create secret generic headlamp-oidc \
  --from-literal=clientID=headlamp \
  --from-literal=clientSecret=<secret> \
  --from-literal=issuerURL=https://dex.kubequest.epitech.beer \
  --from-literal=scopes="profile email groups"
```
`clientSecret` must equal the `headlamp` value of the `dex-clients` Secret on the
Dex side.

> Do **not** put `openid` in `scopes`: Headlamp prepends it automatically, and a
> duplicated `openid openid ...` is rejected by Dex, so the login fails. List
> only the extra scopes (`profile email groups`).

> Regeneration caveat: this chart (0.43.0) only wires the OIDC env vars via
> `secretKeyRef` when `config.oidc.secret.create=true`, but that also makes it
> render a `Secret` from the values (a placeholder `clientSecret`). After
> `helm template`, **strip that generated `Secret` document** from
> `k8s/headlamp/headlamp.yaml` so no secret material lands in Git — the
> Deployment keeps its `secretKeyRef`s to the hand-created `headlamp-oidc`.

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/headlamp/application.yaml`
is picked up by the root app-of-apps and synced automatically. No manual
`kubectl apply`.
