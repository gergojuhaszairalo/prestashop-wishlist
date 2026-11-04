{**
 * Confirm deleting one list. Cancel gets the initial focus (safe default).
 * Only this list's saved associations go; other lists and catalogue products stay.
 *
 * @param swl_dialog_target the list ({id, name, count, is_default})
 *}
{$swl_dlg_id = "sw-delete-dialog-`$swl_dialog_target.id`"}
{$swl_dlg_open = ($swl_open_dialog == 'delete' && ($swl_dialog_list == $swl_dialog_target.id || ($swl_dialog_list == 0 && $swl_list && $swl_list.id == $swl_dialog_target.id)))}
<dialog class="sw-dialog{if $swl_dlg_open} sw-dialog--inline{/if}" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}"
        aria-labelledby="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-title" aria-describedby="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-text"{if $swl_dlg_open} open data-sw-autoshow{/if}>
  <form action="{$swl_manage_url|escape:'htmlall':'UTF-8'}" method="post">
    <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
    <input type="hidden" name="op" value="delete">
    <input type="hidden" name="id_wishlist" value="{$swl_dialog_target.id|intval}">

    <h2 class="sw-dialog__title" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-title">
      {l s='Delete "%list%"?' sprintf=['%list%' => $swl_dialog_target.name] d='Modules.Summitwishlist.Shop'}
    </h2>
    <p class="sw-dialog__text" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-text">
      {l s='Its %count% saved products will no longer be in this wishlist. Your other wishlists and the products in the store stay as they are.' sprintf=['%count%' => $swl_dialog_target.count] d='Modules.Summitwishlist.Shop'}
      {if $swl_dialog_target.is_default && $swl_lists|count > 1}
        {l s='Your oldest remaining wishlist becomes the default.' d='Modules.Summitwishlist.Shop'}
      {/if}
    </p>
    <div class="sw-dialog__actions">
      <a class="btn btn-outline-primary sw-action" href="{$swl_self_url|escape:'htmlall':'UTF-8'}" data-sw-dialog-close autofocus>
        {l s='Cancel' d='Shop.Theme.Actions'}
      </a>
      <button type="submit" class="btn btn-danger sw-action">
        {l s='Delete wishlist' d='Modules.Summitwishlist.Shop'}
      </button>
    </div>
  </form>
</dialog>
