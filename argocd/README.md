# Argo CD (GitOps)

Argo CD is the GitOps engine for the cluster: once bootstrapped, every workload
is declared as an Argo CD `Application` in Git and reconciled automatically.

## Layout
```
argocd/
  install/
    values.yaml    # source Helm values (minimal footprint)
    argocd.yaml    # vendored manifest rendered from the chart — what gets applied
  apps/
    root/
      application.yaml   # app-of-apps; bootstraps everything else
    <workload>/
      application.yaml   # one dir per workload, synced by the root app
```

## Design: vendored manifest
Like the Cilium CNI, Argo CD is installed from a manifest checked into the repo
(`install/argocd.yaml`), rendered with `helm template` — never `helm install`
(no Helm release state on the cluster). Every change to the install is a
reviewable diff alongside its source `values.yaml`.

### Minimal footprint
This cluster is resource-constrained, so the install is trimmed to the four core
components: `application-controller`, `repo-server`, `server` (API/UI) and
`redis`. Disabled extras:

- **Dex**: a cluster-wide external Dex is shared across workloads, so the bundled
  Dex would be a second, redundant one. Wire SSO later via `oidc.config` in
  `argocd-cm` (issuer = external Dex URL).
- **ApplicationSet**: applications are declared by hand under `apps/`, so the
  controller is scaled to 0 replicas (the chart has no `enabled` toggle for it).
- **Notifications**: no alerting wired up.

Redis is **not** disabled: the controller and repo-server use it as a cache and
crash-loop without it. It runs as a single lightweight pod (no HA).

### Regenerating the manifest
```sh
helm repo add argo https://argoproj.github.io/argo-helm
helm repo update argo
helm template argocd argo/argo-cd \
  --version <ARGOCD_CHART_VERSION> \
  --namespace argocd \
  --values argocd/install/values.yaml \
  > argocd/install/argocd.yaml
```
Pinned chart version: **9.5.21** (app version v3.4.3). Bump it here and in the
command above, then commit the regenerated manifest with the values change.

## Bootstrap
Applied manually once against the cluster's kubeconfig:

```sh
# 1. Install Argo CD itself.
# Use server-side apply: the bundled CRDs are larger than the 262144-byte
# limit on the last-applied-configuration annotation that client-side apply
# writes, so a plain `kubectl apply` fails on the applicationsets CRD.
kubectl create namespace argocd --dry-run=client -o yaml | kubectl apply -f -
kubectl apply --server-side -n argocd -f argocd/install/argocd.yaml

# 2. Hand control over to GitOps via the root app-of-apps
kubectl apply -n argocd -f argocd/apps/root/application.yaml
```

From then on Argo CD is self-managing: commit a new
`argocd/apps/<name>/application.yaml` and the root app syncs it — no further
manual `kubectl apply`.

## Accessing the UI
The server is exposed at **https://argocd.kubequest.epitech.beer** through the
ingress-nginx controller, with TLS issued by cert-manager (see
`k8s/argocd/ingress.yaml`). `argocd-server` runs in `--insecure` mode
(`configs.params.server.insecure: true`) so the Ingress terminates TLS once —
otherwise the server's own TLS plus the Ingress TLS double up and cause redirect
loops.

The initial admin password:
```sh
kubectl -n argocd get secret argocd-initial-admin-secret \
  -o jsonpath='{.data.password}' | base64 -d
```
Log in as user `admin`. (A `kubectl -n argocd port-forward svc/argocd-server
8080:80` still works for local access if the Ingress is down.)

> The Ingress starts on the `letsencrypt-staging` issuer (untrusted cert, used
> to validate HTTP-01). Switch the `cert-manager.io/cluster-issuer` annotation
> to `letsencrypt-prod` once staging issues cleanly.
