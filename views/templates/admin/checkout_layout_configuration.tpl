{**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *}

{if isset($checkout_layout_css_url) && $checkout_layout_css_url !== ''}
  <link rel="stylesheet" href="{$checkout_layout_css_url|escape:'htmlall':'UTF-8'}">
{/if}

<div class="psopc-configuration">
  <div class="psopc-card">
    <div class="psopc-card-body">
      <h3 class="psopc-layout-title">{$checkout_layout_title|escape:'html':'UTF-8'}</h3>
      <p class="psopc-layout-description">{$checkout_layout_description|escape:'html':'UTF-8'}</p>

      <form action="{$configuration_form_action|escape:'htmlall':'UTF-8'}" method="post" class="psopc-form">
        <div id="{$configuration_key|escape:'html':'UTF-8'}" class="psopc-choices">
          {foreach from=$choices item=choice}
            <label class="psopc-choice" for="{$choice.id|escape:'html':'UTF-8'}">
              <span class="psopc-choice-radio">
                <input
                  type="radio"
                  id="{$choice.id|escape:'html':'UTF-8'}"
                  name="{$configuration_key|escape:'html':'UTF-8'}"
                  value="{$choice.value|intval}"
                  {if $choice.checked}checked="checked"{/if}
                  required="required"
                >
                <span class="psopc-choice-dot" aria-hidden="true"></span>
              </span>

              <span class="psopc-choice-content">
                <span class="psopc-choice-header">
                  <span class="psopc-choice-label">{$choice.label|escape:'html':'UTF-8'}</span>
                  {if $choice.badge|default:'' !== ''}
                    <span class="psopc-choice-badge">{$choice.badge|escape:'html':'UTF-8'}</span>
                  {/if}
                </span>

                <span class="psopc-choice-body">
                  <span class="psopc-choice-text">
                    <span class="psopc-choice-description">{$choice.description|escape:'html':'UTF-8'}</span>
                    <ul class="psopc-choice-features">
                      {foreach from=$choice.features item=feature}
                        <li>{$feature|escape:'html':'UTF-8'}</li>
                      {/foreach}
                    </ul>
                  </span>
                  <span class="psopc-choice-illustration" aria-hidden="true">
                    <img src="{$choice.illustration|escape:'htmlall':'UTF-8'}" alt="{$choice.label|escape:'html':'UTF-8'}">
                  </span>
                </span>
              </span>
            </label>
          {/foreach}
        </div>

        <div class="psopc-actions">
          <button type="submit" name="{$form_submit_action|escape:'html':'UTF-8'}" class="btn btn-primary psopc-save-button">
            {$save_button_label|escape:'html':'UTF-8'}
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
