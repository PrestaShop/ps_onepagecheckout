{**
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *}
{extends file=$layout}
{block name='notifications'}
{/block}

{block name='breadcrumb'}
{/block}

{block name='content_columns'}
  {block name='checkout_notifications'}
    {include file='_partials/notifications.tpl'}
  {/block}
  <div
    id="js-account-created-toast"
    class="d-none"
    data-show="{$show_account_created_toast|intval|default:0}"
    data-message="{l s='Account successfully created' d='Modules.Onepagecheckout.Shop'}"
  ></div>

  <div class="columns-container container">
    <div id="center-column" class="center-column page page--full-width">
      <div class="checkout-grid row">
        <div class="checkout-grid__content col-lg-8 order-2 order-lg-1">
          {block name='express_checkout'}
            {* Express/wallet buttons above the form, mirroring the cart page slot. The wrapper
               (and its "or" separator) only renders when a module actually returns content,
               so shops without express-capable payment modules see no change. *}
            {capture name='opcExpressCheckout'}{hook h='displayExpressCheckout'}{/capture}
            {if trim($smarty.capture.opcExpressCheckout)}
              <div class="opc-express-checkout">
                <div class="opc-express-checkout__buttons">
                  {$smarty.capture.opcExpressCheckout nofilter}
                </div>
                <div class="opc-express-checkout__separator" aria-hidden="true">
                  <span class="opc-express-checkout__separator-label">{l s='or' d='Modules.Onepagecheckout.Shop'}</span>
                </div>
              </div>
            {/if}
          {/block}
          <div class="tab-content">
            {block name='checkout_process'}
              {render file='checkout/checkout-process.tpl' ui=$checkout_process}
            {/block}
          </div>
        </div>

        <div class="checkout-grid__aside col-lg-4 order-1 order-lg-2">
          <div class="checkout-grid__aside-wrapper">
            <div class="checkout__summary-accordion accordion">
              <div class="checkout__summary-accordion-item accordion-item">
                <div class="checkout__summary-accordion-header accordion-header">
                  <button class="accordion-button" type="button" data-bs-target="#js-checkout-summary" data-bs-toggle="collapse" aria-expanded="true">
                    {l s='Order summary' d='Modules.Onepagecheckout.Shop'}
                  </button>
                </div>

                {block name='cart_summary'}
                  <div class="checkout__summary-accordion-wrapper cart-summary js-checkout-summary">
                    {include file='checkout/_partials/cart-summary.tpl' cart=$cart}
                  </div>
                {/block}
              </div>
            </div>

            {hook h='displayReassurance'}
          </div>
        </div>
      </div>
    </div>
  </div>

  {include file='checkout/_partials/modal-terms.tpl'}
{/block}
