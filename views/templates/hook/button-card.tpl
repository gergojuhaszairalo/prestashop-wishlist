{**
 * Product-card heart (toggle). Rendered by displayProductListReviews (below name/price)
 * as a standalone form, outside the card's native cart form. wishlist.js moves this
 * wrapper into the card's image area for the top-right placement; without JS it stays here.
 * Outline heart: click saves the card's variant to the default list. Filled heart:
 * click removes it. The accessible name stays constant; aria-pressed carries the state.
 *}
{capture name='swl_name'}{l s='Save %product% to wishlist' sprintf=['%product%' => $swl_product_name] d='Modules.Summitwishlist.Shop'}{/capture}
{capture name='swl_title_add'}{l s='Add %product% to wishlist' sprintf=['%product%' => $swl_product_name] d='Modules.Summitwishlist.Shop'}{/capture}
{capture name='swl_title_remove'}{l s='Remove %product% from wishlist' sprintf=['%product%' => $swl_product_name] d='Modules.Summitwishlist.Shop'}{/capture}
<form class="sw-card-heart" action="{$swl_action_url|escape:'htmlall':'UTF-8'}" method="post">
  <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
  <input type="hidden" name="op" value="toggle">
  <input type="hidden" name="id_product" value="{$swl_id_product|intval}">
  <input type="hidden" name="swl_id_product_attribute" value="{$swl_id_product_attribute|intval}">
  <button
    type="submit"
    class="btn sw-heart sw-icon-action{if $swl_saved} is-saved{/if}"
    data-summitwishlist-action="toggle"
    data-sw-product="{$swl_id_product|intval}"
    data-sw-attribute="{$swl_id_product_attribute|intval}"
    data-sw-title-add="{$smarty.capture.swl_title_add|trim|escape:'htmlall':'UTF-8'}"
    data-sw-title-remove="{$smarty.capture.swl_title_remove|trim|escape:'htmlall':'UTF-8'}"
    aria-pressed="{if $swl_saved}true{else}false{/if}"
    aria-label="{$smarty.capture.swl_name|trim|escape:'htmlall':'UTF-8'}"
    title="{if $swl_saved}{$smarty.capture.swl_title_remove|trim|escape:'htmlall':'UTF-8'}{else}{$smarty.capture.swl_title_add|trim|escape:'htmlall':'UTF-8'}{/if}"
  >
    <i class="material-icons sw-heart-icon" aria-hidden="true">{if $swl_saved}&#xE87D;{else}&#xE87E;{/if}</i>
  </button>
</form>
