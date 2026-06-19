# 0002 - Durcissement de la NetworkPolicy et des backups MySQL

- Statut : Accepted
- Date : 2026-06-19
- Décideurs : équipe KubeQuest
- PR : [#69](https://github.com/T-CLO-902-KubeQuest/KubeQuest/pull/69),
  [#75](https://github.com/T-CLO-902-KubeQuest/KubeQuest/pull/75) (resserrement ingress)

## Contexte

Le déploiement MySQL (#60) installe le chart Helm Bitnami `mysql` (rendu en
manifest vendoré, `k8s/mysql/mysql.yaml`) plus un dispositif de backup logique
par `CronJob` (`k8s/mysql/backup.yaml`). La review post-merge a relevé trois
écarts de robustesse/sécurité :

- La `NetworkPolicy` rendue par défaut autorisait **tout l'egress**
  (`egress: - {}`), alors qu'une base de données n'a aucune raison d'initier des
  connexions sortantes arbitraires.
- Les `CronJob` de backup et de purge n'avaient ni `securityContext`, ni
  `resources`, ni garde-fous d'exécution (concurrence, rétention d'historique),
  contrairement au `StatefulSet` MySQL déjà durci.
- Le backup écrivait directement dans le fichier `.sql` final ; un dump
  interrompu laissait un fichier partiel que la procédure de restore
  (`ls -t | head -1`) aurait sélectionné comme « dernier dump valide ».

## Décision

### NetworkPolicy

Restreindre l'egress via les valeurs Helm (et non en éditant le manifest rendu,
qui reste régénérable) : `networkPolicy.allowExternalEgress: false` dans
`helm/mysql/values.yaml`. Le chart génère alors un egress limité à la résolution
**DNS** (port 53) et au **trafic intra-cluster**, au lieu d'autoriser toutes les
destinations.

Resserrer l'ingress (PR #75) : `networkPolicy.allowExternal: false` retire la
règle port-only ouverte à tous les pods, et une règle `networkPolicy.extraIngress`
n'autorise le port 3306 que depuis les pods `sample-app`. Comme la policy vit dans
le namespace `mysql`, la règle combine dans un **même peer** un `namespaceSelector`
(`kubernetes.io/metadata.name: sample-app`, le label posé automatiquement par
Kubernetes) **et** un `podSelector` (`app.kubernetes.io/name: sample-app`) — un AND,
donc seuls les pods `sample-app` de ce namespace correspondent. Le resserrement n'a
été fait qu'une fois `sample-app` déployé (#62, #65), son label de pod étant alors
connu.

### Backups

Aligner les deux `CronJob` (`mysql-backup`, `mysql-backup-cleanup`) sur le
durcissement du `StatefulSet` :

- `securityContext` : `runAsNonRoot`, `runAsUser/Group: 1001`,
  `allowPrivilegeEscalation: false`, `capabilities.drop: [ALL]`,
  `seccompProfile: RuntimeDefault`.
- `resources` (requests/limits) bornées.
- Garde-fous d'exécution : `concurrencyPolicy: Forbid`,
  `successfulJobsHistoryLimit`/`failedJobsHistoryLimit: 3`,
  `startingDeadlineSeconds: 300`.

Rendre le backup **atomique** : écrire le dump dans un fichier `.tmp` puis le
renommer (`mv`) uniquement en cas de succès, pour qu'un dump interrompu ne laisse
jamais de `.sql` partiel exploitable par le restore.

## Alternatives envisagées

- **Resserrer l'ingress par label dès #69** : reporté tant que `sample-app`
  n'était pas déployé — on aurait bloqué un consommateur dont le label était
  inconnu. Fait depuis dans #75, une fois l'application déployée.
- **Éditer directement le bloc `NetworkPolicy` dans `mysql.yaml`** : rejeté, le
  manifest est rendu par `helm template` et doit rester régénérable (cf.
  `k8s/mysql/README.md`). Le changement passe donc par `values.yaml`.

## Conséquences

### Positives

- Surface réseau réduite : MySQL ne peut plus initier d'egress arbitraire.
- Pods de backup non privilégiés et bornés en ressources, cohérents avec le
  reste du déploiement.
- Plus de risque de restaurer un dump tronqué.

### Négatives / points d'attention

- L'ingress n'autorise plus que les pods `sample-app` : tout nouveau consommateur
  de MySQL devra être ajouté explicitement à `networkPolicy.extraIngress`.
- Toute régénération future de `mysql.yaml` doit conserver le bloc
  `networkPolicy` dans `values.yaml`, sinon egress et ingress redeviennent
  permissifs.
