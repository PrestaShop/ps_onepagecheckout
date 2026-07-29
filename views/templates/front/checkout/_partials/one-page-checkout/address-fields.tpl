{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{**
 * One Page Checkout - Address fields partial
 *
 * Renders one address section (delivery or invoice) in the order the formatter emitted, which is
 * the address format configured for the selected country in International > Locations > Countries.
 * Only the country select leads the section, because changing it rebuilds every other field.
 *
 * Some fields read better side by side (first/last name, city/state/postcode). They share a row
 * ONLY when the configured format puts them next to each other, so a merchant who moves a field
 * into the middle of one of those groups still gets the layout they asked for.
 *
 * Every field is rendered by the theme's {form_field} (see address-field.tpl), so themes keep
 * control of field markup. This partial only decides ORDER and row grouping.
 *
 * Used for the inline form and, through address-modal-fields.tpl, for the address modals.
 *
 * @param array  $formFields        Field arrays keyed by form-field name, in formatter order
 * @param string $prefix            Field-name prefix for this section ('' or 'invoice_')
 * @param string $id_prefix         Element-id prefix for the inert state placeholder only, so the
 *                                  modals do not collide with each other
 * @param bool   $state_placeholder Emit an inert state select when the format has no state field
 *}

{assign var="_prefix" value=$prefix|default:''}
{assign var="_id_prefix" value=$id_prefix|default:''}
{assign var="_state_placeholder" value=$state_placeholder|default:false}
{assign var="_prefix_len" value=$_prefix|strlen}

{* Fields that may share a row, as sets. Adjacency in the configured format decides whether a set
   actually becomes a row; the order within the row follows the format, not this list. *}
{assign var="_row_groups" value=[['firstname', 'lastname'], ['city', 'postcode', 'id_state']]}

{* This section's base field names in formatter order, plus a base name => field lookup. *}
{assign var="_bases" value=[]}
{assign var="_fields_by_base" value=[]}
{foreach from=$formFields item="_field"}
  {if $_prefix && strpos($_field.name, $_prefix) !== 0}{continue}{/if}
  {if !$_prefix && strpos($_field.name, 'invoice_') === 0}{continue}{/if}

  {if $_prefix}
    {assign var="_base" value=$_field.name|substr:$_prefix_len}
  {else}
    {assign var="_base" value=$_field.name}
  {/if}

  {append var="_bases" value=$_base}
  {$_fields_by_base[$_base] = $_field}
{/foreach}

{assign var="_total" value=$_bases|count}
{assign var="_cursor" value=0}

{while $_cursor < $_total}
  {assign var="_base" value=$_bases[$_cursor]}

  {* The row group this field belongs to, if any. *}
  {assign var="_group" value=[]}
  {foreach from=$_row_groups item="_candidate"}
    {if in_array($_base, $_candidate)}
      {assign var="_group" value=$_candidate}
      {break}
    {/if}
  {/foreach}

  {* Longest run of adjacent fields drawn from that group, never longer than the group itself. *}
  {assign var="_run" value=[]}
  {if $_group}
    {assign var="_scan" value=$_cursor}
    {while $_scan < $_total && ($_run|count) < ($_group|count) && in_array($_bases[$_scan], $_group)}
      {append var="_run" value=$_bases[$_scan]}
      {assign var="_scan" value=$_scan + 1}
    {/while}
  {/if}

  {if ($_run|count) > 1}
    {* A state field the selected country has no states for is hidden, so it claims no column. *}
    {assign var="_row_columns" value=$_run|count}
    {if in_array('id_state', $_run) && empty($_fields_by_base['id_state'].availableValues)}
      {assign var="_row_columns" value=$_row_columns - 1}
    {/if}
    <div class="opc-form-fields-row opc-form-fields-row--{$_row_columns}">
      {foreach from=$_run item="_row_base"}
        {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-field.tpl'
          field=$_fields_by_base[$_row_base]
          base=$_row_base
        }
      {/foreach}
    </div>
    {assign var="_cursor" value=$_cursor + ($_run|count)}

  {else}
    {include file='module:ps_onepagecheckout/views/templates/front/checkout/_partials/one-page-checkout/address-field.tpl'
      field=$_fields_by_base[$_base]
      base=$_base
    }
    {assign var="_cursor" value=$_cursor + 1}
  {/if}
{/while}

{* Countries whose format has no state field at all: the modals keep an inert select in the DOM so
   the section always posts a state value. *}
{if $_state_placeholder && !isset($_fields_by_base['id_state'])}
  <div class="form-group mb-3 state-field-wrapper" style="display: none;">
    <label class="form-label" for="{$_id_prefix}field-id_state">
      {l s='State' d='Modules.Onepagecheckout.Shop'}
    </label>
    <select class="form-select" name="{$_prefix}id_state" id="{$_id_prefix}field-id_state">
      <option value="">{l s='-- please choose --' d='Modules.Onepagecheckout.Shop'}</option>
    </select>
  </div>
{/if}
