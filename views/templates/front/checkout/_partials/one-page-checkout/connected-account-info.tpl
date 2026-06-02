{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

<div class="step__account">
  {capture name='opc_account_aria_label'}{l s='My account (%firstname% %lastname%)' d='Modules.Onepagecheckout.Shop' sprintf=['%firstname%' => $customer.firstname, '%lastname%' => $customer.lastname]}{/capture}
  <p>
    {l
    s='Connected as [1]%firstname% %lastname%[/1].'
    d='Modules.Onepagecheckout.Shop'
    sprintf=[
      '[1]' => "<a href='{$urls.pages.identity}' aria-label='{$smarty.capture.opc_account_aria_label|escape:'html'}'>",
      '[/1]' => "</a>",
      '%firstname%' => $customer.firstname,
      '%lastname%' => $customer.lastname
    ]
    }
  </p>

  <p class="mb-1">
    {l
    s='Not you? [1]Sign out[/1]'
    d='Modules.Onepagecheckout.Shop'
    sprintf=[
      '[1]' => "<a class='text-danger' href='{$urls.actions.logout}'>",
      '[/1]' => "</a>"
    ]
    }
  </p>

  {if !isset($empty_cart_on_logout) || $empty_cart_on_logout}
    <p class="mb-0">
      <small class="text-body-tertiary">
        {l s='If you sign out now, your cart will be emptied.' d='Modules.Onepagecheckout.Shop'}
      </small>
    </p>
  {/if}
</div>
