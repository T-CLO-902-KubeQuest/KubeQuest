# KubeQuest - Terraform (Azure)

Provisions the four Rocky Linux 9 VMs that mirror the AWS topology:
one control-plane (static public IP) plus three workers (static public IPs).
Tags match the AWS side (`Group`, `Role`, `Name`, `Project`).

## Usage

```sh
cp terraform.tfvars.example terraform.tfvars
# edit subscription_id at minimum

terraform init
terraform apply
```

After a successful apply, `../ansible/inventory.azure.yml` is (re)generated
with the current public IPs. Run the playbook with:

```sh
cd ../ansible
ansible-playbook -i inventory.azure.yml playbook.yml
```

## Notes

- The Rocky Linux 9 marketplace image requires accepting a plan agreement,
  handled by `azurerm_marketplace_agreement.rocky`.
- `admin_username` defaults to `rocky`, the cloud image default user.
- `allowed_ssh_cidr` defaults to `0.0.0.0/0` for convenience; tighten it
  to your public IP for anything beyond a lab.
