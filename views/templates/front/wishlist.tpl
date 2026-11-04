{extends file='page.tpl'}

{block name='page_title'}
  {l s='My wishlist' d='Modules.Summitwishlist.Shop'}
{/block}

{block name='page_content'}
  <section class="sw-wishlist" aria-labelledby="sw-wishlist-count">
    <p class="sw-wishlist__count" id="sw-wishlist-count">
      {l s='%count% saved items' sprintf=['%count%' => $swl_items|count] d='Modules.Summitwishlist.Shop'}
    </p>

    {include file='module:summitwishlist/views/templates/_partials/feedback.tpl'}
    {include file='module:summitwishlist/views/templates/_partials/post-cart-prompt.tpl'}

    {if $swl_items}
      {* One form: "Add to cart" and "Add selected" post to the cart controller,
         "Remove" posts to the item controller through formaction. *}
      <form class="sw-wishlist__form" action="{$swl_cart_url|escape:'htmlall':'UTF-8'}" method="post" data-sw-wishlist-form>
        <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="op" value="remove">

        <div class="sw-toolbar">
          <div class="form-check sw-toolbar__select">
            <input class="form-check-input" type="checkbox" id="sw-select-all" data-sw-select-all{if !$swl_available_count} disabled{/if}>
            <label class="form-check-label" for="sw-select-all">
              {l s='Select available items' d='Modules.Summitwishlist.Shop'}
            </label>
          </div>
          <div class="sw-toolbar__end">
            <span class="sw-toolbar__selected" data-sw-selected-count aria-live="polite"
                  data-sw-label="{l s='%count% selected' d='Modules.Summitwishlist.Shop'}"></span>
            {* Belongs to #sw-clear-form (outside this form) via the form attribute; with JS it opens a confirmation first. *}
            <button type="submit" form="sw-clear-form" class="btn btn-link sw-action sw-clear" data-sw-confirm="sw-clear-dialog">
              <i class="material-icons" aria-hidden="true">&#xE872;</i>
              {l s='Empty wishlist' d='Modules.Summitwishlist.Shop'}
            </button>
          </div>
        </div>

        <ul class="sw-list list-unstyled">
          {foreach from=$swl_items item=item}
            {include file='module:summitwishlist/views/templates/_partials/wishlist-item.tpl' item=$item}
          {/foreach}
        </ul>

        <div class="sw-bulk">
          <button type="submit" name="bulk" value="1" class="btn btn-primary sw-action" data-sw-bulk
                  data-sw-label-none="{l s='Add selected to cart' d='Modules.Summitwishlist.Shop'}"
                  data-sw-label-some="{l s='Add %count% selected to cart' d='Modules.Summitwishlist.Shop'}">
            {l s='Add selected to cart' d='Modules.Summitwishlist.Shop'}
          </button>
          <a class="btn btn-outline-primary sw-action" href="{$swl_continue_url|escape:'htmlall':'UTF-8'}">
            {l s='Continue shopping' d='Modules.Summitwishlist.Shop'}
          </a>
        </div>
      </form>

      <form id="sw-clear-form" action="{$swl_item_url|escape:'htmlall':'UTF-8'}" method="post" hidden>
        <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
        <input type="hidden" name="op" value="clear">
      </form>

      <dialog class="sw-dialog" id="sw-clear-dialog" aria-labelledby="sw-clear-title" aria-describedby="sw-clear-text">
        <h2 class="sw-dialog__title" id="sw-clear-title">{l s='Empty your wishlist?' d='Modules.Summitwishlist.Shop'}</h2>
        <p class="sw-dialog__text" id="sw-clear-text">
          {l s='This removes all %count% saved products. Your cart is not affected.' sprintf=['%count%' => $swl_items|count] d='Modules.Summitwishlist.Shop'}
        </p>
        <div class="sw-dialog__actions">
          <button type="button" class="btn btn-outline-primary sw-action" data-sw-dialog-close>
            {l s='Cancel' d='Shop.Theme.Actions'}
          </button>
          <button type="submit" form="sw-clear-form" class="btn btn-danger sw-action">
            {l s='Empty wishlist' d='Modules.Summitwishlist.Shop'}
          </button>
        </div>
      </dialog>
    {else}
      {include file='module:summitwishlist/views/templates/_partials/empty.tpl'}
    {/if}
  </section>
{/block}
