# MySQL Database

## Overview
MySQL 8.0 is the persistent datastore for the Laravel `sample-app` (Issue #17).
The subject mandates an **official Helm chart** for the database (not a hand-rolled
manifest), with persistent storage, secrets kept out of Git, and a tested
backup/restore procedure. It runs as a single-node StatefulSet
(`architecture: standalone`) — enough for this workload, and it keeps backups
simple.

## Design: vendored manifest
Like Cilium, Argo CD, cert-manager and Headlamp, MySQL is installed from a
manifest checked into the repo (`k8s/mysql/mysql.yaml`), rendered with
`helm template` — never `helm install` (no Helm release state on the cluster).
Every change is a reviewable diff alongside its source values
(`helm/mysql/values.yaml`).

### Regenerating the manifest
```sh
helm repo add bitnami https://charts.bitnami.com/bitnami
helm repo update bitnami
helm template mysql bitnami/mysql \
  --version 10.3.0 \
  --namespace mysql \
  --values helm/mysql/values.yaml \
  > k8s/mysql/mysql.yaml
```
Pinned chart version: **10.3.0** (app version MySQL **8.0.37**), chosen to match
the `mysql:8.0` the app's `docker-compose` expects. Bump it here and in the
command above, then commit the regenerated manifest with the values change.

> **Image registry — `bitnamilegacy`.** Bitnami moved its free Docker Hub images
> out of `docker.io/bitnami` (the pinned tag now returns `manifest unknown`).
> `helm/mysql/values.yaml` overrides `image.repository` to `bitnamilegacy/mysql`,
> which still pulls. These images are frozen and no longer receive security
> updates — **technical debt**. Production fix: mirror the image into a registry
> we control, or move to Bitnami Secure Images.

## Persistence
The chart requests a `PersistentVolumeClaim` (`primary.persistence`, 5Gi) bound to
the **`local-path`** StorageClass provisioned by
`argocd/apps/local-path-provisioner`. That StorageClass uses
`reclaimPolicy: Retain`, so the underlying volume **survives deletion of the
PVC/pod** — the persistence requirement of the issue. The StatefulSet guarantees
the pod re-attaches to the same volume across restarts.

> Production note: `local-path` stores data on the node's local disk, so the
> volume is tied to one node. The cloud-native evolution is the AWS EBS CSI
> driver (network-attached volumes), which requires IAM wiring not yet present in
> the infra.

### Resource limits
The node is small (2 vCPU / 4 GB, shared with Argo CD & co.) and the dataset is
tiny, so MySQL is capped rather than left to burst:
`requests` 250m/256Mi, `limits` 500m/512Mi. If the pod is `OOMKilled` at startup,
raise the memory limit to 768Mi–1Gi.

## Credentials (Secret, not committed)
The chart consumes an **existing** Secret (`auth.existingSecret: mysql-credentials`)
instead of generating one, so no password is ever rendered into the committed
manifest. The Secret is created out of band, once, with generated values:
```sh
kubectl create namespace mysql
kubectl create secret generic mysql-credentials -n mysql \
  --from-literal=mysql-root-password="$(openssl rand -base64 24)" \
  --from-literal=mysql-password="$(openssl rand -base64 24)"
```
The key names (`mysql-root-password`, `mysql-password`) are exactly those the
StatefulSet reads — do not rename them. The `mysql-password` is the password of
the application user `laravel` (database `laravel`); the app will be wired to
these in the app-deployment issue.

> GitOps evolution: replace the manual Secret with **Sealed Secrets** (encrypted
> object committable to Git, decrypted in-cluster), shareable with Dex's secret.

## Deployment
Managed by GitOps: the `Application` at `argocd/apps/mysql/application.yaml`
(sync-wave `0`) is picked up by the root app-of-apps and synced automatically.
The storage layer (`local-path-provisioner`, sync-wave `-1`) syncs first so the
PVC can bind. Create the Secret **before** the sync to avoid a transient
CrashLoop. No manual `kubectl apply`.

Connection from inside the cluster: host `mysql.mysql.svc.cluster.local`, port
`3306`.

## Backup / restore
> **TODO** — strategy to be implemented (Issue #17 acceptance criterion). Will
> document a tested backup and restore procedure here.