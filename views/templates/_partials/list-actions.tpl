{**
 * Management actions for one list (overview card or detail header): Open, Rename, Make default, Delete.
 * Links carry a no-JS fallback (?dialog=...); with JS they open the matching dialog.
 * Make default is a real POST (CSRF) and changes no products.
 *}
{* Both bases always carry a query (?list= / ?view=all), so the dialog parameter is appended with &. *}
{$swl_base = $swl_card.url|default:$swl_self_url}
{$swl_sep = '&'}
<div class="sw-list-actions">
  {if $swl_context == 'card'}
    <a class="btn btn-primary sw-action" href="{$swl_card.url|escape:'htmlall':'UTF-8'}">
      {l s='Open' d='Modules.Summitwishlist.Shop'}<span class="visually-hidden"> {$swl_card.name|escape:'htmlall':'UTF-8'}</span>
    </a>
  {/if}
  <a class="btn btn-outline-primary sw-action" href="{$swl_base|escape:'htmlall':'UTF-8'}{$swl_sep}dialog=rename&amp;target={$swl_card.id|intval}"
     data-sw-confirm="sw-rename-dialog-{$swl_card.id|intval}">
    <i class="material-icons" aria-hidden="true">&#xE3C9;</i>
    {l s='Rename' d='Modules.Summitwishlist.Shop'}<span class="visually-hidden"> {$swl_card.name|escape:'htmlall':'UTF-8'}</span>
  </a>
  {if !$swl_card.is_default}
    <form class="sw-inline-form" action="{$swl_manage_url|escape:'htmlall':'UTF-8'}" method="post">
      <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
      <input type="hidden" name="op" value="set_default">
      <input type="hidden" name="id_wishlist" value="{$swl_card.id|intval}">
      <input type="hidden" name="return" value="{$swl_self_url|escape:'htmlall':'UTF-8'}">
      <button type="submit" class="btn btn-outline-primary sw-action">
        <i class="material-icons" aria-hidden="true">&#xE838;</i>
        {l s='Make default' d='Modules.Summitwishlist.Shop'}<span class="visually-hidden"> {$swl_card.name|escape:'htmlall':'UTF-8'}</span>
      </button>
    </form>
  {/if}
  <a class="btn btn-outline-tertiary sw-action sw-delete-list" href="{$swl_base|escape:'htmlall':'UTF-8'}{$swl_sep}dialog=delete&amp;target={$swl_card.id|intval}"
     data-sw-confirm="sw-delete-dialog-{$swl_card.id|intval}">
    <i class="material-icons" aria-hidden="true">&#xE872;</i>
    {l s='Delete' d='Modules.Summitwishlist.Shop'}<span class="visually-hidden"> {$swl_card.name|escape:'htmlall':'UTF-8'}</span>
  </a>
  {if $swl_context == 'detail'}
    {* On a list page (including the only list of a Stage 1 shopper) this is how a second list starts. *}
    <a class="btn btn-primary sw-action sw-create-list" href="{$swl_self_url|escape:'htmlall':'UTF-8'}&amp;dialog=create" data-sw-confirm="sw-create-dialog">
      <i class="material-icons" aria-hidden="true">&#xE145;</i>
      {l s='Create wishlist' d='Modules.Summitwishlist.Shop'}
    </a>
  {/if}
</div>
