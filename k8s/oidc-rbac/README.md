# OIDC cluster RBAC

Binds cluster permissions to identities authenticated through Dex.

## Why
The kube-apiserver is wired to Dex as an OIDC token validator (Ansible role
`control-plane`, `apiserver-oidc.yml`): `--oidc-issuer-url` points at Dex,
`--oidc-client-id=headlamp`, with `--oidc-username-prefix=oidc:` and
`--oidc-groups-prefix=oidc:`. Without a binding, an SSO user authenticates but
has **no** permissions (every API call 403s).

Dex only admits the `T-CLO-902-KubeQuest` GitHub org, so each SSO user carries
the group `oidc:T-CLO-902-KubeQuest`. `cluster-view.yaml` binds that group to the
built-in **`view`** ClusterRole — read-only, consistent with Headlamp's stance.

## Scope
- Read-only only. Privileged access stays with kubeconfig / certificate
  identities, not granted through SSO.
- To promote a specific GitHub team, add a binding for its group
  (`oidc:T-CLO-902-KubeQuest:<team>`) to a stronger role.

## Deployment
GitOps: `argocd/apps/oidc-rbac/application.yaml` is synced by the root app. The
`ClusterRoleBinding` is cluster-scoped; the Application's destination namespace
(`kube-system`) is only a placeholder Argo CD requires.
