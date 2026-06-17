# cert-manager

## Overview
[cert-manager](https://cert-manager.io/) issues and renews TLS certificates for
the cluster. It pulls certificates from Let's Encrypt and stores them as
Kubernetes secrets that Ingress resources reference, so HTTPS is automatic and
self-renewing.

## Design: vendored manifest
Like the rest of the platform, cert-manager is installed from a manifest checked
into the repo (`k8s/cert-manager/cert-manager.yaml`), rendered with
`helm template` — never `helm install`. Source values live in
`helm/cert-manager/values.yaml`. The CRDs are rendered *into* the manifest
(`crds.enabled: true`) so the whole install is one GitOps-applied file.

### Regenerating the manifest
```sh
helm repo add jetstack https://charts.jetstack.io
helm repo update jetstack
helm template cert-manager jetstack/cert-manager \
  --version <CHART_VERSION> \
  --namespace cert-manager \
  --values helm/cert-manager/values.yaml \
  > k8s/cert-manager/cert-manager.yaml
```
Pinned chart version: **v1.20.2**.

## Issuance: Let's Encrypt over HTTP-01
The DNS provider for `epitech.beer` does not support wildcards, so DNS-01 brings
no benefit; certificates are issued per-host with the **HTTP-01** solver through
the `nginx` IngressClass (`cluster-issuers.yaml`).

HTTP-01 works by Let's Encrypt fetching
`http://<host>/.well-known/acme-challenge/...` on **port 80** of the Elastic IP,
where ingress-nginx listens via hostNetwork. Two prerequisites:

1. **DNS**: an A record for the host (e.g. `argocd.kubequest.epitech.beer`)
   pointing at the control-plane Elastic IP.
2. **AWS security group**: inbound **port 80** open (not only 443) — the
   challenge runs over plain HTTP even for an HTTPS-only service.

Two `ClusterIssuer`s exist: `letsencrypt-staging` and `letsencrypt-prod`. Start
on **staging** to validate the challenge chain without burning the strict
production rate limit, then switch the Ingress
`cert-manager.io/cluster-issuer` annotation to `letsencrypt-prod` and delete the
old secret to force a prod re-issue.

## Deployment
GitOps via three Applications under `argocd/apps`, ordered by sync-wave so the
webhook is ready before any custom resource is admitted:

- `cert-manager` (wave 0) — controller + webhook + cainjector + CRDs
- `cert-manager-issuers` (wave 1) — the two `ClusterIssuer`s
- consumers (e.g. `argocd-ingress`, wave 2) — Ingresses with the annotation

## Resource limits
All three components are capped (`limits` 100m/128Mi each) since cert-manager
also lands on the control-plane, the node we keep from OOMing.
