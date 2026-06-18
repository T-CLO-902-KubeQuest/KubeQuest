# KubeQuest
Epitech 10th semester cloud project. Design and deploy a fully-equipped Kubernetes cluster.

## Local kubectl access (AWS)

The Kubernetes API (port 6443) is not exposed publicly. Reach it through an SSH tunnel via the control-plane.

Resolve the control-plane public DNS from the Ansible inventory:

```bash
MASTER=$(ansible-inventory -i ansible/inventory.aws_ec2.yml --list \
  | jq -r '.masters.hosts[0]')
```

Fetch the kubeconfig and point it at the local tunnel endpoint:

```bash
ssh -i ~/.ssh/kubequest.pem -o IdentitiesOnly=yes ec2-user@"$MASTER" \
  'sudo cat /etc/kubernetes/admin.conf' > ~/.kube/kubequest-aws.yaml
chmod 600 ~/.kube/kubequest-aws.yaml
sed -i -E 's|server: https://[^[:space:]]+|server: https://127.0.0.1:6443|' \
  ~/.kube/kubequest-aws.yaml
sed -i '/server: https:\/\/127.0.0.1:6443/a\    tls-server-name: kubernetes' \
  ~/.kube/kubequest-aws.yaml
```

Open the tunnel (keep it running):

```bash
ssh -i ~/.ssh/kubequest.pem -o IdentitiesOnly=yes \
  -L 6443:127.0.0.1:6443 -N ec2-user@"$MASTER"
```

In another shell:

```bash
export KUBECONFIG=~/.kube/kubequest-aws.yaml
kubectl get nodes
```
