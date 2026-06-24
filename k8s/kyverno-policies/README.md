# Kyverno policies

Validating admission policies enforced cluster-wide by the Kyverno webhook
(deployed via `argocd/apps/kyverno-policies/`). They cover the directives'
requirement for a validating webhook that **validates *and* controls** admission
requests.

## Enforcement modes

Each `ClusterPolicy` sets `validate.failureAction`:

- **`Enforce`** — the webhook **rejects** non-compliant Pods at admission.
- **`Audit`** — the webhook only **records** violations in `PolicyReport`s
  (`kubectl get polr -A`); the Pod is still admitted.

| Policy | Mode | What it checks |
|--------|------|----------------|
| `disallow-privileged` | **Enforce** | `securityContext.privileged` must be false |
| `disallow-privilege-escalation` | **Enforce** | `allowPrivilegeEscalation: false` on every container |
| `drop-all-capabilities` | **Enforce** | `capabilities.drop: [ALL]` on every container |
| `require-resource-limits` | **Enforce** | CPU + memory `limits` on every container |
| `require-run-as-non-root` | **Enforce** | pod-level `runAsNonRoot: true` |
| `disallow-latest-tag` | Audit | explicit, non-`:latest` image tag |
| `disallow-host-namespaces` | Audit | no `hostNetwork`/`hostIPC`/`hostPID` |
| `require-read-only-root-fs` | Audit | `readOnlyRootFilesystem: true` |
| `require-probes` | Audit | liveness + readiness probes |
| `require-resource-requests` | Audit | CPU + memory `requests` |

### Namespace scope of the Enforce policies

The 5 `Enforce` policies **exclude system/infra namespaces** (`kube-system`,
`kyverno`, `argocd`, `argo-rollouts`, `monitoring`, `cert-manager`,
`ingress-nginx`, `dex`, `headlamp`, `oauth2-proxy`, `local-path-storage`, …).
Those namespaces host vendored third-party workloads we do not control and
cannot make fully compliant; enforcing there would block them.

The exclusion is **fail-closed**: any namespace *not* listed stays enforced, so
application namespaces (`sample-app`, `mysql`) are protected by default — and so
is any new application namespace, unless it is explicitly added to the exclude
list.

## Demo: the admission controller blocks a non-compliant Pod

Try to schedule a privileged Pod in an **application** namespace — it is rejected:

```console
$ kubectl -n sample-app run pwn --image=nginx:1.27 \
    --overrides='{"spec":{"containers":[{"name":"pwn","image":"nginx:1.27","securityContext":{"privileged":true}}]}}' \
    --restart=Never
Error from server: admission webhook "validate.kyverno.svc-fail" denied the request:

resource Pod/sample-app/pwn was blocked due to the following policies:

disallow-privileged:
  check-privileged: 'validation error: Privileged containers are not allowed
    (securityContext.privileged must be false).'
```

The same Pod is **admitted** in an excluded system namespace (then clean up):

```console
$ kubectl -n kube-system run probe --image=nginx:1.27 \
    --overrides='{"spec":{"containers":[{"name":"probe","image":"nginx:1.27","securityContext":{"privileged":true}}]}}' \
    --restart=Never
pod/probe created

$ kubectl -n kube-system delete pod probe
```

Inspect the active enforcement state:

```console
$ kubectl get clusterpolicy
NAME                            ADMISSION   BACKGROUND   VALIDATE ACTION   READY
disallow-privileged             true        true         Enforce           True
require-run-as-non-root         true        true         Enforce           True
...
disallow-latest-tag             true        true         Audit             True
```
