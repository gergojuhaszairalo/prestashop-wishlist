{extends file='page.tpl'}

{block name='page_title'}
  {if $swl_list}
    {$swl_list.name|escape:'htmlall':'UTF-8'}
  {elseif $swl_mode == 'not_found'}
    {l s='Wishlist not available' d='Modules.Summitwishlist.Shop'}
  {elseif $swl_mode == 'overview'}
    {l s='My wishlists' d='Modules.Summitwishlist.Shop'}
  {else}
    {l s='My wishlist' d='Modules.Summitwishlist.Shop'}
  {/if}
{/block}

{block name='page_content'}
  <section class="sw-wishlist sw-wishlist--{$swl_mode|escape:'htmlall':'UTF-8'}" aria-labelledby="sw-wishlist-count">
    {if $swl_mode == 'detail'}
      {include file='module:summitwishlist/views/templates/_partials/list-detail.tpl'}
    {elseif $swl_mode == 'overview'}
      {include file='module:summitwishlist/views/templates/_partials/overview.tpl'}
    {elseif $swl_mode == 'not_found'}
      {* Same response for foreign, unknown and deleted lists: no name, count or owner. *}
      <p class="sw-wishlist__count" id="sw-wishlist-count">{l s='This wishlist isn\'t available.' d='Modules.Summitwishlist.Shop'}</p>
      <div class="sw-empty">
        <i class="material-icons sw-empty__icon" aria-hidden="true">&#xE897;</i>
        <h2 class="sw-empty__title">{l s='This wishlist isn\'t available' d='Modules.Summitwishlist.Shop'}</h2>
        <p class="sw-empty__text">{l s='It may have been deleted, or it belongs to someone else.' d='Modules.Summitwishlist.Shop'}</p>
        <a class="btn btn-primary sw-action" href="{$swl_manage_url|escape:'htmlall':'UTF-8'}">{l s='Go to my wishlists' d='Modules.Summitwishlist.Shop'}</a>
      </div>
    {else}
      {* Zero lists: the Stage 1 empty page, plus "Create your first wishlist". *}
      <p class="sw-wishlist__count" id="sw-wishlist-count">
        {l s='%count% saved items' sprintf=['%count%' => 0] d='Modules.Summitwishlist.Shop'}
      </p>
      {include file='module:summitwishlist/views/templates/_partials/feedback.tpl'}
      {include file='module:summitwishlist/views/templates/_partials/empty.tpl' swl_empty_kind='zero'}
    {/if}

    {if $swl_mode != 'not_found'}
      {include file='module:summitwishlist/views/templates/_partials/name-dialog.tpl' swl_dialog_mode='create' swl_dialog_target=null}
    {/if}
  </section>
{/block}
