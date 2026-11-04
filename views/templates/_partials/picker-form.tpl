{**
 * "Save to wishlists": membership editor for one exact product/combination.
 * Rendered on the no-JS picker page and loaded into the module dialog by wishlist.js.
 * Checkboxes reflect current membership; `baseline[ID]` records it so the server applies only
 * the edits made here (another tab's changes to untouched lists survive). Nothing is written
 * until "Save changes". Creating a list here creates an empty list; saving still adds.
 *}
<div class="sw-picker" data-sw-picker>
  {if !$swl_valid}
    <h2 class="sw-dialog__title" id="sw-picker-title">{l s='Save to wishlists' d='Modules.Summitwishlist.Shop'}</h2>
    <div class="alert alert-warning" role="status">{l s='This product can\'t be added to your wishlist.' d='Modules.Summitwishlist.Shop'}</div>
    <div class="sw-dialog__actions">
      <a class="btn btn-outline-primary sw-action" href="{$swl_cancel_url|escape:'htmlall':'UTF-8'}" data-sw-dialog-close>{l s='Close' d='Shop.Theme.Global'}</a>
    </div>
  {else}
    <form class="sw-picker__form" action="{$swl_item_url|escape:'htmlall':'UTF-8'}" method="post" data-sw-picker-form>
      <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
      <input type="hidden" name="op" value="save_memberships">
      <input type="hidden" name="id_product" value="{$swl_product.id|intval}">
      <input type="hidden" name="id_product_attribute" value="{$swl_product.id_product_attribute|intval}">

      <h2 class="sw-dialog__title" id="sw-picker-title">{l s='Save to wishlists' d='Modules.Summitwishlist.Shop'}</h2>
      <p class="sw-picker__product">
        <strong>{$swl_product.name|escape:'htmlall':'UTF-8'}</strong>
        {if $swl_product.combination}<span class="sw-item__variant">{$swl_product.combination|escape:'htmlall':'UTF-8'}</span>{/if}
      </p>
      <p class="sw-dialog__text" id="sw-picker-help">{l s='Check lists to save this item; uncheck lists to remove it.' d='Modules.Summitwishlist.Shop'}</p>

      <fieldset class="sw-choices" aria-describedby="sw-picker-help">
        <legend class="visually-hidden">{l s='Your wishlists' d='Modules.Summitwishlist.Shop'}</legend>
        {foreach from=$swl_picker_lists item=list}
          <input type="hidden" name="baseline[{$list.id|intval}]" value="{if $list.in_list}1{else}0{/if}">
          <div class="form-check sw-choice">
            <input class="form-check-input" type="checkbox" name="lists[]" value="{$list.id|intval}" id="sw-pick-{$list.id|intval}"
                   data-sw-list-name="{$list.name|escape:'htmlall':'UTF-8'}" data-sw-was="{if $list.in_list}1{else}0{/if}"{if $list.in_list} checked{/if}>
            <label class="form-check-label" for="sw-pick-{$list.id|intval}">
              <span class="sw-choice__name">{$list.name|escape:'htmlall':'UTF-8'}</span>
              {if $list.is_default}<span class="badge text-bg-primary sw-badge-default">{l s='Default' d='Modules.Summitwishlist.Shop'}</span>{/if}
              <span class="sw-choice__state">
                {if $list.in_list}{l s='Saved' d='Modules.Summitwishlist.Shop'}{else}{l s='Not saved' d='Modules.Summitwishlist.Shop'}{/if}
              </span>
            </label>
          </div>
        {/foreach}
      </fieldset>

      {* Change summary, kept current by wishlist.js from the checkboxes; empty without JS. *}
      <div class="sw-picker__summary" data-sw-picker-summary aria-live="polite"
           data-sw-label-add="{l s='Adds to: %lists%' d='Modules.Summitwishlist.Shop'}"
           data-sw-label-remove="{l s='Removes from: %lists%' d='Modules.Summitwishlist.Shop'}"
           data-sw-label-keep="{l s='Stays in: %lists%' d='Modules.Summitwishlist.Shop'}"
           data-sw-label-none="{l s='No changes yet.' d='Modules.Summitwishlist.Shop'}"></div>
      <div class="sw-picker__feedback" data-sw-picker-feedback role="alert"></div>

      <div class="sw-dialog__actions">
        <a class="btn btn-outline-primary sw-action" href="{$swl_cancel_url|escape:'htmlall':'UTF-8'}" data-sw-dialog-close>{l s='Cancel' d='Shop.Theme.Actions'}</a>
        <button type="submit" class="btn btn-primary sw-action">{l s='Save changes' d='Modules.Summitwishlist.Shop'}</button>
      </div>
    </form>

    {* Separate form (never nested): creates an empty list, then the picker shows it unchecked. *}
    <form class="sw-picker__create" action="{$swl_manage_url|escape:'htmlall':'UTF-8'}" method="post" data-sw-picker-create novalidate>
      <input type="hidden" name="swl_token" value="{$swl_token|escape:'htmlall':'UTF-8'}">
      <input type="hidden" name="op" value="create">
      <input type="hidden" name="return" value="{$swl_picker_self_url|escape:'htmlall':'UTF-8'}">
      <label class="form-label" for="sw-picker-new">{l s='Create a wishlist' d='Modules.Summitwishlist.Shop'}</label>
      <div class="sw-picker__create-row">
        <input class="form-control{if $swl_create_error} is-invalid{/if}" type="text" name="name" id="sw-picker-new"
               value="{$swl_create_value|escape:'htmlall':'UTF-8'}" maxlength="{$swl_name_max|intval}" autocomplete="off"
               placeholder="{l s='Wishlist name' d='Modules.Summitwishlist.Shop'}"
               {if $swl_create_error}aria-invalid="true" aria-describedby="sw-picker-new-error"{/if}>
        <button type="submit" class="btn btn-outline-primary sw-action">{l s='Create' d='Modules.Summitwishlist.Shop'}</button>
      </div>
      <div class="invalid-feedback{if $swl_create_error} d-block{/if}" id="sw-picker-new-error" data-sw-picker-create-error>{$swl_create_error|escape:'htmlall':'UTF-8'}</div>
    </form>
  {/if}
</div>
