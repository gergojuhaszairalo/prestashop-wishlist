{$swl_available = ($item.status == 'ok')}
<li class="sw-item{if !$swl_available} sw-item--unavailable{/if}" data-sw-item="{$item.id|intval}">
  <div class="sw-item__select form-check">
    <input class="form-check-input" type="checkbox" name="ids[]" value="{$item.id|intval}" id="sw-select-{$item.id|intval}" data-sw-select{if !$swl_available} disabled{/if}>
    <label class="visually-hidden" for="sw-select-{$item.id|intval}">
      {l s='Select %product%' sprintf=['%product%' => $item.name] d='Modules.Summitwishlist.Shop'}
    </label>
  </div>

  <div class="sw-item__media">
    {if $item.image}
      <img class="sw-item__image" src="{$item.image|escape:'htmlall':'UTF-8'}" alt="{$item.name|escape:'htmlall':'UTF-8'}" loading="lazy" width="112" height="112">
    {else}
      <img class="sw-item__image" src="{$urls.no_picture_image.bySize.home_default.url|escape:'htmlall':'UTF-8'}" alt="" loading="lazy" width="112" height="112">
    {/if}
  </div>

  <div class="sw-item__details">
    {if $item.url}
      <a class="sw-item__name" href="{$item.url|escape:'htmlall':'UTF-8'}">{$item.name|escape:'htmlall':'UTF-8'}</a>
    {else}
      <span class="sw-item__name">{$item.name|escape:'htmlall':'UTF-8'}</span>
    {/if}
    {if $item.combination}
      <p class="sw-item__variant">{$item.combination|escape:'htmlall':'UTF-8'}</p>
    {/if}
    {if $item.price}
      <p class="sw-item__price">{$item.price|escape:'htmlall':'UTF-8'}</p>
    {/if}
    <p class="sw-item__availability" data-sw-status="{$item.status|escape:'htmlall':'UTF-8'}">
      {if $swl_available}
        <span class="text-success">
          <i class="material-icons" aria-hidden="true">&#xE5CA;</i>
          {if $item.availability_label}{$item.availability_label|escape:'htmlall':'UTF-8'}{else}{l s='In stock' d='Modules.Summitwishlist.Shop'}{/if}
        </span>
      {elseif $item.status == 'out_of_stock'}
        <span class="badge text-bg-warning">{l s='Out of stock' d='Modules.Summitwishlist.Shop'}</span>
      {elseif $item.status == 'needs_options'}
        <span class="badge text-bg-warning">{l s='Options required' d='Modules.Summitwishlist.Shop'}</span>
      {elseif $item.status == 'no_access'}
        <span class="badge text-bg-danger">{l s='Not available for your account' d='Modules.Summitwishlist.Shop'}</span>
      {else}
        <span class="badge text-bg-danger">{l s='No longer available' d='Modules.Summitwishlist.Shop'}</span>
      {/if}
    </p>
  </div>

  <div class="sw-item__actions">
    {if $swl_available}
      <button type="submit" name="single" value="{$item.id|intval}" class="btn btn-primary sw-action sw-add">
        <i class="material-icons" aria-hidden="true">&#xE854;</i>
        {l s='Add to cart' d='Shop.Theme.Actions'}
      </button>
    {elseif $item.status == 'needs_options' && $item.url}
      <a class="btn btn-outline-primary sw-action sw-add" href="{$item.url|escape:'htmlall':'UTF-8'}">
        {l s='Choose options' d='Modules.Summitwishlist.Shop'}
      </a>
    {else}
      <button type="button" class="btn btn-primary sw-action sw-add" disabled>
        <i class="material-icons" aria-hidden="true">&#xE854;</i>
        {l s='Add to cart' d='Shop.Theme.Actions'}
      </button>
    {/if}
    {capture name='swl_remove_label'}{l s='Remove %product% from wishlist' sprintf=['%product%' => $item.name] d='Modules.Summitwishlist.Shop'}{/capture}
    <button type="submit" formaction="{$swl_item_url|escape:'htmlall':'UTF-8'}" name="id_item" value="{$item.id|intval}"
            class="btn btn-outline-tertiary sw-icon-action sw-remove"
            aria-label="{$smarty.capture.swl_remove_label|trim|escape:'htmlall':'UTF-8'}"
            title="{$smarty.capture.swl_remove_label|trim|escape:'htmlall':'UTF-8'}">
      <i class="material-icons" aria-hidden="true">&#xE872;</i>
    </button>
  </div>
</li>
