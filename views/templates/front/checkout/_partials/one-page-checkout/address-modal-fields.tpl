{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *
 * Address modal fields, rebuilt per country. Rendered both on initial page load
 * and by the `addressmodal` AJAX endpoint when the selected country changes.
 *
 * The field list, its order and its row grouping are shared with the inline form: the modal only
 * namespaces element ids per modal, and keeps an inert state select for countries whose address
 * format has none.
 *}
<div class="row">
  {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-fields.tpl'
    formFields=$formFields
    prefix=$prefix
    id_prefix="{$modal_id}-"
    state_placeholder=true
  }
</div>
