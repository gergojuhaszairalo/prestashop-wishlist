{**
 * PDP toggle. It sits inside the core #add-to-cart-or-refresh form, so it must not
 * open a form of its own: it submits that form (id_product + group[]) to the module
 * via formaction, which also gives a working no-JS path. The module CSRF field has
 * its own name so it never collides with the core cart token.
 * Clicking adds the selected combination, or removes it when it is already saved.
 *}
<div class="sw-pdp">
  <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
  <button
    type="submit"
    class="btn btn-outline-primary sw-action sw-pdp__trigger{if $swl_saved} is-saved{/if}"
    formaction="{$swl_action_url|escape:'htmlall':'UTF-8'}"
    formmethod="post"
    name="op"
    value="toggle"
    data-summitwishlist-action="toggle"
    data-sw-product="{$swl_id_product|intval}"
    data-sw-attribute="{$swl_id_product_attribute|intval}"
    data-sw-text-add="{l s='Add to wishlist' d='Modules.Summitwishlist.Shop'}"
    data-sw-text-saved="{l s='In your wishlist' d='Modules.Summitwishlist.Shop'}"
    data-sw-title-add="{l s='Add to wishlist' d='Modules.Summitwishlist.Shop'}"
    data-sw-title-remove="{l s='Remove from wishlist' d='Modules.Summitwishlist.Shop'}"
    title="{if $swl_saved}{l s='Remove from wishlist' d='Modules.Summitwishlist.Shop'}{else}{l s='Add to wishlist' d='Modules.Summitwishlist.Shop'}{/if}"
    aria-pressed="{if $swl_saved}true{else}false{/if}"
  >
    <i class="material-icons sw-heart-icon" aria-hidden="true">{if $swl_saved}&#xE87D;{else}&#xE87E;{/if}</i>
    <span class="sw-pdp__label">
      {if $swl_saved}
        {l s='In your wishlist' d='Modules.Summitwishlist.Shop'}
      {else}
        {l s='Add to wishlist' d='Modules.Summitwishlist.Shop'}
      {/if}
    </span>
  </button>
</div>
