{**
 * For the full copyright and license information, please view the
 * LICENSE.md file that was distributed with this source code.
 *}

{**
 * OPC — Awaiting-address hint (section readiness).
 *
 * Shown while a section (carriers / payment) cannot list its options because no usable delivery/tax
 * address exists yet. Soft, informational tone (the buyer has not done anything wrong). The runtime
 * fills `.js-opc-awaiting-address-fields` with the still-missing required fields, by label.
 *
 * Variables:
 *   $message - the base guidance sentence
 *}
<div class="alert alert-info mb-0 js-opc-awaiting-address" role="status">
  <span class="js-opc-awaiting-address-message">{$message}</span>
  <span class="js-opc-awaiting-address-fields"></span>
</div>
