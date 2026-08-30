{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{* Delivery Address Modal *}
{include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-modal.tpl'
modal_id='modal-delivery'
formFields=$deliveryFields
fieldRows=$deliveryFieldRows
title_new={l s='New delivery address' d='Modules.Onepagecheckout.Shop'}
title_edit={l s='Edit delivery address' d='Modules.Onepagecheckout.Shop'}
address_type='delivery'
prefix=''
}

{* Billing Address Modal *}
{include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-modal.tpl'
modal_id='modal-invoice'
formFields=$invoiceFields
fieldRows=$invoiceFieldRows
title_new={l s='New billing address' d='Modules.Onepagecheckout.Shop'}
title_edit={l s='Edit billing address' d='Modules.Onepagecheckout.Shop'}
address_type='invoice'
prefix='invoice_'
}

{include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/delete-address-modal.tpl'}

{assign var="_addresses_count" value=$customer.addresses|count}
{assign var="_delivery_selected_address" value=$deliveryFields.id_address_delivery.value|default:($cart.id_address_delivery|default:0)}
{assign var="_billing_selected_address" value=$invoiceMetaFields.id_address_invoice.value|default:($cart.id_address_invoice|default:0)}

<template id="opc-delivery-address-loader">
  {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/opc-loader.tpl'
    message={l s='Loading addresses...' d='Modules.Onepagecheckout.Shop'}
  }
</template>

<template id="opc-delivery-address-error">
  {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/opc-error.tpl'
    message={l s='An error occurred while loading addresses. Please try again.' d='Modules.Onepagecheckout.Shop'}
    retry_label={l s='Retry' d='Modules.Onepagecheckout.Shop'}
    retry_action='retry-addresses'
  }
</template>

<template id="opc-billing-address-loader">
  {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/opc-loader.tpl'
    message={l s='Loading addresses...' d='Modules.Onepagecheckout.Shop'}
  }
</template>

<template id="opc-billing-address-error">
  {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/opc-error.tpl'
    message={l s='An error occurred while loading addresses. Please try again.' d='Modules.Onepagecheckout.Shop'}
    retry_label={l s='Retry' d='Modules.Onepagecheckout.Shop'}
    retry_action='retry-addresses'
  }
</template>

<section class="one-page-checkout__section">
  <h2 class="one-page-checkout__title">
    {if $is_virtual_cart}
      {l s='Billing address' d='Modules.Onepagecheckout.Shop'}
    {else}
      {l s='Delivery address' d='Modules.Onepagecheckout.Shop'}
    {/if}
  </h2>

  <section id="opc-delivery-address" class="form-fields">
    <div id="opc-delivery-address-content">
        <div id="opc-delivery-address-content-list" class="{if $_addresses_count <= 0}d-none{/if}">
          {if $_addresses_count > 0}
          {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-list.tpl'
            prefix=''
            selected_address=$_delivery_selected_address
          }
          {/if}
        </div>

      <div id="opc-delivery-address-fields" class="{if $_addresses_count > 0}d-none{/if}">
        {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-fields.tpl'
          formFields=$deliveryFields
          fieldRows=$deliveryFieldRows
          prefix=''
        }
      </div>
    </div>

    {if !$is_virtual_cart}
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="opc-use-same-address" name="use_same_address" value="1" {if $useSameAddressField.value}checked{/if}>
        <label class="form-check-label" for="opc-use-same-address">
          {l s='Use this address for invoice too' d='Modules.Onepagecheckout.Shop'}
        </label>
      </div>
    {/if}
  </section>
</section>

{if !$is_virtual_cart}
<section class="one-page-checkout__section" id="opc-billing-section" style="display: none;">
  <h2 class="one-page-checkout__title">{l s='Billing address' d='Modules.Onepagecheckout.Shop'}</h2>

  <section class="form-fields">
    <div id="opc-billing-address-content">
        <div id="opc-billing-address-content-list" class="{if $_addresses_count <= 1}d-none{/if}">
          {if $_addresses_count > 1}
          {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-list.tpl'
            prefix='invoice_'
            selected_address=$_billing_selected_address
          }
          {/if}
        </div>

      <div id="opc-billing-address-fields" class="{if $_addresses_count > 1}d-none{/if}">
        {if isset($invoiceMetaFields.id_address_invoice)}
          <input type="hidden" name="{$invoiceMetaFields.id_address_invoice.name}" value="{$invoiceMetaFields.id_address_invoice.value}">
        {/if}

        {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-fields.tpl'
          formFields=$invoiceFields
          fieldRows=$invoiceFieldRows
          prefix='invoice_'
        }
      </div>
    </div>
  </section>
</section>
{/if}

{capture name="address_selector_bottom"}{hook h='displayAddressSelectorBottom'}{/capture}
{if $smarty.capture.address_selector_bottom}
  {block name='address_selector_bottom'}
    <div class="address-selector-bottom mt-3">
      {$smarty.capture.address_selector_bottom nofilter}
    </div>
  {/block}
{/if}
