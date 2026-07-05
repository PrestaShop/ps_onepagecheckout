{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{**
 * One Page Checkout - Address fields partial
 *}

{assign var="_prefix_len" value=$prefix|strlen}
{assign var="_key_firstname" value="{$prefix}firstname"}
{assign var="_key_lastname" value="{$prefix}lastname"}
{assign var="_key_city" value="{$prefix}city"}
{assign var="_key_postcode" value="{$prefix}postcode"}
{assign var="_key_id_state" value="{$prefix}id_state"}
{assign var="_key_company" value="{$prefix}company"}
{assign var="_key_vat_number" value="{$prefix}vat_number"}

{assign var="_has_name_row" value=isset($formFields[$_key_firstname]) && isset($formFields[$_key_lastname])}
{assign var="_has_city_row" value=isset($formFields[$_key_city]) && isset($formFields[$_key_postcode])}
{assign var="_has_state" value=isset($formFields[$_key_id_state])}

{foreach from=$formFields item="field"}
  {if $prefix && strpos($field.name, $prefix) !== 0}{continue}{/if}
  {if !$prefix && strpos($field.name, 'invoice_') === 0}{continue}{/if}

  {if $prefix}
    {assign var="_base" value=$field.name|substr:$_prefix_len}
  {else}
    {assign var="_base" value=$field.name}
  {/if}

  {if $_base === 'alias'}
    {* Not rendered on the checkout form: a first-time buyer has no basis for naming an
       address, and the save paths already default an empty alias to the translated
       "My Address". The field stays available in the account address book and the
       address modal. *}

  {elseif $_base === 'firstname' && $_has_name_row}
    {include file='module:ps_onepagecheckout/views/templates/front/_partials/form-fields-row.tpl'
      fields=[$formFields[$_key_firstname], $formFields[$_key_lastname]]
    }

  {elseif $_base === 'lastname' && $_has_name_row}
  {elseif $_base === 'city' && $_has_city_row}
    {if $_has_state}
      {include file='module:ps_onepagecheckout/views/templates/front/_partials/form-fields-row.tpl'
        fields=[$formFields[$_key_city], $formFields[$_key_id_state], $formFields[$_key_postcode]]
      }
    {else}
      {include file='module:ps_onepagecheckout/views/templates/front/_partials/form-fields-row.tpl'
        fields=[$formFields[$_key_city], $formFields[$_key_postcode]]
      }
    {/if}

  {elseif $_base === 'postcode' && $_has_city_row}
  {elseif $_base === 'id_state' && $_has_city_row}
  {elseif $_base === 'address2'}
    {* Optional complement collapsed behind a disclosure; kept visible when the field is
       required by the address format, already filled (draft/saved value) or invalid. *}
    {if $field.required}
      {form_field field=$field}
    {else}
      <details class="opc-optional-fields"{if $field.value || !empty($field.errors)} open{/if}>
        <summary class="opc-optional-fields__toggle">{l s='Add an address complement' d='Modules.Onepagecheckout.Shop'}</summary>
        {form_field field=$field}
      </details>
    {/if}

  {elseif $_base === 'company' && isset($formFields[$_key_company])}
    {* Optional B2B fields (company + VAT) grouped behind one disclosure, with the same
       keep-visible rules as the address complement. *}
    {assign var="_b2b_required" value=$formFields[$_key_company].required || (isset($formFields[$_key_vat_number]) && $formFields[$_key_vat_number].required)}
    {assign var="_b2b_filled" value=$formFields[$_key_company].value || !empty($formFields[$_key_company].errors) || (isset($formFields[$_key_vat_number]) && ($formFields[$_key_vat_number].value || !empty($formFields[$_key_vat_number].errors)))}
    {if $_b2b_required}
      {form_field field=$formFields[$_key_company]}
      {if isset($formFields[$_key_vat_number])}{form_field field=$formFields[$_key_vat_number]}{/if}
    {else}
      <details class="opc-optional-fields"{if $_b2b_filled} open{/if}>
        <summary class="opc-optional-fields__toggle">{l s='Add company details' d='Modules.Onepagecheckout.Shop'}</summary>
        {form_field field=$formFields[$_key_company]}
        {if isset($formFields[$_key_vat_number])}{form_field field=$formFields[$_key_vat_number]}{/if}
      </details>
    {/if}

  {elseif $_base === 'vat_number' && isset($formFields[$_key_company])}
  {elseif $_base === 'id_country'}
    <div class="form-group mb-3">
      <label class="form-label{if $field.required} required{/if}" for="field-{$field.name}">
        {$field.label}
      </label>
      <select
        class="form-select"
        name="{$field.name}"
        id="field-{$field.name}"
        {if $field.required}required{/if}
      >
        <option value="">{l s='-- please choose --' d='Modules.Onepagecheckout.Shop'}</option>
        {foreach from=$field.availableValues item="label" key="value"}
          <option value="{$value}" {if (string) $value === (string) $field.value}selected{/if}>{$label}</option>
        {/foreach}
      </select>
      {include file='_partials/form-errors.tpl' errors=$field.errors|default:[]}
    </div>

  {else}
    {form_field field=$field}
  {/if}
{/foreach}
