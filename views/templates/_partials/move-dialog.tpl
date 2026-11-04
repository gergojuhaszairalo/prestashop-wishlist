{**
 * Move one saved entry to another list: one destination, so radios. The source list is
 * excluded. A destination that already holds the variant keeps its single entry; only the
 * source entry is removed, after the server confirms.
 *
 * @param item a presented row ({id, name, combination, ...})
 *}
{$swl_dlg_id = "sw-move-dialog-`$item.id`"}
{$swl_dlg_open = ($swl_move_item == $item.id)}
{$swl_in = $swl_item_lists[$item.id]|default:[]}
<dialog class="sw-dialog{if $swl_dlg_open} sw-dialog--inline{/if}" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}"
        aria-labelledby="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-title"{if $swl_dlg_open} open data-sw-autoshow{/if}>
  <form action="{$swl_item_url|escape:'htmlall':'UTF-8'}" method="post">
    <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
    <input type="hidden" name="op" value="move">
    <input type="hidden" name="id_wishlist" value="{$swl_list.id|intval}">
    <input type="hidden" name="id_item" value="{$item.id|intval}">

    <h2 class="sw-dialog__title" id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-title">{l s='Move to another wishlist' d='Modules.Summitwishlist.Shop'}</h2>
    <p class="sw-dialog__text">
      <strong>{$item.name|escape:'htmlall':'UTF-8'}</strong>{if $item.combination} <span class="sw-item__variant">({$item.combination|escape:'htmlall':'UTF-8'})</span>{/if}<br>
      {l s='From: %list%' sprintf=['%list%' => $swl_list.name] d='Modules.Summitwishlist.Shop'}
    </p>
    <fieldset class="sw-choices">
      <legend class="form-label">{l s='Move to' d='Modules.Summitwishlist.Shop'}</legend>
      {$swl_first = true}
      {foreach from=$swl_lists item=dest}
        {if $dest.id != $swl_list.id}
          <div class="form-check sw-choice">
            <input class="form-check-input" type="radio" name="target_wishlist" value="{$dest.id|intval}"
                   id="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-{$dest.id|intval}"{if $swl_first} checked{/if} required>
            <label class="form-check-label" for="{$swl_dlg_id|escape:'htmlall':'UTF-8'}-{$dest.id|intval}">
              {$dest.name|escape:'htmlall':'UTF-8'}
              {if isset($swl_in[$dest.id])}
                <span class="badge text-bg-light sw-choice__state">{l s='Already saved there' d='Modules.Summitwishlist.Shop'}</span>
              {/if}
            </label>
          </div>
          {$swl_first = false}
        {/if}
      {/foreach}
    </fieldset>
    <div class="sw-dialog__actions">
      <a class="btn btn-outline-primary sw-action" href="{$swl_self_url|escape:'htmlall':'UTF-8'}" data-sw-dialog-close>
        {l s='Cancel' d='Shop.Theme.Actions'}
      </a>
      <button type="submit" class="btn btn-primary sw-action">{l s='Move' d='Modules.Summitwishlist.Shop'}</button>
    </div>
  </form>
</dialog>
