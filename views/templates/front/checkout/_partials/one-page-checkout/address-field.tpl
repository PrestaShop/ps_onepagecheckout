{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{**
 * One Page Checkout - a single address field
 *
 * Fields go through the theme's {form_field} so themes keep control of field markup. Two of them
 * need module-owned markup:
 *
 *  - id_country: the theme's countrySelect adds the `js-country` class, which wires the native
 *    four-step checkout's own country handler. OPC drives country changes itself, so it renders
 *    its own select to stay out of that handler's way.
 *  - id_state: kept in the DOM but hidden while the selected country has no states, instead of
 *    showing an empty select.
 *
 * @param array  $field     Field array as produced by FormField::toArray()
 * @param string $base      Field name without the section prefix (e.g. 'city', not 'invoice_city')
 * @param string $id_prefix Prefix for generated element ids, so several forms can coexist on a page
 *}

{assign var="_id_prefix" value=$id_prefix|default:''}

{if $base === 'id_country'}
  <div class="form-group mb-3">
    <label class="form-label{if $field.required} required{/if}" for="{$_id_prefix}field-id_country">
      {$field.label}
    </label>
    <select
      class="form-select"
      name="{$field.name}"
      id="{$_id_prefix}field-id_country"
      {if $field.required}required{/if}
    >
      <option value="">{l s='-- please choose --' d='Modules.Onepagecheckout.Shop'}</option>
      {foreach from=$field.availableValues item="label" key="value"}
        <option value="{$value}" {if (string) $value === (string) $field.value}selected{/if}>{$label}</option>
      {/foreach}
    </select>
    {include file='_partials/form-errors.tpl' errors=$field.errors|default:[]}
  </div>

{elseif $base === 'id_state' && empty($field.availableValues)}
  <div class="form-group mb-3 state-field-wrapper" style="display: none;">
    {form_field field=$field}
  </div>

{else}
  {form_field field=$field}
{/if}
