{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

<div
  id="{$modal_id}"
  class="modal fade"
  tabindex="-1"
  role="dialog"
  aria-labelledby="address-modal-title"
  aria-hidden="true"
  data-title-new="{$title_new}"
  data-title-edit="{$title_edit}"
>
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
    <div class="modal-content address-form-container">
      <div class="modal-header pb-2">
        <h2 class="mb-0">{$title_new}</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <hr>
      <div class="modal-body">
        <input type="hidden" name="id_address" value="">
        <input type="hidden" name="token" value="{$token}">
        <input type="hidden" name="address_type" value="{$address_type}">
        {* Country-dependent fields, swapped in place by opc-address-modal.js on country change. *}
        <div class="js-opc-address-modal-fields">
          {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-modal-fields.tpl'
            formFields=$formFields
            prefix=$prefix
            modal_id=$modal_id}
        </div>
      </div>
      <div class="modal-footer">
        <button
          id="{$modal_id}-submit-address-modal"
          type="button"
          class="btn btn-primary js-opc-save-address"
        >
          <span class="spinner-border spinner-border-sm me-2 d-none" aria-hidden="true" data-opc-address-save-spinner></span>
          {l s='Save' d='Modules.Onepagecheckout.Shop'}
        </button>
      </div>
    </div>
  </div>
</div>
