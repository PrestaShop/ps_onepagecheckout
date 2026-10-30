{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{**
 * One Page Checkout - Address fields partial
 *
 * Renders one address section (delivery or invoice) as the rows PHP handed over. Both the field
 * order and the row grouping are decided in AddressFieldRows: the order is the address format
 * configured for the selected country in International > Locations > Countries, with only the
 * country select pinned first because changing it rebuilds every other field.
 *
 * Every field is rendered by the theme's {form_field}, so themes keep control of field markup.
 * This partial only renders what it is given.
 *
 * Used for the inline form and, through address-modal-fields.tpl, for the address modals.
 *
 * @param array  $fieldRows         Rows of field arrays; a row of one renders as a single field
 * @param array  $formFields        The same fields keyed by form-field name, for theme overrides
 * @param array  $fieldSections     Optional: the rows split into sections by AddressFieldSections, some
 *                                  of them behind a disclosure. The inline forms pass it, the modals
 *                                  do not and render every row
 * @param string $prefix            Field-name prefix for this section ('' or 'invoice_')
 * @param string $id_prefix         Element-id prefix for the inert state placeholder only, so the
 *                                  modals do not collide with each other
 * @param bool   $state_placeholder Emit an inert state select when the format has no state field
 *}

{assign var="_prefix" value=$prefix|default:''}
{assign var="_id_prefix" value=$id_prefix|default:''}
{assign var="_state_placeholder" value=$state_placeholder|default:false}

{if isset($fieldSections)}
  {assign var="_sections" value=$fieldSections}
{else}
  {assign var="_sections" value=[['disclosure' => null, 'open' => true, 'rows' => $fieldRows|default:[]]]}
{/if}

{foreach from=$_sections item="_section"}
  {if $_section.disclosure}
    <details class="opc-optional-fields"{if $_section.open} open{/if}>
      <summary class="opc-optional-fields__toggle">
        {if $_section.disclosure === 'company'}
          {l s='Add company details' d='Modules.Onepagecheckout.Shop'}
        {else}
          {l s='Add an address complement' d='Modules.Onepagecheckout.Shop'}
        {/if}
      </summary>
  {/if}
  {foreach from=$_section.rows item="_row"}
    {if ($_row|count) > 1}
      {include file='module:ps_onepagecheckout/views/templates/front/_partials/form-fields-row.tpl'
        fields=$_row
      }
    {else}
      {form_field field=$_row[0]}
    {/if}
  {/foreach}
  {if $_section.disclosure}
    </details>
  {/if}
{/foreach}

{* Countries whose format has no state field at all: the modals keep an inert select in the DOM so
   the section always posts a state value. *}
{assign var="_state_key" value="{$_prefix}id_state"}
{if $_state_placeholder && !isset($formFields[$_state_key])}
  <div class="form-group mb-3 state-field-wrapper" style="display: none;">
    <label class="form-label" for="{$_id_prefix}field-id_state">
      {l s='State' d='Modules.Onepagecheckout.Shop'}
    </label>
    <select class="form-select" name="{$_prefix}id_state" id="{$_id_prefix}field-id_state">
      <option value="">{l s='-- please choose --' d='Modules.Onepagecheckout.Shop'}</option>
    </select>
  </div>
{/if}
