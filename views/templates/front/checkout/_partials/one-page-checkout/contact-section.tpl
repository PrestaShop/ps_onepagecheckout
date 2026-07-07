{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{hook h='displayPersonalInformationTop' customer=$customer}

{$isGuestCheckoutEnabled = $configuration.is_guest_checkout_enabled}

{if !$customer.is_logged}
  <section class="one-page-checkout__section">
    <h2 class="one-page-checkout__title">{l s='Contact information' d='Modules.Onepagecheckout.Shop'}</h2>

    <div class="one-page-checkout__links">
      <a class="one-page-checkout__link" href="{$opc_urls.authentication}">
        {l s='Already have an account? Sign in' d='Modules.Onepagecheckout.Shop'}
      </a>
      <a class="one-page-checkout__link" href="{$opc_urls.registration}">
        {l s='Create account' d='Modules.Onepagecheckout.Shop'}
      </a>
    </div>

    {if $isGuestCheckoutEnabled}
      {if isset($contactFields['email'])}
        <p class="one-page-checkout__guest-cta">{l s='Continue as guest' d='Modules.Onepagecheckout.Shop'}</p>
        <div class="one-page-checkout__field">
          {* The label must NAME the field like every other form field (SPE-151): the guest CTA above
             is a funnel choice, not a substitute for the field's own label. *}
          <label class="form-label" for="field-email">{$contactFields['email']['label']}</label>
          <input class="form-control" type="email" name="email" id="field-email" value="{$contactFields['email']['value']}" required>
          {include file='_partials/form-errors.tpl' errors=$contactFields['email']['errors']|default:[]}
        </div>
      {/if}

      {if isset($contactFields['optin'])}
        <div class="one-page-checkout__field">
          {form_field field=$contactFields['optin']}
        </div>
      {/if}

      {foreach from=$additionalCustomerFields item="field"}
        <div class="one-page-checkout__field">
          {form_field field=$field}
        </div>
      {/foreach}
    {/if}
  </section>
{else}
  <section class="one-page-checkout__section">
    <h2 class="one-page-checkout__title">{l s='Contact information' d='Modules.Onepagecheckout.Shop'}</h2>
    {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/connected-account-info.tpl'}
  </section>
{/if}
