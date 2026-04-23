# Kubernetes Worker Join Role

## Overview
This Ansible role automates the process of adding worker nodes to an existing Kubernetes cluster. It is specifically designed to handle the joining of `kube-2` (and future workers) to the cluster initialized by `kube-1`.

## Technologies Used
- **Ansible**: Used for configuration management and orchestration.
- **Kubernetes (kubeadm)**: The standard tool for managing Kubernetes cluster lifecycles.
- **YAML**: The language used for defining Ansible tasks and playbooks.

## Implementation Details
The role performs three main steps:
1. **Idempotency Check**: It verifies if the file `/etc/kubernetes/kubelet.conf` exists. If it does, the node is considered already joined, and the join process is skipped.
2. **Dynamic Token Retrieval**: Instead of hardcoding tokens, the role uses `delegate_to` to run a command on the `control_plane` node (`kube-1`). It generates a fresh join command using `kubeadm token create --print-join-command`.
3. **Cluster Join**: The worker node executes the dynamically retrieved command to join the cluster.

## Why These Choices?
- **Security**: By retrieving the token dynamically in memory, we avoid storing sensitive information like join tokens or CA hashes in the source code or on the disk of the worker nodes.
- **Idempotency**: Using the `stat` module ensures that the `kubeadm join` command is not executed multiple times on a node that is already part of the cluster, which prevents errors and unnecessary operations.
- **Scalability**: The use of `run_once: true` and `delegate_to` allows the role to scale efficiently. Even if we add dozens of workers, the join command is only generated once on the master and then distributed to all relevant workers.
- **Flexibility**: The role depends on Ansible groups (`control_plane` and `workers`), making it compatible with any environment (AWS, on-premise, etc.) as long as the inventory is correctly defined.
