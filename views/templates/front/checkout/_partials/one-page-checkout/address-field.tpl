{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{**
 * One Page Checkout - a single address field
 *
 * Every field is rendered by the theme's {form_field}, so themes keep full control of field
 * markup, classes and wrappers. The only deviation is a presentational one: while the selected
 * country has no states, the state select is kept in the DOM but hidden, instead of showing an
 * empty dropdown.
 *
 * @param array  $field Field array as produced by FormField::toArray()
 * @param string $base  Field name without the section prefix (e.g. 'city', not 'invoice_city')
 *}

{if $base === 'id_state' && empty($field.availableValues)}
  <div class="form-group mb-3 state-field-wrapper" style="display: none;">
    {form_field field=$field}
  </div>
{else}
  {form_field field=$field}
{/if}
