# KubeQuest - Ansible Playbook

[Link - Understanding Ansibles's check_mode (EN)](https://medium.com/opsops/understanding-ansibles-check-mode-299fd8a6a532)

## Facts

Les **facts** Ansible, c'est le rapport de reconnaissance qu'Ansible établit sur chaque machine avant de commencer à travailler. Avant d'exécuter la moindre tâche, Ansible se connecte à chaque hôte cible et lui pose une série de questions : "Tu es sous quelle distribution ? Quelle version du kernel ? Combien de CPU ? Quelles interfaces réseau ? Quelle architecture ? Quel hostname ?" Les réponses sont stockées dans un énorme dictionnaire de variables que tu peux ensuite utiliser dans tes playbooks.

Concrètement, quand tu lances un playbook, la toute première chose qui se passe (sauf si tu la désactives) est une tâche invisible appelée `Gathering Facts`. C'est le module `setup` qui tourne silencieusement sur chaque hôte. Tu l'as sûrement déjà vu passer dans ta sortie Ansible sans forcément y prêter attention.

Pour voir à quoi ressemblent ces facts sur tes nœuds Kubequest, tu peux lancer directement :

```bash
ansible node-1 -m setup
```

Et tu vas recevoir plusieurs centaines de variables. Quelques-unes parmi les plus utiles dans ton contexte :

| Fact | Ce que ça te donne |
|------|-------------------|
| `ansible_distribution` | `Ubuntu`, `Debian`, `Amazon`, etc. |
| `ansible_distribution_version` | `22.04`, `24.04`... |
| `ansible_kernel` | Version du kernel (utile pour Calico/Cilium) |
| `ansible_architecture` | `x86_64`, `aarch64` |
| `ansible_default_ipv4.address` | L'IP principale du nœud |
| `ansible_hostname` | Le hostname court |
| `ansible_processor_vcpus` | Nombre de vCPUs |
| `ansible_memtotal_mb` | RAM totale |

Tu les utilises dans tes tâches avec la syntaxe `{{ ansible_distribution }}`, ou en conditionnel avec `when: ansible_distribution == "Ubuntu"`.

**Pourquoi c'est crucial pour Kubequest spécifiquement :**

Les instances EC2 changent de DNS à chaque redémarrage, mais les facts, eux, restent cohérents (distribution, kernel, architecture). Ça te permet d'écrire des rôles **portables** qui ne dépendent pas du nom de la machine mais de ses caractéristiques réelles. Exemple typique dans ton rôle `containerd` :

```yaml
- name: Install containerd (Ubuntu/Debian)
  apt:
    name: containerd.io
    state: present
  when: ansible_os_family == "Debian"

- name: Install containerd (RHEL/Amazon Linux)
  yum:
    name: containerd.io
    state: present
  when: ansible_os_family == "RedHat"
```

Le même rôle fonctionne sur n'importe quelle distribution — c'est la beauté de la chose.

**Trois choses à connaître sur les facts que les débutants ignorent :**

D'abord, la **collecte coûte cher**. Gathering facts prend plusieurs secondes par hôte. Sur 4 nœuds, ça va. Sur 400, ça devient un problème. Tu peux désactiver la collecte avec `gather_facts: no` dans ton play, ou la rendre plus ciblée avec `gather_subset: ['!all', 'network']`.

Ensuite, il existe les **custom facts**. Tu peux déposer des scripts ou des fichiers INI dans `/etc/ansible/facts.d/` sur un hôte, et Ansible les remontera automatiquement sous `ansible_local.*`. Utile pour exposer des infos spécifiques à ton cluster (rôle du nœud, version de composants installés, etc.) que tu veux ensuite consommer depuis tes playbooks.

Enfin, les **set_fact**. Tu peux créer tes propres facts à la volée pendant l'exécution d'un playbook avec le module `set_fact`. C'est différent des facts système : ce sont des variables que *tu* définis et qui persistent pour le reste du play. Typique quand tu calcules quelque chose (genre l'IP du control-plane à partir d'une requête dynamique) et que tu veux le réutiliser plus tard.
