# OIDC cluster RBAC

Binds cluster permissions to identities authenticated through Dex.

## Why
The kube-apiserver is wired to Dex as an OIDC token validator (Ansible role
`control-plane`, `apiserver-oidc.yml`): `--oidc-issuer-url` points at Dex,
`--oidc-client-id=headlamp`, `--oidc-username-claim=email` and
`--oidc-username-prefix=oidc:`. Without a binding, an SSO user authenticates but
has **no** permissions (every API call 403s).

## Binding
`cluster-admin.yaml` binds named users (`oidc:<email>`) to the built-in
**`cluster-admin`** ClusterRole — full access.

We bind by **username, not group**: the GitHub connector lists `orgs` without
`teams`, so Dex does not reliably emit the org name in the `groups` claim, and a
group binding (`oidc:T-CLO-902-KubeQuest`) would match nobody. The cost is that
each member must be added by hand to the `subjects` list.

## Scope / trade-off
This grant is intentionally broad (cluster-admin). To tighten it later:
- configure Dex to emit org/team groups (`loadAllGroups` or `orgs[].teams`),
- then switch to a group binding (e.g. `oidc:T-CLO-902-KubeQuest:<team>`) against
  a narrower role.

## Deployment
GitOps: `argocd/apps/oidc-rbac/application.yaml` is synced by the root app. The
`ClusterRoleBinding` is cluster-scoped; the Application's destination namespace
(`kube-system`) is only a placeholder Argo CD requires.
