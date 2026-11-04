{**
 * Shown after products were added to the cart from the wishlist: ask whether they should
 * also leave the wishlist. Opt-in; "Keep" is the default and changes nothing.
 * Rendered open, so without JS it is an inline panel; wishlist.js upgrades it to a modal.
 *}
{if $swl_added_items}
  <dialog class="sw-dialog sw-dialog--inline" id="sw-post-cart" open data-sw-autoshow
          aria-labelledby="sw-post-cart-title" aria-describedby="sw-post-cart-text">
    <form action="{$swl_item_url|escape:'htmlall':'UTF-8'}" method="post">
      <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
      <input type="hidden" name="op" value="remove_selected">
      {foreach from=$swl_added_items item=added}
        <input type="hidden" name="ids[]" value="{$added.id|intval}">
      {/foreach}

      <h2 class="sw-dialog__title" id="sw-post-cart-title">
        <i class="material-icons text-success" aria-hidden="true">&#xE86C;</i>
        {l s='Added to your cart' d='Modules.Summitwishlist.Shop'}
      </h2>
      <p class="sw-dialog__text" id="sw-post-cart-text">
        {if $swl_added_items|count == 1}
          {l s='Do you also want to remove this product from your wishlist?' d='Modules.Summitwishlist.Shop'}
        {else}
          {l s='Do you also want to remove these %count% products from your wishlist?' sprintf=['%count%' => $swl_added_items|count] d='Modules.Summitwishlist.Shop'}
        {/if}
      </p>
      <ul class="sw-dialog__list list-unstyled">
        {foreach from=$swl_added_items item=added}
          <li>
            {$added.name|escape:'htmlall':'UTF-8'}{if $added.combination} <span class="sw-item__variant">({$added.combination|escape:'htmlall':'UTF-8'})</span>{/if}
          </li>
        {/foreach}
      </ul>
      <div class="sw-dialog__actions">
        <a class="btn btn-outline-primary sw-action" href="{$swl_wishlist_url|escape:'htmlall':'UTF-8'}" data-sw-dialog-close>
          {l s='Keep in wishlist' d='Modules.Summitwishlist.Shop'}
        </a>
        <button type="submit" class="btn btn-primary sw-action">
          {l s='Remove from wishlist' d='Modules.Summitwishlist.Shop'}
        </button>
      </div>
    </form>
  </dialog>
{/if}
