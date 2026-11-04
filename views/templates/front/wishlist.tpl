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
          <span class="sw-toolbar__selected" data-sw-selected-count aria-live="polite"
                data-sw-label="{l s='%count% selected' d='Modules.Summitwishlist.Shop'}"></span>
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
    {else}
      {include file='module:summitwishlist/views/templates/_partials/empty.tpl'}
    {/if}
  </section>
{/block}
