{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

<div class="step__account">
  <p>
    {l s='Connected as' d='Modules.Onepagecheckout.Shop'}
    <a href="{$urls.pages.identity|escape:'html'}" aria-label="{l s='My account' d='Modules.Onepagecheckout.Shop'} ({$customer.firstname|escape:'html'} {$customer.lastname|escape:'html'})">{$customer.firstname|escape:'html'} {$customer.lastname|escape:'html'}</a>.
  </p>

  <p class="mb-1">
    {l s='Not you?' d='Modules.Onepagecheckout.Shop'}
    <a class="text-danger" href="{$urls.actions.logout|escape:'html'}">{l s='Sign out' d='Modules.Onepagecheckout.Shop'}</a>
  </p>

  {if !isset($empty_cart_on_logout) || $empty_cart_on_logout}
    <p class="mb-0">
      <small class="text-body-tertiary">{l s='If you sign out now, your cart will be emptied.' d='Modules.Onepagecheckout.Shop'}</small>
    </p>
  {/if}
</div>
