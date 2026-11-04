{**
 * Empty states (Stage 1 markup and wording kept for one-list owners).
 * @param swl_empty_kind 'zero' (no lists at all) | 'list' (the open list has no entries)
 *}
<div class="sw-empty">
  <i class="material-icons sw-empty__icon" aria-hidden="true">&#xE87E;</i>
  {if $swl_empty_kind == 'list' && $swl_lists|count > 1}
    <h2 class="sw-empty__title">{l s='"%list%" is empty' sprintf=['%list%' => $swl_list.name] d='Modules.Summitwishlist.Shop'}</h2>
  {else}
    <h2 class="sw-empty__title">{l s='Your wishlist is empty' d='Modules.Summitwishlist.Shop'}</h2>
  {/if}
  <p class="sw-empty__text">{l s='Tap the heart on any product to save it here for later.' d='Modules.Summitwishlist.Shop'}</p>
  <div class="sw-empty__actions">
    <a class="btn btn-primary sw-action" href="{$swl_continue_url|escape:'htmlall':'UTF-8'}">
      {l s='Continue shopping' d='Modules.Summitwishlist.Shop'}
    </a>
    {if $swl_empty_kind == 'zero'}
      <a class="btn btn-outline-primary sw-action" href="{$swl_self_url|escape:'htmlall':'UTF-8'}&amp;dialog=create" data-sw-confirm="sw-create-dialog">
        {l s='Create your first wishlist' d='Modules.Summitwishlist.Shop'}
      </a>
    {/if}
  </div>
</div>
