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

## Initialization and unseal (manual, post-deployment)
Vault standalone starts **sealed**. Unlike the managed-cloud offerings, this
deployment has no auto-unseal configured (see "Production evolution" below),
so this is a one-time, then repeat-after-every-restart, manual procedure:

```sh
kubectl -n vault exec -it vault-0 -- vault operator init \
  -key-shares=5 -key-threshold=3
# Store the 5 unseal keys and the root token somewhere safe, OUTSIDE Git.
# Never commit real key/token values, even as "examples".

kubectl -n vault exec -it vault-0 -- vault operator unseal <key1>
kubectl -n vault exec -it vault-0 -- vault operator unseal <key2>
kubectl -n vault exec -it vault-0 -- vault operator unseal <key3>
```

Any of the 5 keys works as long as 3 distinct ones are supplied. Check status
at any time with `kubectl -n vault exec -it vault-0 -- vault status`.

> The pod's readiness probe expects Vault to be unsealed, so `vault-0` will
> show `0/1 Ready` (and Argo CD will report the `vault` Application as
> `Progressing`/`Degraded`) between deployment and the first unseal. This is
> expected — not a sync failure.

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

## SSO (oauth2-proxy)
Vault's OSS UI has no simple native OIDC login, so — unlike Grafana, which
speaks OIDC to Dex directly — the Vault Ingress delegates authentication to
**oauth2-proxy**'s nginx `auth_request` forward-auth, using the annotations
documented in `k8s/oauth2-proxy/README.md` ("Protecting a future tool"). This
is the first Ingress in the cluster to actually consume that mechanism.
Logging into the UI via SSO only proves GitHub org membership — it does not
unseal Vault or grant any Vault policy; the two layers are independent.

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/vault/application.yaml`
(sync-wave `0`) is picked up by the root app-of-apps and synced automatically.
No manual `kubectl apply` for the manifests — but init/unseal and the
Kubernetes auth method setup above remain manual, out-of-Git steps.

## Production evolution
- **No auto-unseal**: every pod restart (OOMKill, reschedule, node reboot)
  re-seals Vault and requires a manual unseal with 3 of the 5 keys. The
  robust evolution is `seal "awskms"` in the server config, which needs IAM
  wiring (a role for the pod, a KMS key policy) not yet present in the
  Terraform infra — same gap already noted for the AWS EBS CSI driver in
  `k8s/mysql/README.md`.
- **No HA**: a single pod is a single point of failure. Multi-replica Raft
  HA is deliberately deferred until the cluster has more headroom.
- **Secrets not yet migrated**: Vault currently coexists with the hand-created
  Secrets it is meant to replace.
- **Storage tied to one node**: like MySQL, the `local-path` PVC lives on the
  node's local disk.
