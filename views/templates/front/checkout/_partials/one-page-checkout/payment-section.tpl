{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{**
 * OPC — Payment method section
 * Included by one-page-checkout.tpl.
 * Also renderable standalone for AJAX refresh.
 *
 * Variables:
 *   $payment_options          - available payment options (may be empty on first load)
 *   $is_free                  - boolean, true when cart total is 0
 *   $selected_payment_module  - legacy fallback module_name of the currently selected payment option
 *   $selected_payment_selection_key - stable OPC selection key for the currently selected payment option
 *}

<section class="one-page-checkout__section js-opc-payment-section">
  <h2 class="one-page-checkout__title">{l s='Payment method' d='Modules.Onepagecheckout.Shop'}</h2>

  {hook h='displayPaymentTop'}

  <div class="opc-payment-methods-wrapper position-relative">
    <div class="one-page-checkout__placeholder" id="opc-payment-methods"
      data-url-update="{url entity='order' params=['ajax' => 1, 'action' => 'opcSelectPayment']}"
    >
      {if $payment_options|count}
        {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/payment-methods.tpl'
          payment_options=$payment_options
          is_free=$is_free
          selected_payment_module=$selected_payment_module
          selected_payment_selection_key=$selected_payment_selection_key|default:''
        }
      {else}
        <div class="card card-body bg-light">
          {l s='You will see the available payment methods once you\'ve entered your delivery address.' d='Modules.Onepagecheckout.Shop'}
        </div>
      {/if}
    </div>

    <div id="opc-payment-methods-loader"
      class="opc-payment-methods-loader position-absolute top-0 start-0 end-0 bottom-0 bg-white d-none"
      aria-hidden="true"
    >
      {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/opc-loader.tpl'
        message={l s='Loading payment methods...' d='Modules.Onepagecheckout.Shop'}
      }
    </div>
  </div>

  <template id="opc-template-payment-error">
    {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/opc-error.tpl'
      message={l s='An error occurred while loading payment methods. Please try again.' d='Modules.Onepagecheckout.Shop'}
      retry_label={l s='Retry' d='Modules.Onepagecheckout.Shop'}
      retry_action='retry-payment'
    }
  </template>
</section>
