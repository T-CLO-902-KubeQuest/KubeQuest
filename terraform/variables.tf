variable "subscription_id" {
  description = "Azure subscription ID (from Azure for Students)"
  type        = string
}

variable "location" {
  description = "Azure region for all resources"
  type        = string
  default     = "westeurope"
}

variable "group" {
  description = "Project group identifier, replicated as the Group tag"
  type        = string
  default     = "group-41"
}

variable "vm_size" {
  description = "Azure VM size for every node"
  type        = string
  default     = "Standard_B2s"
}

variable "admin_username" {
  description = "Admin user created on each VM (matches the Rocky cloud image default)"
  type        = string
  default     = "rocky"
}

variable "ssh_public_key_path" {
  description = "Path to the SSH public key injected on every VM"
  type        = string
  default     = "~/.ssh/id_rsa.pub"
}

variable "ssh_private_key_path" {
  description = "Path to the SSH private key used by Ansible (written into the inventory)"
  type        = string
  default     = "~/.ssh/id_rsa"
}

variable "allowed_ssh_cidr" {
  description = "CIDR allowed to reach SSH (22/tcp) and the Kubernetes API (6443/tcp) from the internet"
  type        = string
  default     = "0.0.0.0/0"
}

variable "rocky_image" {
  description = "Rocky Linux 9 marketplace image reference"
  type = object({
    publisher = string
    offer     = string
    sku       = string
    version   = string
  })
  default = {
    publisher = "resf"
    offer     = "rockylinux-x86_64"
    sku       = "9-base"
    version   = "latest"
  }
}

variable "rocky_plan" {
  description = "Marketplace plan required to deploy the Rocky Linux image"
  type = object({
    name      = string
    product   = string
    publisher = string
  })
  default = {
    name      = "9-base"
    product   = "rockylinux-x86_64"
    publisher = "resf"
  }
}
