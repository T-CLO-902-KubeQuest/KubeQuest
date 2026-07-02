# HashiCorp Vault

## Overview
Vault is the cluster's future single source of truth for secrets. Today,
every credential is created by hand and kept out of Git: `mysql-credentials`
(`k8s/mysql/README.md`), `dex-github`/`dex-clients` (`k8s/dex/README.md`),
`grafana-admin`/`grafana-oidc` (`k8s/monitoring/README.md`) and `oauth2-proxy`
(`k8s/oauth2-proxy/README.md`) — each README documents this as technical debt.

This ticket deploys Vault itself, with the **Vault Agent Injector** enabled
and the Kubernetes auth method configured with a minimal demo policy/role
proving the mechanism works. **Migrating the existing secrets above is out of
scope** — a follow-up piece of work once this foundation is in place.

## Design: vendored manifest
Like Cilium, Argo CD, cert-manager, Kyverno, MySQL and oauth2-proxy, Vault is
installed from a manifest checked into the repo (`k8s/vault/vault.yaml`),
rendered with `helm template` — never `helm install` (no Helm release state on
the cluster). Every change is a reviewable diff alongside its source values
(`helm/vault/values.yaml`).

### Regenerating the manifest
```sh
helm repo add hashicorp https://helm.releases.hashicorp.com
helm repo update hashicorp
helm template vault hashicorp/vault \
  --version 0.32.0 \
  --namespace vault \
  --values helm/vault/values.yaml \
  > k8s/vault/vault.yaml
```
Pinned chart version: **0.32.0** (app version Vault **1.21.2**). Bump it here
and in the command above, then commit the regenerated manifest with the
values change.

## Mode: standalone with Integrated Storage (Raft)
The chart's `server.ha.*` block deploys a multi-replica StatefulSet with Raft
clustering **between pods** — deliberately not used here: this cluster has
only 2 workers at 4 GB RAM each, no comfortable room for a multi-node Raft
quorum alongside MySQL, monitoring and everything else.

Instead, `server.ha.enabled` stays at its chart default (`false`) and
`server.standalone.enabled: true` is used, but with its `config` overridden to
swap the default `storage "file"` backend for `storage "raft"`
(`helm/vault/values.yaml`). This is a **single pod** using the modern
Integrated Storage engine — no external Consul, no multi-node quorum, but no
HA either: Vault is a single point of failure by design in this deployment.

Persistent storage: a 2Gi PVC on the **`local-path`** StorageClass (same as
MySQL), `server.dataStorage`.

### Kyverno compliance
The `vault` namespace is **not** in the Kyverno infra exclusion list
(`helm/kyverno/values.yaml`), so the enforced ClusterPolicies apply. The
server container therefore drops ALL capabilities
(`server.statefulSet.securityContext.container` in `helm/vault/values.yaml`),
which also means no `IPC_LOCK`: `disable_mlock = true` is set in the server
config — required anyway by Vault >= 1.20 with Integrated Storage, and
HashiCorp's recommendation for Raft (data already touches disk, mlock adds
no meaningful protection).

## Initialization (manual, once) and auto-unseal
Vault standalone starts **sealed**. Initialization is a one-time manual step:

```sh
kubectl -n vault exec -it vault-0 -- vault operator init \
  -key-shares=5 -key-threshold=3
# Store the 5 unseal keys and the root token somewhere safe, OUTSIDE Git.
# Never commit real key/token values, even as "examples".
```

Unsealing is handled by the in-cluster **vault-unsealer** controller
(`k8s/vault/unsealer.yaml`, see [adr/0006](../../adr/0006-vault-in-cluster-auto-unseal.md)):
it watches Secrets in this namespace labelled with the target StatefulSet
name and unseals the vault pods whenever they start sealed — which on this
cluster happens **every morning** (the nodes are shut down at night).

Create the Secret once, by hand (out of Git, like `mysql-credentials`), with
at least `-key-threshold` (3) of the 5 keys:

```sh
kubectl -n vault create secret generic vault-unseal-keys \
  --from-literal=unsealKey1=<key1> \
  --from-literal=unsealKey2=<key2> \
  --from-literal=unsealKey3=<key3>
kubectl -n vault label secret vault-unseal-keys \
  vault-unsealer.bakito.net/stateful-set=vault
```

> The unsealer only loads the keys Secret **at startup**: if the Secret is
> created (or changed) while the controller is already running, restart it —
> `kubectl -n vault rollout restart deploy/vault-unsealer` — so it picks the
> keys up and performs the unseal.

Check status at any time with
`kubectl -n vault exec -it vault-0 -- vault status`. Manual fallback if the
unsealer is ever broken: `vault operator unseal <key>` three times.

> The pod's readiness probe expects Vault to be unsealed, so `vault-0` will
> show `0/1 Ready` (and Argo CD will report the `vault` Application as
> `Progressing`/`Degraded`) between deployment and the first unseal, and
> briefly after each restart until the unsealer acts. This is expected — not
> a sync failure.

### Regenerating the unsealer manifest
```sh
helm repo add bakito https://bakito.github.io/helm-charts
helm repo update bakito
helm template vault-unsealer bakito/vault-unsealer \
  --version 0.4.1 \
  --namespace vault \
  --values helm/vault/unsealer-values.yaml \
  > k8s/vault/unsealer.yaml
```
Pinned chart version: **0.4.1** (app version v0.4.1).

