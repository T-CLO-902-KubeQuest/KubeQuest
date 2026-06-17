# ingress-nginx Controller

## Overview
[ingress-nginx](https://kubernetes.github.io/ingress-nginx/) is the HTTP/HTTPS
entrypoint for the cluster. Workloads expose themselves with a standard
`Ingress` object; the controller terminates traffic and routes it to the
backing services. It is the simplest fit for this cluster's need — exposing a
handful of HTTP services — without the extra dataplane that a Gateway API
implementation would add on top of the existing Cilium + Envoy stack.

## Design: vendored manifest
Like the Cilium CNI and Argo CD, the controller is installed from a manifest
checked into the repo (`k8s/ingress-nginx/ingress-nginx.yaml`), rendered with
`helm template` — never `helm install` (no Helm release state on the cluster).
Every change is a reviewable diff alongside its source values
(`helm/ingress-nginx/values.yaml`).

### Regenerating the manifest
```sh
helm repo add ingress-nginx https://kubernetes.github.io/ingress-nginx
helm repo update ingress-nginx
helm template ingress-nginx ingress-nginx/ingress-nginx \
  --version <CHART_VERSION> \
  --namespace ingress-nginx \
  --values helm/ingress-nginx/values.yaml \
  > k8s/ingress-nginx/ingress-nginx.yaml
```
Pinned chart version: **4.15.1** (app version 1.15.1). Bump it here and in the
command above, then commit the regenerated manifest with the values change.

## Exposure: hostNetwork on the control-plane
There is no cloud LoadBalancer on this cluster, and only the control-plane node
has a fixed Elastic IP. So the controller runs with `hostNetwork: true`, pinned
to the control-plane (`nodeSelector` + a toleration for its `NoSchedule` taint),
and binds the host's `:80`/`:443` directly. This is cleaner than a NodePort
(no high port to remember, no extra SG rule for a 30000+ port) — but the AWS
security group must allow inbound `80`/`443` on the Elastic IP.

`dnsPolicy` is set to `ClusterFirstWithHostNet` so the pod still resolves
cluster DNS despite sharing the host network namespace. The `Service` is kept as
`ClusterIP` (not LoadBalancer): real traffic flows through hostNetwork, the
Service is just an internal abstraction.

### Resource limits
The control-plane is the node we must keep from OOMing, so the controller is
capped (`requests` 100m/128Mi, `limits` 500m/256Mi) rather than left to burst.

## Deployment
Managed by GitOps: the `Application` at
`argocd/apps/ingress-nginx/application.yaml` is picked up by the root
app-of-apps and synced automatically. No manual `kubectl apply`.

The `nginx` IngressClass is marked default, so an `Ingress` without an explicit
`ingressClassName` still resolves to this controller.

## Exposing a service
```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: example
spec:
  rules:
    - host: example.<elastic-ip>.nip.io
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: example
                port:
                  number: 80
```

## Why not Gateway API?
The current need is plain HTTP/HTTPS routing to a few services — Ingress covers
it fully. Cilium's Gateway API would have required enabling
`kubeProxyReplacement` (and the cluster-specific `k8sServiceHost` the vendored
Cilium manifest deliberately avoids); NGINX Gateway Fabric would have added a
second dataplane on the control-plane we are trying to keep light. Ingress
touches neither: the Cilium manifest stays untouched.
