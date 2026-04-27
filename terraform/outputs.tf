output "master_public_ips" {
  description = "Static public IPs of the control-plane nodes"
  value       = { for k, v in azurerm_public_ip.master : k => v.ip_address }
}

output "worker_private_ips" {
  description = "Private IPs of the worker nodes (reachable through the master as SSH bastion)"
  value       = { for k, v in azurerm_network_interface.node : k => v.private_ip_address if local.nodes[k].role == "worker" }
}

output "resource_group" {
  description = "Azure resource group holding the cluster"
  value       = azurerm_resource_group.kubequest.name
}
