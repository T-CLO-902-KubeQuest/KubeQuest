resource "azurerm_virtual_network" "kubequest" {
  name                = "vnet-kubequest-${var.group}"
  location            = azurerm_resource_group.kubequest.location
  resource_group_name = azurerm_resource_group.kubequest.name
  address_space       = ["10.42.0.0/16"]
  tags                = local.common_tags
}

resource "azurerm_subnet" "nodes" {
  name                 = "snet-nodes"
  resource_group_name  = azurerm_resource_group.kubequest.name
  virtual_network_name = azurerm_virtual_network.kubequest.name
  address_prefixes     = ["10.42.1.0/24"]
}

resource "azurerm_network_security_group" "nodes" {
  name                = "nsg-kubequest-${var.group}"
  location            = azurerm_resource_group.kubequest.location
  resource_group_name = azurerm_resource_group.kubequest.name
  tags                = local.common_tags

  security_rule {
    name                       = "allow-ssh"
    priority                   = 1000
    direction                  = "Inbound"
    access                     = "Allow"
    protocol                   = "Tcp"
    source_port_range          = "*"
    destination_port_range     = "22"
    source_address_prefix      = var.allowed_ssh_cidr
    destination_address_prefix = "*"
  }

  security_rule {
    name                       = "allow-kube-apiserver"
    priority                   = 1010
    direction                  = "Inbound"
    access                     = "Allow"
    protocol                   = "Tcp"
    source_port_range          = "*"
    destination_port_range     = "6443"
    source_address_prefix      = var.allowed_ssh_cidr
    destination_address_prefix = "*"
  }

  security_rule {
    name                       = "allow-intra-cluster"
    priority                   = 1100
    direction                  = "Inbound"
    access                     = "Allow"
    protocol                   = "*"
    source_port_range          = "*"
    destination_port_range     = "*"
    source_address_prefix      = tolist(azurerm_virtual_network.kubequest.address_space)[0]
    destination_address_prefix = "*"
  }
}

resource "azurerm_subnet_network_security_group_association" "nodes" {
  subnet_id                 = azurerm_subnet.nodes.id
  network_security_group_id = azurerm_network_security_group.nodes.id
}
