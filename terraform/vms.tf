resource "azurerm_public_ip" "master" {
  for_each = local.masters

  name                = "pip-${each.key}"
  location            = azurerm_resource_group.kubequest.location
  resource_group_name = azurerm_resource_group.kubequest.name
  allocation_method   = "Static"
  sku                 = "Standard"
  tags                = merge(local.common_tags, { Role = each.value.role, Name = each.key })
}

resource "azurerm_network_interface" "node" {
  for_each = local.nodes

  name                = "nic-${each.key}"
  location            = azurerm_resource_group.kubequest.location
  resource_group_name = azurerm_resource_group.kubequest.name
  tags                = merge(local.common_tags, { Role = each.value.role, Name = each.key })

  ip_configuration {
    name                          = "ipconfig1"
    subnet_id                     = azurerm_subnet.nodes.id
    private_ip_address_allocation = "Dynamic"
    public_ip_address_id          = each.value.role == "control-plane" ? azurerm_public_ip.master[each.key].id : null
  }
}

resource "azurerm_marketplace_agreement" "rocky" {
  publisher = var.rocky_plan.publisher
  offer     = var.rocky_plan.product
  plan      = var.rocky_plan.name
}

resource "azurerm_linux_virtual_machine" "node" {
  for_each = local.nodes

  name                            = each.key
  computer_name                   = each.key
  location                        = azurerm_resource_group.kubequest.location
  resource_group_name             = azurerm_resource_group.kubequest.name
  size                            = var.vm_size
  admin_username                  = var.admin_username
  disable_password_authentication = true
  network_interface_ids           = [azurerm_network_interface.node[each.key].id]

  admin_ssh_key {
    username   = var.admin_username
    public_key = file(pathexpand(var.ssh_public_key_path))
  }

  os_disk {
    name                 = "osdisk-${each.key}"
    caching              = "ReadWrite"
    storage_account_type = "StandardSSD_LRS"
  }

  source_image_reference {
    publisher = var.rocky_image.publisher
    offer     = var.rocky_image.offer
    sku       = var.rocky_image.sku
    version   = var.rocky_image.version
  }

  plan {
    name      = var.rocky_plan.name
    product   = var.rocky_plan.product
    publisher = var.rocky_plan.publisher
  }

  tags = merge(
    local.common_tags,
    {
      Role = each.value.role
      Name = each.key
    },
  )

  depends_on = [azurerm_marketplace_agreement.rocky]
}
