terraform {
  required_version = ">= 1.6.0"

  required_providers {
    azurerm = {
      source  = "hashicorp/azurerm"
      version = "~> 4.0"
    }
  }
}

provider "azurerm" {
  features {}
  subscription_id = var.subscription_id

  resource_provider_registrations = "core"
  resource_providers_to_register = [
    "Microsoft.Compute",
    "Microsoft.Network",
    "Microsoft.Storage",
    "Microsoft.MarketplaceOrdering",
  ]
}

locals {
  common_tags = {
    Group   = var.group
    Project = "KubeQuest"
  }

  masters = {
    "master-1" = { role = "control-plane" }
  }

  workers = {
    "worker-1" = { role = "worker" }
    "worker-2" = { role = "worker" }
  }

  nodes = merge(local.masters, local.workers)
}

resource "azurerm_resource_group" "kubequest" {
  name     = "rg-kubequest-${var.group}"
  location = var.location
  tags     = local.common_tags
}
