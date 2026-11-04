{**
 * Create / rename a wishlist. Posts to the wishlist page; a failure re-renders this dialog open
 * with the inline error and the typed value. Without JS the dialog opens via ?dialog=..., so it
 * renders open as an inline panel; wishlist.js upgrades open dialogs to modals.
 *
 * @param swl_dialog_mode   'create' | 'rename'
 * @param swl_dialog_target the list being renamed (null for create)
 *}
{if $swl_dialog_mode == 'create'}
  {$swl_dlg_id = 'sw-create-dialog'}
  {$swl_dlg_open = ($swl_open_dialog == 'create')}
  {$swl_dlg_value = ($swl_dlg_open) ? $swl_form_value : ''}
{else}
  {$swl_dlg_id = "sw-rename-dialog-`$swl_dialog_target.id`"}
  {$swl_dlg_open = ($swl_open_dialog == 'rename' && ($swl_dialog_list == $swl_dialog_target.id || ($swl_dialog_list == 0 && $swl_list && $swl_list.id == $swl_dialog_target.id)))}
  {$swl_dlg_value = ($swl_dlg_open && $swl_form_error) ? $swl_form_value : $swl_dialog_target.name}
{/if}
{$swl_dlg_error = ($swl_dlg_open && $swl_form_error) ? $swl_form_error : ''}

<dialog class="sw-dialog{if $swl_dlg_open} sw-dialog--inline{/if}" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}"
        aria-labelledby="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-title"{if $swl_dlg_open} open data-sw-autoshow{/if}>
  <form action="{$swl_self_url|escape:'htmlall':'UTF-8'}" method="post" novalidate>
    <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
    <input type="hidden" name="op" value="{$swl_dialog_mode|escape:'htmlall':'UTF-8'}">
    {if $swl_dialog_target}
      <input type="hidden" name="id_wishlist" value="{$swl_dialog_target.id|intval}">
    {/if}

    <h2 class="sw-dialog__title" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-title">
      {if $swl_dialog_mode == 'create'}
        {l s='Create wishlist' d='Modules.Summitwishlist.Shop'}
      {else}
        {l s='Rename wishlist' d='Modules.Summitwishlist.Shop'}
      {/if}
    </h2>

    <div class="sw-dialog__field">
      <label class="form-label" for="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-name">{l s='Wishlist name' d='Modules.Summitwishlist.Shop'}</label>
      <input class="form-control{if $swl_dlg_error} is-invalid{/if}" type="text" name="name"
             id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-name"
             value="{$swl_dlg_value|escape:'htmlall':'UTF-8'}" maxlength="{$swl_name_max|intval}" required autocomplete="off"
             {if $swl_dlg_error}aria-invalid="true" aria-describedby="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-error"{/if}
             {if $swl_dlg_open}autofocus{/if}>
      {if $swl_dlg_error}
        <div class="invalid-feedback" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-error">{$swl_dlg_error|escape:'htmlall':'UTF-8'}</div>
      {/if}
    </div>

    <div class="sw-dialog__actions">
      <a class="btn btn-outline-primary sw-action" href="{$swl_self_url|escape:'htmlall':'UTF-8'}" data-sw-dialog-close>
        {l s='Cancel' d='Shop.Theme.Actions'}
      </a>
      <button type="submit" class="btn btn-primary sw-action">
        {if $swl_dialog_mode == 'create'}
          {l s='Create wishlist' d='Modules.Summitwishlist.Shop'}
        {else}
          {l s='Save name' d='Modules.Summitwishlist.Shop'}
        {/if}
      </button>
    </div>
  </form>
</dialog>
