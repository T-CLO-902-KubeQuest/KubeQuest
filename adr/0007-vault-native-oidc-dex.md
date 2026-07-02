# 0007 - Login OIDC natif Dex pour l'UI Vault

- Statut : Accepted
- Date : 2026-07-02
- Décideurs : équipe KubeQuest
- PR : à compléter à l'ouverture

## Contexte

L'ADR [0005](0005-vault-secrets-management.md) avait retenu le forward-auth
oauth2-proxy pour protéger l'UI Vault, au motif que « l'UI Vault OSS n'a pas
d'OIDC natif simple ». **Cette affirmation était erronée** : Vault OSS possède
une auth method OIDC (`auth/oidc`) pleinement intégrée à son UI (bouton de
login OIDC avec popup) et à sa CLI (`vault login -method=oidc`).

Le premier test réel du forward-auth a d'ailleurs révélé deux défauts
latents du dispositif oauth2-proxy, jamais exercé jusque-là : une annotation
`auth-signin` documentée sans `$scheme://$host` (l'utilisateur restait bloqué
sur `auth.*` après login) et **l'absence d'enregistrement DNS public** pour
`auth.kubequest.epitech.beer` (certificat en échec depuis 13 jours).

Enfin, le forward-auth ne faisait que filtrer l'accès réseau : l'utilisateur
devait ensuite se connecter à Vault par token — deux authentifications pour
une seule session utile.

## Décision

Brancher Vault **directement sur Dex** via son auth method OIDC native, sur
le modèle de Grafana (`generic_oauth`) :

- `staticClient` `vault` dans `helm/dex/values.yaml` (secret via `secretEnv`
  → clé `vault` du Secret `dex-clients`, créé à la main), redirect URIs : le
  callback UI (`/ui/vault/auth/oidc/oidc/callback`) et le callback CLI local
  (`http://localhost:8250/oidc/callback`).
- Retrait des annotations forward-auth de `k8s/vault/ingress.yaml` : la page
  de login Vault devient publique, comme celle de Grafana.
- Auth method `oidc` configurée avec `default_role=admin` : tout membre de
  l'org GitHub `T-CLO-902-KubeQuest` obtient la policy **admin**
  (`path "*"`, toutes capabilities dont `sudo`).

## Alternatives envisagées

- **Garder les deux couches** (forward-auth + OIDC natif) : rejeté — double
  login au premier accès pour un gain marginal, et incohérent avec le
  précédent Grafana (OIDC natif seul).
- **Rester sur oauth2-proxy seul** (login Vault par token) : rejeté — deux
  authentifications hétérogènes, et l'UI par token incite à faire circuler
  le root token.
- **Policy en lecture seule pour les utilisateurs OIDC** : écarté par choix
  d'équipe pour la soutenance — l'administration de Vault via l'UI SSO prime ;
  le trade-off est documenté ci-dessous.

## Conséquences

### Positives

- Un seul login GitHub donne une session Vault avec de vraies policies —
  plus besoin de faire circuler le root token pour les opérations courantes.
- Cohérence avec le reste du cluster : Grafana, Argo CD et Headlamp parlent
  déjà OIDC nativement à Dex ; Vault rejoint ce modèle.
- Les défauts latents du dispositif oauth2-proxy (annotation `rd`, DNS
  `auth.*`) ont été corrigés au passage pour ses futurs consommateurs.

### Négatives / points d'attention

- **Tout membre de l'org GitHub est admin Vault** : la frontière d'accès est
  l'appartenance à l'org, pas un rôle par équipe. Resserrement futur possible
  par claim `groups` de Dex (réputé peu fiable ici, cf.
  `k8s/oidc-rbac/README.md`) ou par des rôles OIDC distincts.
- La page de login Vault est exposée publiquement (surface identique à
  Grafana : page de login + endpoints non authentifiés `sys/health`).
- oauth2-proxy retombe à zéro consommateur : dispositif prêt mais inutilisé
  (comme avant Vault), à réévaluer quand Prometheus/Alertmanager seront
  exposés.
