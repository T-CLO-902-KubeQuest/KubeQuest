locals {
  master_public_ips = { for k, v in azurerm_public_ip.master : k => v.ip_address }
  worker_private_ips = {
    for k, v in azurerm_network_interface.node : k => v.private_ip_address
    if local.nodes[k].role == "worker"
  }
  bastion_ip = values(local.master_public_ips)[0]
}

resource "local_file" "ansible_inventory" {
  filename        = "${path.module}/../ansible/inventory.azure.yml"
  file_permission = "0644"

  content = templatefile("${path.module}/templates/inventory.tmpl", {
    admin_username       = var.admin_username
    ssh_private_key_path = var.ssh_private_key_path
    masters              = local.master_public_ips
    workers              = local.worker_private_ips
    bastion_ip           = local.bastion_ip
  })
}
