# Cilium CNI Role

## Overview
This Ansible role deploys [Cilium](https://cilium.io/) as the CNI on the Kubernetes cluster. Without a CNI, nodes stay `NotReady` and pod-to-pod networking is unavailable. Cilium also unlocks `NetworkPolicy` enforcement, which will be leveraged for the security hardening work.

## Design: vendored manifest
The CNI is deployed from a manifest stored in the repository (`files/cilium.yaml`), never from an ad-hoc `kubectl apply <url>` or `cilium install`. The rendered manifest is checked in alongside its source `values.yaml`, so every change to the CNI is reviewable as a diff.

### Regenerating the manifest
The manifest is produced by `helm template`, not installed with `helm install` (no Tiller/Helm state on the cluster). To upgrade Cilium or change values:

```sh
helm repo add cilium https://helm.cilium.io
helm repo update cilium
helm template cilium cilium/cilium \
  --version <CILIUM_VERSION> \
  --namespace kube-system \
  --values ansible/roles/cilium/files/values.yaml \
  > ansible/roles/cilium/files/cilium.yaml
```

Then bump `cilium_version` in `defaults/main.yml` and commit the regenerated manifest together with the values change.

## Implementation Details
The role runs on the control-plane host and:
1. Copies `files/cilium.yaml` to `/etc/kubernetes/manifests-extra/cilium.yaml` so the applied manifest is traceable on-node.
2. Runs `kubectl apply -f` against the cluster's `admin.conf` kubeconfig.
3. Waits for the `cilium` DaemonSet and the `cilium-operator` Deployment to roll out before handing over to the worker-join play.

## Why These Choices?
- **GitOps-friendly**: Since the manifest is versioned, any drift between the repo and the cluster is a bug, not a surprise. An ArgoCD/Flux migration later would be a one-file move.
- **Cohérence du podCIDR**: The `clusterPoolIPv4PodCIDRList` in `values.yaml` matches `kubernetes_pod_subnet` passed to `kubeadm init`. Drift between the two breaks pod scheduling silently, so both are kept in sync in-repo.
- **`kube-proxy` kept in place**: Replacing `kube-proxy` with Cilium requires `k8sServiceHost` pointing at the API server, which is cluster-specific. Baking a cluster-specific value into the vendored manifest would defeat the purpose. The switch can be done later with a templated manifest.
- **CNI applied before workers join**: Workers will not reach `Ready` until a CNI is present. Ordering the Cilium play between control-plane init and worker join avoids a transient `NotReady` state.
