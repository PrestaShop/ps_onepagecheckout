{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

<div
  id="opc-delete-address-confirm-modal"
  class="modal fade"
  tabindex="-1"
  role="dialog"
  aria-labelledby="opc-delete-address-confirm-title"
  aria-hidden="true"
>
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h2 id="opc-delete-address-confirm-title" class="mb-0">
          {l s='Delete this address?' d='Modules.Onepagecheckout.Shop'}
        </h2>
        <button
          type="button"
          class="btn-close"
          data-bs-dismiss="modal"
          aria-label="{l s='Close' d='Modules.Onepagecheckout.Shop'}"
        ></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">
          {l s='This action will remove the selected address from your checkout.' d='Modules.Onepagecheckout.Shop'}
        </p>
      </div>
      <div class="modal-footer">
        <button
          type="button"
          class="btn btn-outline-primary js-opc-delete-address-cancel"
          data-bs-dismiss="modal"
        >
          {l s='Cancel' d='Modules.Onepagecheckout.Shop'}
        </button>
        <button
          type="button"
          class="btn btn-outline-danger js-opc-delete-address-confirm"
        >
          {l s='Delete' d='Modules.Onepagecheckout.Shop'}
        </button>
      </div>
    </div>
  </div>
</div>