## Kubernetes auth method (Injector foundation, demo only)
Enable the auth method Vault needs to validate Kubernetes ServiceAccount
tokens, then a minimal policy/role to prove the Injector path works end to
end:

```sh
kubectl exec -it vault-0 -n vault -- vault login <root-token>
kubectl exec -it vault-0 -n vault -- vault auth enable kubernetes
kubectl exec -it vault-0 -n vault -- vault write auth/kubernetes/config \
  kubernetes_host="https://kubernetes.default.svc:443"

kubectl exec -it vault-0 -n vault -- vault secrets enable -path=secret kv-v2
kubectl exec -it vault-0 -n vault -- vault kv put secret/demo/example \
  username=demo password=demo123

kubectl exec -it vault-0 -n vault -- vault policy write demo-policy - <<'EOF'
path "secret/data/demo/*" {
  capabilities = ["read"]
}
EOF

kubectl exec -it vault-0 -n vault -- vault write auth/kubernetes/role/demo-role \
  bound_service_account_names=default \
  bound_service_account_namespaces=default \
  policies=demo-policy \
  ttl=1h
```

This `demo-role`/`demo-policy` pair is purely illustrative (bound to the
`default` ServiceAccount/namespace) — it exists to prove the Injector and
Kubernetes auth method are wired correctly, not to protect anything real.

**Next steps (future ticket):** migrate `mysql-credentials`, `dex-github`,
`dex-clients`, `grafana-admin`, `grafana-oidc` and `oauth2-proxy` into KV
paths with dedicated Kubernetes roles (one per consuming app), and add
`vault.hashicorp.com/agent-inject: "true"` annotations to the relevant
Deployments/StatefulSets.

## Exposure & TLS
The UI is reachable at `vault.kubequest.epitech.beer` (`k8s/vault/ingress.yaml`).
Vault's listener has `tls_disable = 1`, so the Service serves plain HTTP;
TLS is terminated at the Ingress by cert-manager on the `letsencrypt-prod`
issuer (HTTP-01). The HTTP-01 self-check needs a CoreDNS `hosts` entry for
`vault.kubequest.epitech.beer` — see `k8s/cert-manager/README.md`. A matching
DNS A record must also exist at the DNS provider (`epitech.beer`) — both are
manual, out-of-Git steps.

## SSO (native OIDC via Dex)
Vault authenticates users itself, like Grafana: its **native OIDC auth
method** (`auth/oidc`) speaks to the cluster-wide Dex (staticClient `vault`
in `helm/dex/values.yaml`, client secret = the `vault` key of the
`dex-clients` Secret). The earlier oauth2-proxy forward-auth layer was
removed in favour of this single login — see
[adr/0007](../../adr/0007-vault-native-oidc-dex.md).

One-time configuration (after init/unseal, with a root token):

```sh
vault auth enable oidc
vault write auth/oidc/config \
  oidc_discovery_url="https://dex.kubequest.epitech.beer" \
  oidc_client_id="vault" \
  oidc_client_secret="<the vault key of the dex-clients Secret>" \
  default_role="admin"

vault policy write admin - <<'EOF'
path "*" {
  capabilities = ["create", "read", "update", "delete", "list", "sudo"]
}
EOF

vault write auth/oidc/role/admin \
  allowed_redirect_uris="https://vault.kubequest.epitech.beer/ui/vault/auth/oidc/oidc/callback" \
  allowed_redirect_uris="http://localhost:8250/oidc/callback" \
  user_claim="email" \
  oidc_scopes="openid,profile,email" \
  policies="admin" \
  token_ttl="1h"
```

UI login: pick the **OIDC** method → Dex popup → GitHub (org-restricted) →
back in the UI with the `admin` policy. CLI login from a workstation:
`vault login -method=oidc` (uses the `http://localhost:8250/oidc/callback`
redirect). Any member of the `T-CLO-902-KubeQuest` GitHub org gets the
**admin** policy — an accepted trade-off on this demo cluster (adr/0007).

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/vault/application.yaml`
(sync-wave `0`) is picked up by the root app-of-apps and synced automatically.
No manual `kubectl apply` for the manifests — but init/unseal and the
Kubernetes auth method setup above remain manual, out-of-Git steps.

## Production evolution
- **Auto-unseal keys live in-cluster**: the `vault-unseal-keys` Secret means
  Vault's seal no longer protects against an attacker with cluster access —
  anyone who can read Secrets in the `vault` namespace can unseal (see
  adr/0006 for why this trade-off was accepted on this nightly-shutdown
  cluster). The robust evolution is `seal "awskms"` in the server config,
  which needs IAM wiring (a role for the pod, a KMS key policy) not yet
  present in the Terraform infra — same gap already noted for the AWS EBS
  CSI driver in `k8s/mysql/README.md`.
- **No HA**: a single pod is a single point of failure. Multi-replica Raft
  HA is deliberately deferred until the cluster has more headroom.
- **Secrets not yet migrated**: Vault currently coexists with the hand-created
  Secrets it is meant to replace.
- **Storage tied to one node**: like MySQL, the `local-path` PVC lives on the
  node's local disk.
