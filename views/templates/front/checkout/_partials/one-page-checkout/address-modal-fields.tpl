{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *
 * Address modal fields, rebuilt per country. Rendered both on initial page load
 * and by the `addressmodal` AJAX endpoint when the selected country changes.
 *}
<div class="row">
  {assign var="_key_alias" value="{$prefix}alias"}
  {assign var="_key_id_country" value="{$prefix}id_country"}
  {assign var="_key_firstname" value="{$prefix}firstname"}
  {assign var="_key_lastname" value="{$prefix}lastname"}
  {assign var="_key_company" value="{$prefix}company"}
  {assign var="_key_vat_number" value="{$prefix}vat_number"}
  {assign var="_key_address1" value="{$prefix}address1"}
  {assign var="_key_address2" value="{$prefix}address2"}
  {assign var="_key_city" value="{$prefix}city"}
  {assign var="_key_postcode" value="{$prefix}postcode"}
  {assign var="_key_id_state" value="{$prefix}id_state"}
  {assign var="_key_phone" value="{$prefix}phone"}
  {assign var="_has_states" value=false}
  {if isset($formFields[$_key_id_state]) && !empty($formFields[$_key_id_state].availableValues)}
    {assign var="_has_states" value=true}
  {/if}

  {if isset($formFields[$_key_id_country])}
    <div class="form-group mb-3">
      <label class="form-label{if $formFields[$_key_id_country].required} required{/if}" for="{$modal_id}-field-id_country">
        {$formFields[$_key_id_country].label}
      </label>
      <select
        class="form-select"
        name="{$formFields[$_key_id_country].name}"
        id="{$modal_id}-field-id_country"
        {if $formFields[$_key_id_country].required}required{/if}
      >
        <option value="">{l s='-- please choose --' d='Modules.Onepagecheckout.Shop'}</option>
        {foreach from=$formFields[$_key_id_country].availableValues item="label" key="value"}
          <option value="{$value}" {if (string) $value === (string) $formFields[$_key_id_country].value}selected{/if}>{$label}</option>
        {/foreach}
      </select>
    </div>
  {/if}

  {if isset($formFields[$_key_alias])}{form_field field=$formFields[$_key_alias] id_prefix="{$modal_id}-"}{/if}

  {if isset($formFields[$_key_firstname]) && isset($formFields[$_key_lastname])}
    {include file='module:ps_onepagecheckout/views/templates/front/_partials/form-fields-row.tpl' fields=[$formFields[$_key_firstname], $formFields[$_key_lastname]] id_prefix="{$modal_id}-"}
  {/if}

  {if isset($formFields[$_key_company])}{form_field field=$formFields[$_key_company] id_prefix="{$modal_id}-"}{/if}

  {if isset($formFields[$_key_vat_number])}{form_field field=$formFields[$_key_vat_number] id_prefix="{$modal_id}-"}{/if}

  {if isset($formFields[$_key_address1])}{form_field field=$formFields[$_key_address1] id_prefix="{$modal_id}-"}{/if}

  {if isset($formFields[$_key_address2])}{form_field field=$formFields[$_key_address2] id_prefix="{$modal_id}-"}{/if}

  <div class="opc-form-fields-row {if $_has_states}opc-form-fields-row--3{else}opc-form-fields-row--2{/if} address-country-row">
    {if isset($formFields[$_key_city])}{form_field field=$formFields[$_key_city] id_prefix="{$modal_id}-"}{/if}
    <div class="form-group mb-3 state-field-wrapper" style="{if !$_has_states}display: none;{/if}">
      <label class="form-label{if isset($formFields[$_key_id_state]) && $formFields[$_key_id_state].required} required{/if}" for="{$modal_id}-field-id_state">
        {l s='State' d='Modules.Onepagecheckout.Shop'}
      </label>
      <select
        class="form-select"
        name="{if isset($formFields[$_key_id_state])}{$formFields[$_key_id_state].name}{else}{$prefix}id_state{/if}"
        id="{$modal_id}-field-id_state"
        data-select-placeholder="{l s='-- please choose --' d='Modules.Onepagecheckout.Shop' js=1}"
        {if $_has_states && $formFields[$_key_id_state].required}required{/if}
      >
        <option value="">{l s='-- please choose --' d='Modules.Onepagecheckout.Shop'}</option>
        {if isset($formFields[$_key_id_state]) && isset($formFields[$_key_id_state].availableValues)}
          {foreach from=$formFields[$_key_id_state].availableValues item="label" key="value"}
            <option value="{$value}" {if $value eq $formFields[$_key_id_state].value}selected{/if}>{$label}</option>
          {/foreach}
        {/if}
      </select>
    </div>
    {if isset($formFields[$_key_postcode])}{form_field field=$formFields[$_key_postcode] id_prefix="{$modal_id}-"}{/if}
  </div>

  {if isset($formFields[$_key_phone])}{form_field field=$formFields[$_key_phone] id_prefix="{$modal_id}-"}{/if}

  {* Render any additional fields not covered above (phone_mobile, dni, other, hook fields) *}
  {assign var="_static_fields" value=['alias', 'id_country', 'firstname', 'lastname', 'company', 'vat_number', 'address1', 'address2', 'city', 'postcode', 'id_state', 'phone']}
  {foreach from=$formFields item="field" key="fieldKey"}
    {if $prefix && strpos($field.name, $prefix) !== 0}{continue}{/if}
    {if !$prefix && strpos($field.name, 'invoice_') === 0}{continue}{/if}
    {if $prefix}
      {assign var="_base" value=$field.name|substr:($prefix|strlen)}
    {else}
      {assign var="_base" value=$field.name}
    {/if}
    {if in_array($_base, $_static_fields)}{continue}{/if}
    {form_field field=$field id_prefix="{$modal_id}-"}
  {/foreach}
</div>
